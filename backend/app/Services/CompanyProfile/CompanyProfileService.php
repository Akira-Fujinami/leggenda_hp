<?php

namespace App\Services\CompanyProfile;

use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Support\Facades\Log;

/**
 * 依頼DK: 比較の各社の「私たちが理解している、{社名}という会社」を作って保存する。1社ずつ、材料(AIなし) → AI(1回・Web検索つき)
 * → サーバーの確認(CompanyProfileVerifier) → 全社ぶんを1つのファイルに置く。
 * 結果のファイルが既にあれば、AIを呼び直さない。AIの出力・文・会社名はログに出さない(件数と符号だけ)。
 */
class CompanyProfileService
{
    public function __construct(
        private readonly CompanyProfileMaterialBuilder $materials,
        private readonly CompanyProfileVerifier $verifier,
        private readonly CompanyProfileStore $store,
    ) {}

    /**
     * @return array<string, mixed>
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
            $companies[(string) $websiteAnalysis->id] = $this->composeCompany($websiteAnalysis, (string) ($websiteAnalysis->website?->name ?? ''));
        }

        $result = [
            'status' => 'done',
            'prompt_version' => (string) config('company_profile.prompt_version'),
            'model' => $this->provider()?->model(),
            'generated_at' => now()->toIso8601String(),
            'companies' => $companies,
        ];
        $this->store->write($analysisId, $result);

        $summary = [];
        foreach ($companies as $company) {
            $summary[] = ['status' => $company['status'], 'reason' => $company['reason'], 'rejected' => $company['rejected'], 'pillars' => $company['pillar_count']];
        }
        Log::info('Company profile saved', [
            'analysis_id' => $analysisId,
            'companies' => $summary,
            'ai_calls' => array_sum(array_column($companies, 'ai_calls')),
            'web_searches' => array_sum(array_column($companies, 'web_searches')),
            'usage_input_tokens' => array_sum(array_column($companies, 'usage_input_tokens')),
            'usage_output_tokens' => array_sum(array_column($companies, 'usage_output_tokens')),
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function composeCompany(WebsiteAnalysis $websiteAnalysis, string $companyName): array
    {
        $base = ['status' => 'skipped', 'reason' => null, 'profile' => null, 'rejected' => [], 'pillar_count' => 0, 'ai_calls' => 0, 'web_searches' => 0, 'usage_input_tokens' => 0, 'usage_output_tokens' => 0];

        $provider = $this->provider();
        if ($provider === null) {
            return ['reason' => 'MODEL_NOT_CONFIGURED'] + $base;
        }
        if (trim($companyName) === '') {
            return ['reason' => 'no_company_name'] + $base;
        }

        $material = $this->materials->build($websiteAnalysis, $companyName);

        try {
            $base['ai_calls'] = 1;
            $outcome = $provider->draft($material);
        } catch (ComparisonCommentaryException $e) {
            return ['reason' => $e->errorCode] + $base;
        }
        $base['web_searches'] = (int) $outcome['web_searches'];
        $base['usage_input_tokens'] = (int) ($outcome['usage_input_tokens'] ?? 0);
        $base['usage_output_tokens'] = (int) ($outcome['usage_output_tokens'] ?? 0);

        $checked = $this->verifier->verify($outcome['decoded'], $material);
        $base['rejected'] = $checked['rejected'];
        if ($checked['profile'] === null) {
            return ['reason' => 'nothing_verified'] + $base;
        }

        return ['status' => 'profile', 'profile' => $checked['profile'], 'pillar_count' => count($checked['profile']['pillars'])] + $base;
    }

    public function recordFailure(Analysis $analysis, string $reason): void
    {
        $this->store->write((int) $analysis->id, [
            'status' => 'done',
            'companies' => [],
            'job_failed' => $reason,
            'prompt_version' => (string) config('company_profile.prompt_version'),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function provider(): ?CompanyProfileProvider
    {
        $provider = app()->bound(CompanyProfileProvider::class) ? app(CompanyProfileProvider::class) : app(AnthropicCompanyProfileProvider::class);

        return $provider->model() !== null ? $provider : null;
    }
}
