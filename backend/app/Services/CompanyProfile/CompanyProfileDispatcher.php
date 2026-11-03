<?php

namespace App\Services\CompanyProfile;

use App\Enums\AnalysisKind;
use App\Jobs\GenerateCompanyProfileJob;
use App\Models\Analysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Support\Facades\Log;

/**
 * 依頼DK: 比較(AdminComparison)の全社の巡回が終わったあとに、会社の理解のページを作るジョブを1回だけ起動し、終わるまで比較の完了を待たせる
 * (依頼CZのパネルと同じ考え方)。config('company_profile.enabled')がfalse(既定)なら何もしない。
 * ジョブが失敗しても、失敗の記録が結果のファイルとして置かれるので待ちは解ける。全社が終わってから wait_max_minutes を過ぎたら待たない。
 */
class CompanyProfileDispatcher
{
    public function __construct(
        private readonly CompanyProfileStore $store,
        private readonly CompanyProfileService $service,
    ) {}

    public function holdFinalization(Analysis $analysis): bool
    {
        if (! (bool) config('company_profile.enabled', false) || $analysis->kind !== AnalysisKind::AdminComparison) {
            return false;
        }

        try {
            if ($this->store->exists((int) $analysis->id)) {
                return false;
            }
            $latest = $analysis->websiteAnalyses()->max('updated_at');
            if ($latest !== null && now()->parse($latest)->lt(now()->subMinutes((int) config('company_profile.wait_max_minutes', 20)))) {
                return false;
            }

            GenerateCompanyProfileJob::dispatch((int) $analysis->id)->onQueue('ai');

            return true;
        } catch (\Throwable $e) {
            Log::warning('Company profile dispatch failed', ['analysis_id' => $analysis->id, 'exception' => $e::class]);
            $this->recordFailureAndFinish((int) $analysis->id, 'dispatch_failed');

            return false;
        }
    }

    public function recordFailureAndFinish(int $analysisId, string $reason): void
    {
        try {
            $analysis = Analysis::query()->find($analysisId);
            if ($analysis !== null && ! $this->store->exists($analysisId)) {
                $this->service->recordFailure($analysis, $reason);
            }
        } catch (\Throwable $e) {
            Log::error('Company profile failure could not be recorded', ['analysis_id' => $analysisId, 'exception' => $e::class]);
        }

        try {
            $this->afterFinished($analysisId);
        } catch (\Throwable $e) {
            Log::error('Company profile could not advance the comparison', ['analysis_id' => $analysisId, 'exception' => $e::class]);
        }
    }

    public function afterFinished(int $analysisId): void
    {
        $pipeline = app(AnalysisPipeline::class);
        $pipeline->updateAnalysisProgress($analysisId);
        $pipeline->maybeFinalizeAnalysis($analysisId);
    }
}
