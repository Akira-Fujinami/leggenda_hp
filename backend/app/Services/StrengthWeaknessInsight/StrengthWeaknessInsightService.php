<?php

namespace App\Services\StrengthWeaknessInsight;

use App\Models\Analysis;
use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Support\Facades\Log;

/**
 * 依頼DB: 比較(Analysis)の強み・弱みの下書きを作って保存する。流れ: 材料(AIを使わない) → Claudeを1回呼ぶ →
 * 書かれた文を、サーバー側で材料と突き合わせる(StrengthWeaknessInsightVerifier。事実の部分だけ) → ファイルに置く。
 *
 * - 結果のファイルが既にあれば、AIを呼び直さない(force=trueを除く)。PPTXを作る側はファイルを読むだけ。
 * - 材料が作れない(自社の判定が成立していない・競合がいない・読み取れた記述が少ない会社がある・競合の判定が成立していない)・
 *   APIキー/モデル名が未設定・AIの呼び出しが失敗(タイムアウト等)のときは、AIを呼ばない/書けない(skippedとして記録する)。
 *   例外は投げない ―― その側は依頼CT-1の文のまま。
 * - 確認で落ちた側は null(依頼CT-1の文に戻る)。落ちた理由の符号を件数としてログに出す。文の中身はログに出さない。
 * - AI呼び出しの再試行は、HTTPの接続失敗・タイムアウトだけ(プロバイダ側の設定)。ここでは呼び直さない。
 */
class StrengthWeaknessInsightService
{
    public function __construct(
        private readonly StrengthWeaknessInsightMaterialBuilder $materials,
        private readonly StrengthWeaknessInsightVerifier $verifier,
        private readonly StrengthWeaknessInsightStore $store,
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
     * 材料から、Claudeを1回呼んで強み・弱みを書かせ、サーバー側で確認した結果を返す(保存はしない。測定コマンドからも使う)。
     *
     * @param  array<string, mixed>  $material  StrengthWeaknessInsightMaterialBuilder::build()の戻り値
     * @param  ?callable  $beforeCall  AIを呼ぶ直前に呼ぶ。falseを返したら呼ばない(測定コマンドの回数の上限)
     * @return array<string, mixed>
     */
    public function compose(array $material, ?callable $beforeCall = null): array
    {
        $base = [
            'status' => 'done',
            'skipped' => null,
            'strength' => null,
            'weakness' => null,
            'rejected' => ['strength' => [], 'weakness' => []],
            'prompt_version' => (string) config('strength_weakness_insight.prompt_version'),
            'provider' => null,
            'model' => null,
            'ai_calls' => 0,
            'usage_input_tokens' => 0,
            'usage_output_tokens' => 0,
            'evidence_trimmed' => (int) ($material['evidence_trimmed'] ?? 0),
            'generated_at' => now()->toIso8601String(),
        ];

        if (($material['status'] ?? null) !== 'ok') {
            return ['skipped' => (string) ($material['reason'] ?? 'unknown')] + $base;
        }

        try {
            $provider = app(StrengthWeaknessInsightProviderFactory::class)->make();
        } catch (ComparisonCommentaryException $e) {
            return ['skipped' => $e->errorCode] + $base;
        }

        $base['provider'] = $provider->name();
        $base['model'] = $provider->model();
        $base['prompt_version'] = $provider->promptVersion();

        if ($beforeCall !== null && $beforeCall() === false) {
            return ['skipped' => 'MAX_AI_CALLS_REACHED'] + $base;
        }

        try {
            $base['ai_calls'] = 1;
            $outcome = $provider->write($material);
        } catch (ComparisonCommentaryException $e) {
            return ['skipped' => $e->errorCode] + $base;
        }

        $base['usage_input_tokens'] = (int) $outcome['usage_input_tokens'];
        $base['usage_output_tokens'] = (int) $outcome['usage_output_tokens'];

        $base['candidates'] = [];
        foreach (['strength', 'weakness'] as $side) {
            $candidate = $outcome['decoded'][$side] ?? null;
            $base['candidates'][$side] = $candidate;
            $checked = $this->verifier->verify($side, $candidate, $material);
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
            'rejected' => ['strength' => [], 'weakness' => []],
            'job_failed' => $reason,
            'prompt_version' => (string) config('strength_weakness_insight.prompt_version'),
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

        // 件数と符号だけ。文・根拠の文・会社名・APIキーは出さない。
        Log::info('Strength weakness insight saved', [
            'analysis_id' => $analysisId,
            'skipped' => $result['skipped'],
            'ai_calls' => $result['ai_calls'],
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
