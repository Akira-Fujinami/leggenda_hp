<?php

namespace App\Services\TopMessagePanel;

use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CZ: 比較(Analysis)の各社の「トップメッセージと制度のパネル」を作って保存する。1社ずつ、材料を作る(AIを使わない)
 * → 材料があるときだけAIを1回呼ぶ(選ぶだけ) → 選んだものをサーバー側で材料と突き合わせる(TopMessagePanelVerifier)
 * → 会社ごとの結果を1つのファイルに置く。
 *
 * - 結果のファイルが既にあれば、AIを呼び直さない(force=trueを除く)。同じ比較でPPTXを何度ダウンロードしても、
 *   AIは呼ばれない(PPTXを作る側はファイルを読むだけ)。
 * - 材料が無い会社(メッセージのページが無い・文が無い・制度の候補が無い)は、AIを呼ばない(fallback。依頼CSの素材ページのまま)。
 * - 確認で、核の一文が落ちた・キーワードが少ない会社はfallback。落ちた理由は符号ごとの件数として結果に置き、ログにも件数だけ出す。
 *   AIの出力・文の中身はログに出さない。
 * - AI呼び出しの再試行はここでは行わない(呼び出し回数を増やさないため。HTTPの再試行はプロバイダ側の既存設定)。
 */
class TopMessagePanelService
{
    public function __construct(
        private readonly TopMessagePanelMaterialBuilder $materials,
        private readonly TopMessagePanelVerifier $verifier,
        private readonly TopMessagePanelStore $store,
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

        $analysis->loadMissing('websiteAnalyses.website');

        $companies = [];
        foreach ($analysis->websiteAnalyses as $websiteAnalysis) {
            $result = $this->composeCompany($websiteAnalysis, (string) ($websiteAnalysis->website?->name ?? ''));
            unset($result['candidates']);
            $companies[(string) $websiteAnalysis->id] = $result;
        }

        $first = $this->providerOrNull();
        $final = [
            'status' => 'done',
            'prompt_version' => (string) config('top_message_panel.prompt_version'),
            'provider' => $first?->name(),
            'model' => $first?->model(),
            'generated_at' => now()->toIso8601String(),
            'companies' => $companies,
        ];

        return $this->finish($analysisId, $final);
    }

    /**
     * 1社ぶん: 材料 → AI(1回) → 確認。保存はしない(測定コマンドからも使う)。
     *
     * @param  ?callable  $beforeAiCall  AIを呼ぶ直前に呼ぶ。falseを返すとAIを呼ばず fallback(reason=ai_call_limit)にする(測定の回数の上限用)
     * @return array<string, mixed>
     */
    public function composeCompany(WebsiteAnalysis $websiteAnalysis, string $companyName, ?callable $beforeAiCall = null): array
    {
        return $this->compose($this->materials->build($websiteAnalysis), $companyName, $beforeAiCall);
    }

    /**
     * @param  array<string, mixed>  $material  TopMessagePanelMaterialBuilder::build()の戻り値
     * @return array<string, mixed>
     */
    public function compose(array $material, string $companyName, ?callable $beforeAiCall = null): array
    {
        $base = [
            'status' => 'fallback',
            'reason' => null,
            'panel' => null,
            'speaker' => $material['speaker'] ?? null,
            'message_title' => $material['message_title'] ?? null,
            'rejected' => [],
            'dropped' => $material['dropped'] ?? [],
            'sentence_count' => count($material['sentences'] ?? []),
            'program_count' => count($material['programs'] ?? []),
            'ai_calls' => 0,
            'usage_input_tokens' => 0,
            'usage_output_tokens' => 0,
        ];

        if ($material['status'] !== 'ok') {
            return ['reason' => (string) $material['reason']] + $base;
        }

        try {
            $provider = app(TopMessagePanelProviderFactory::class)->make();
        } catch (ComparisonCommentaryException $e) {
            return ['reason' => $e->errorCode] + $base;
        }

        if ($beforeAiCall !== null && $beforeAiCall() === false) {
            return ['reason' => 'ai_call_limit'] + $base;
        }

        try {
            $base['ai_calls'] = 1;
            $outcome = $provider->select([
                'company_name' => $companyName,
                'sentences' => $material['sentences'],
                'programs' => array_map(fn (array $p) => ['id' => $p['id'], 'name' => $p['name'], 'excerpt' => $p['excerpt']], $material['programs']),
            ]);
        } catch (ComparisonCommentaryException $e) {
            return ['reason' => $e->errorCode] + $base;
        }

        $base['usage_input_tokens'] = (int) $outcome['usage_input_tokens'];
        $base['usage_output_tokens'] = (int) $outcome['usage_output_tokens'];
        // AIの出力そのまま(確認前)は、測定のために返すだけ。ファイルにもログにも置かない。
        $base['candidates'] = $outcome['decoded'];

        $checked = $this->verifier->verify($outcome['decoded'], $material);
        $base['rejected'] = $checked['rejected'];
        if ($checked['panel'] === null) {
            $base['reason'] = array_key_first($checked['rejected']) ?? 'panel_rejected';

            return $base;
        }

        $base['status'] = 'panel';
        $base['panel'] = $checked['panel'];

        return $base;
    }

    /**
     * ジョブ全体の失敗(例外・タイムアウト)を、結果のファイルとして記録する。会社の結果は無いので、素材ページのままになる。
     * 比較の完了を待たせないための記録でもある。
     */
    public function recordFailure(Analysis $analysis, string $reason): void
    {
        $this->store->write((int) $analysis->id, [
            'status' => 'done',
            'companies' => [],
            'job_failed' => $reason,
            'prompt_version' => (string) config('top_message_panel.prompt_version'),
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

        // 件数と符号だけ。文・語句・制度名・会社名は出さない。
        $summary = [];
        foreach ($result['companies'] as $company) {
            $summary[] = [
                'status' => $company['status'],
                'reason' => $company['reason'],
                'rejected' => $company['rejected'],
                'ai_calls' => $company['ai_calls'],
            ];
        }
        Log::info('Top message panel saved', [
            'analysis_id' => $analysisId,
            'companies' => $summary,
            'ai_calls' => array_sum(array_column($result['companies'], 'ai_calls')),
            'usage_input_tokens' => array_sum(array_column($result['companies'], 'usage_input_tokens')),
            'usage_output_tokens' => array_sum(array_column($result['companies'], 'usage_output_tokens')),
        ]);

        return $result;
    }

    private function providerOrNull(): ?TopMessagePanelProvider
    {
        try {
            return app(TopMessagePanelProviderFactory::class)->make();
        } catch (ComparisonCommentaryException) {
            return null;
        }
    }
}
