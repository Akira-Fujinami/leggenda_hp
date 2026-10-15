<?php

namespace App\Services\BranchSurveyItems;

use App\Models\WebsiteAnalysis;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CT: 自社(WebsiteAnalysis)の階層図の、TOPページと各枝(第3列、上限まで)について、調査14項目のどれが
 * 書かれているかをAIで判定して保存する。流れ: 階層図の木を作る(保存済みのデータだけ) → 枝ごとに入力の本文を作る
 * → AIで判定 → サーバー側で根拠を入力の本文と突き合わせる → ファイルに置く。
 *
 * - 結果のファイルが既にあれば、AIを呼び直さない(force=trueを除く)。同じ比較でPPTXを何度ダウンロード
 *   しても、AIは呼ばれない(PPTXを作る側はファイルを読むだけ)。
 * - 1つの枝の呼び出しが失敗しても、他の枝は続ける。失敗した枝は status=failed(「判定できず」)。
 *   認証・レート制限・未設定の失敗では、残りの枝は呼ばずに failed にする(呼び出し回数を増やさない)。
 * - 巡回のやり直し・追加の取得はしない。
 * - ログには件数だけを出す(本文・根拠の文は出さない)。
 */
class BranchSurveyItemsService
{
    /** これらの失敗は、続けても同じ失敗になるため残りの呼び出しを止める。 */
    private const ABORT_CODES = ['AI_AUTH_FAILED', 'AI_RATE_LIMITED', 'OPENAI_NOT_CONFIGURED', 'PROVIDER_NOT_OPENAI'];

    public function __construct(
        private readonly AdminComparisonSiteHierarchyBuilder $hierarchy,
        private readonly BranchSurveyItemsInputBuilder $inputBuilder,
        private readonly BranchSurveyItemsVerifier $verifier,
        private readonly BranchSurveyItemsStore $store,
    ) {}

    /**
     * @return array<string, mixed> 置いた(または既にあった)結果
     */
    public function generate(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
    {
        $analysisId = (int) $websiteAnalysis->analysis_id;
        $websiteAnalysisId = (int) $websiteAnalysis->id;

        if (! $force) {
            $existing = $this->store->read($analysisId, $websiteAnalysisId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $tree = $this->hierarchy->buildTree($websiteAnalysis);
        $inputs = $this->hierarchy->surveyInputs($websiteAnalysis, $tree);

        $provider = null;
        $setupError = null;
        try {
            $provider = app(BranchSurveyItemsProviderFactory::class)->make();
        } catch (BranchSurveyItemsException $e) {
            $setupError = $e->errorCode;
        }

        $state = ['aborted' => $setupError, 'calls' => 0, 'in' => 0, 'out' => 0];

        $top = null;
        if ($inputs['top'] !== null) {
            $top = ['label' => $inputs['top']['label']] + $this->judge([$inputs['top']['html']], $provider, $state);
        }

        $branches = [];
        foreach ($inputs['branches'] as $branch) {
            $branches[] = ['key' => $branch['key'], 'name' => $branch['name']] + $this->judge($branch['htmls'], $provider, $state);
        }

        $result = [
            'status' => 'done',
            'top' => $top,
            'branches' => $branches,
            'prompt_version' => $provider?->promptVersion() ?? (string) config('branch_survey_items.prompt_version'),
            'provider' => $provider?->name(),
            'model' => $provider?->model(),
            'ai_calls' => $state['calls'],
            'usage_input_tokens' => $state['in'],
            'usage_output_tokens' => $state['out'],
            'generated_at' => now()->toIso8601String(),
        ];

        $this->store->write($analysisId, $websiteAnalysisId, $result);

        $targets = array_filter([$top, ...$branches]);
        // 件数だけ。本文・根拠の文・ページ名は出さない。
        Log::info('Branch survey items saved', [
            'analysis_id' => $analysisId,
            'website_analysis_id' => $websiteAnalysisId,
            'target_count' => count($targets),
            'failed_count' => count(array_filter($targets, fn (array $t) => $t['status'] === 'failed')),
            'found_count' => array_sum(array_map(fn (array $t) => count($t['items']), $targets)),
            'discarded_count' => array_sum(array_map(fn (array $t) => (int) $t['discarded'], $targets)),
            'ai_calls' => $state['calls'],
            'usage_input_tokens' => $state['in'],
            'usage_output_tokens' => $state['out'],
        ]);

        return $result;
    }

    /**
     * 1つの対象(TOPまたは1つの枝)を判定する。
     *
     * @param  list<string>  $htmls
     * @param  array{aborted: ?string, calls: int, in: int, out: int}  $state
     * @return array{status: string, items: list<array{key: string, evidence: string}>, discarded: int, reason: ?string}
     */
    private function judge(array $htmls, ?BranchSurveyItemsProvider $provider, array &$state): array
    {
        $failed = fn (string $reason) => ['status' => 'failed', 'items' => [], 'discarded' => 0, 'reason' => $reason];

        $text = $this->inputBuilder->build($htmls);
        if ($text === '') {
            return $failed('no_text');
        }
        if ($provider === null || $state['aborted'] !== null) {
            return $failed((string) ($state['aborted'] ?? 'provider_unavailable'));
        }

        try {
            $state['calls']++;
            $outcome = $provider->analyze($text);
        } catch (BranchSurveyItemsException $e) {
            if (in_array($e->errorCode, self::ABORT_CODES, true)) {
                $state['aborted'] = $e->errorCode;
            }

            return $failed($e->errorCode);
        }

        $state['in'] += (int) $outcome['usage_input_tokens'];
        $state['out'] += (int) $outcome['usage_output_tokens'];

        $verified = $this->verifier->verify($outcome['decoded'], $text);

        return ['status' => 'ok', 'items' => $verified['items'], 'discarded' => $verified['discarded'], 'reason' => null];
    }

    /**
     * ジョブ全体の失敗(例外・タイムアウト)を、結果のファイルとして記録する。枝は空(「判定できず」扱い)。
     * 比較の完了(BranchSurveyItemsGate)を待たせないための記録でもある。
     */
    public function recordFailure(WebsiteAnalysis $websiteAnalysis, string $reason): void
    {
        $this->store->write((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id, [
            'status' => 'done',
            'top' => null,
            'branches' => [],
            'job_failed' => $reason,
            'prompt_version' => (string) config('branch_survey_items.prompt_version'),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
