<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\ExternalDataSnapshot;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Semrush\ExternalSeoDataService;
use App\Services\Semrush\SeoProviderException;

/**
 * 外部SEOデータ(Semrush等)の取得。Provider interfaceのみに依存し、
 * Semrush固有のレスポンス形式は一切知らない。
 *
 * 設計上の方針: 外部APIの障害(認証失敗・レート制限・タイムアウト・未設定)は
 * このJob自体を失敗させず、authorityカテゴリの指標をunavailableとして記録した
 * 上で正常終了とする ―― 外部APIの都合でAnalysis全体がfailedになることを防ぐため
 * (このJobがAnalysisJob.status=failedになるのは、想定外の例外が発生した場合のみ)。
 * リトライ可否・Retry-Afterの考慮はSeoProviderException::isRetryable/
 * retryAfterSecondsに従う。
 */
class FetchExternalSeoDataJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    public $tries = 2;

    public $timeout = 45;

    public $backoff = [15, 60];

    private const SCORED_KEYS = [
        'authority_score', 'organic_traffic_estimate', 'organic_keywords_count',
        'top10_keywords_count', 'backlinks_count', 'referring_domains_count',
    ];

    private const INFORMATIONAL_KEYS = ['top3_keywords_count', 'paid_search_present', 'competitor_domains_count'];

    public function jobType(): JobType
    {
        return JobType::FetchExternalSeoData;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        /** @var ExternalSeoDataService $service */
        $service = app(ExternalSeoDataService::class);

        try {
            $snapshot = $service->fetchFor($websiteAnalysis, $this->analysisId);
        } catch (SeoProviderException $e) {
            if ($e->isRetryable && $this->attempts() < $this->tries && $this->job !== null) {
                $this->release($e->retryAfterSeconds ?? $this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

                return;
            }

            $this->recordAllUnavailable($e->errorCode, $e->getMessage());

            return;
        }

        $this->recordFromSnapshot($snapshot);
    }

    private function recordFromSnapshot(ExternalDataSnapshot $snapshot): void
    {
        if ($snapshot->status !== 'success') {
            $this->recordAllUnavailable($snapshot->error_code, $snapshot->error_message);

            return;
        }

        $data = $snapshot->normalized_data ?? [];
        $domain = $data['domain'] ?? [];
        $keywords = $data['keywords'] ?? [];
        $backlinks = $data['backlinks'] ?? [];
        $competitors = $data['competitors'] ?? [];

        $confidence = $snapshot->is_mock ? 0.0 : 0.9;
        $evidence = ['provider' => $snapshot->provider, 'domain' => $snapshot->domain, 'fetched_at' => $snapshot->fetched_at?->toIso8601String(), 'is_mock' => $snapshot->is_mock];

        $this->recordExternalMetric('authority_score', $domain['authority_score'] ?? null, $domain, $evidence, $confidence, $snapshot->is_mock);
        $this->recordExternalMetric('organic_traffic_estimate', $domain['organic_traffic_estimate'] ?? null, $domain, $evidence, $confidence, $snapshot->is_mock);
        $this->recordExternalMetric('organic_keywords_count', $domain['organic_keywords_count'] ?? null, $domain, $evidence, $confidence, $snapshot->is_mock);
        $this->recordExternalMetric('top10_keywords_count', $keywords['top10_keywords_count'] ?? null, $keywords, $evidence, $confidence, $snapshot->is_mock);
        $this->recordExternalMetric('backlinks_count', $backlinks['backlinks_count'] ?? null, $backlinks, $evidence, $confidence, $snapshot->is_mock);
        $this->recordExternalMetric('referring_domains_count', $backlinks['referring_domains_count'] ?? null, $backlinks, $evidence, $confidence, $snapshot->is_mock);

        $this->recordExternalMetric('top3_keywords_count', $keywords['top3_keywords_count'] ?? null, $keywords, $evidence, $confidence, $snapshot->is_mock);
        $this->recordExternalMetric('paid_search_present', $domain['paid_search_present'] ?? null, $domain, $evidence, $confidence, $snapshot->is_mock);
        $this->recordExternalMetric('competitor_domains_count', $competitors['competitor_domains_count'] ?? null, $competitors, $evidence, $confidence, $snapshot->is_mock);
    }

    /**
     * @param  array<string, mixed>  $rawContext
     * @param  array<string, mixed>  $evidence
     */
    private function recordExternalMetric(string $key, mixed $value, array $rawContext, array $evidence, float $confidence, bool $isMock): void
    {
        // モックデータは「本物の評価」として採点に混ぜない(UI上もデモ表示に留める)。
        if ($isMock) {
            $this->recordMetric($this->websiteAnalysisId, $key, MetricResultStatus::NotApplicable, normalizedValue: $value, rawValue: $rawContext, evidence: $evidence, confidence: $confidence);

            return;
        }

        // 実データの場合、個々の項目がnull(=Semrush側で取得できなかった)なら
        // 「成功扱いでnull」にはせず、その項目だけunavailableとして正直に記録する
        // (未取得値を0点として採点させないため)。
        if ($value === null) {
            $this->recordMetric(
                $this->websiteAnalysisId,
                $key,
                MetricResultStatus::Unavailable,
                rawValue: $rawContext,
                evidence: $evidence,
                errorCode: 'SEMRUSH_METRIC_UNAVAILABLE',
                errorMessage: 'この指標は現在契約中のSemrush APIプランでは取得できませんでした。',
            );

            return;
        }

        $this->recordMetric($this->websiteAnalysisId, $key, MetricResultStatus::Success, normalizedValue: $value, rawValue: $rawContext, evidence: $evidence, confidence: $confidence);
    }

    private function recordAllUnavailable(?string $errorCode, ?string $errorMessage): void
    {
        foreach ([...self::SCORED_KEYS, ...self::INFORMATIONAL_KEYS] as $key) {
            $this->recordMetric(
                $this->websiteAnalysisId,
                $key,
                MetricResultStatus::Unavailable,
                errorCode: $errorCode,
                errorMessage: $errorMessage,
            );
        }
    }
}
