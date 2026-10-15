<?php

namespace App\Services\StrengthWeaknessSentence;

use App\Models\Analysis;
use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CV・CW: 比較(Analysis)の強み・弱みの一文を作って保存する。流れ: 材料(機械が選んだ1項目・1社)を作る(AIを使わない)
 * → 取り上げる対象があるときだけAIを1回呼ぶ(強み・弱みの引用する一節をまとめて選ばせる) → 引用をサーバー側で材料と突き合わせる
 * (StrengthWeaknessSentenceVerifier) → 通った引用を、決まった形の文に入れる(StrengthWeaknessSentenceAssembler) → ファイルに置く。
 *
 * - 結果のファイルが既にあれば、AIを呼び直さない(force=trueを除く)。同じ比較でPPTXを何度ダウンロードしても、
 *   AIは呼ばれない(PPTXを作る側はファイルを読むだけ)。
 * - 対象が無い・自社の判定が成立していない・競合がいない・読み取れた記述が少ない会社がある・競合の判定が成立していない
 *   ときは、AIを呼ばない(skippedとして記録する)。対象が無い側は、AIに書かせず、依頼CT-1の文のまま。
 * - 確認で落ちた側は null(依頼CT-1の文に戻る)。落ちた理由の符号を件数としてログに出す。文の中身はログに出さない。
 * - AI呼び出しの再試行はここでは行わない(呼び出し回数を増やさないため。HTTPの再試行はプロバイダ側の既存設定)。
 */
class StrengthWeaknessSentenceService
{
    public function __construct(
        private readonly StrengthWeaknessSentenceMaterialBuilder $materials,
        private readonly StrengthWeaknessSentenceVerifier $verifier,
        private readonly StrengthWeaknessSentenceStore $store,
    ) {}

    /**
     * @return array<string, mixed> 置いた(または既にあった)結果
     */
    public function generate(Analysis $analysis, bool $force = false): array
    {
        $analysisId = (int) $analysis->id;

        if (! $force) {
            $existing = $this->store->read($analysisId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $result = $this->compose($this->materials->build($analysis));
        // AIの出力そのまま(確認前)は、測定のために返すだけ。ファイルにもログにも置かない。
        unset($result['candidates']);

        return $this->finish($analysisId, $result);
    }

    /**
     * 材料から、AIを呼んで(対象があるときだけ・1回)強み・弱みの一文を作り、サーバー側で確認した結果を返す
     * (保存はしない。測定コマンドからも使う)。
     *
     * @param  array<string, mixed>  $material  StrengthWeaknessSentenceMaterialBuilder::build()の戻り値
     * @return array<string, mixed>
     */
    public function compose(array $material): array
    {
        $base = [
            'status' => 'done',
            'skipped' => null,
            'strength' => null,
            'weakness' => null,
            'targets' => [
                'strength' => $material['strength']['key'] ?? null,
                'strength_basis' => $material['strength']['basis'] ?? 'none',
                'weakness' => isset($material['weakness']) ? ['item' => $material['weakness']['key'], 'company' => $material['weakness']['company_label']] : null,
            ],
            'rejected' => ['strength' => [], 'weakness' => []],
            'prompt_version' => (string) config('strength_weakness_sentence.prompt_version'),
            'provider' => null,
            'model' => null,
            'ai_calls' => 0,
            'usage_input_tokens' => 0,
            'usage_output_tokens' => 0,
            'generated_at' => now()->toIso8601String(),
        ];

        if ($material['status'] !== 'ok') {
            return ['skipped' => (string) $material['reason']] + $base;
        }

        try {
            $provider = app(StrengthWeaknessSentenceProviderFactory::class)->make();
        } catch (ComparisonCommentaryException $e) {
            return ['skipped' => $e->errorCode] + $base;
        }

        $base['provider'] = $provider->name();
        $base['model'] = $provider->model();
        $base['prompt_version'] = $provider->promptVersion();

        try {
            $base['ai_calls'] = 1;
            $outcome = $provider->write($this->materials->visible($material));
        } catch (ComparisonCommentaryException $e) {
            return ['skipped' => $e->errorCode] + $base;
        }

        $base['usage_input_tokens'] = (int) $outcome['usage_input_tokens'];
        $base['usage_output_tokens'] = (int) $outcome['usage_output_tokens'];

        $base['candidates'] = [];
        foreach (['strength', 'weakness'] as $side) {
            if ($material[$side] === null) {
                continue;   // 対象が無い側は、AIに選ばせていない。依頼CT-1の文のまま。
            }
            // AIが選ぶのは引用だけ(依頼CW)。文は、確認を通った引用から、サーバーが組み立てる。
            $quote = $outcome['decoded'][$side.'_quote'] ?? null;
            $base['candidates'][$side] = ['quote' => is_string($quote) ? $quote : null];
            $checked = $this->verifier->verify($side, is_string($quote) ? $quote : null, $material);
            $base[$side] = $checked['value'];
            $base['rejected'][$side] = $checked['reasons'];
        }

        return $base;
    }

    /**
     * ジョブ全体の失敗(例外・タイムアウト)を、結果のファイルとして記録する。両方nullなので依頼CT-1の文になる。
     * 比較の完了を待たせないための記録でもある。
     */
    public function recordFailure(Analysis $analysis, string $reason): void
    {
        $this->store->write((int) $analysis->id, [
            'status' => 'done',
            'skipped' => null,
            'strength' => null,
            'weakness' => null,
            'targets' => ['strength' => null, 'strength_basis' => 'none', 'weakness' => null],
            'rejected' => ['strength' => [], 'weakness' => []],
            'job_failed' => $reason,
            'prompt_version' => (string) config('strength_weakness_sentence.prompt_version'),
            'ai_calls' => 0,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finish(int $analysisId, array $result): array
    {
        $this->store->write($analysisId, $result);

        // 件数と符号だけ。文・根拠の文・会社名は出さない。
        Log::info('Strength weakness sentence saved', [
            'analysis_id' => $analysisId,
            'skipped' => $result['skipped'],
            'ai_calls' => $result['ai_calls'],
            // 依頼CV追補: 強みをどの選び方で選んだか(unique=自社だけの強み / half=半数ちょうど / survey=求職者調査の割合 / none=選べなかった)
            'strength_basis' => $result['targets']['strength_basis'] ?? 'none',
            'strength_used' => $result['strength'] !== null,
            'weakness_used' => $result['weakness'] !== null,
            'rejected_strength' => $result['rejected']['strength'],
            'rejected_weakness' => $result['rejected']['weakness'],
            'usage_input_tokens' => $result['usage_input_tokens'],
            'usage_output_tokens' => $result['usage_output_tokens'],
        ]);

        return $result;
    }
}
