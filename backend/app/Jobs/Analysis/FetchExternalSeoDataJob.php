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

        // モックデータは「本物の評価」として採点に混ぜない(UI上もデモ表示に留める)。
        $status = $snapshot->is_mock ? MetricResultStatus::NotApplicable : MetricResultStatus::Success;
        $confidence = $snapshot->is_mock ? 0.0 : 0.9;
        $evidence = ['provider' => $snapshot->provider, 'domain' => $snapshot->domain, 'fetched_at' => $snapshot->fetched_at?->toIso8601String(), 'is_mock' => $snapshot->is_mock];

        $this->recordMetric($this->websiteAnalysisId, 'authority_score', $status, normalizedValue: $domain['authority_score'] ?? null, rawValue: $domain, evidence: $evidence, confidence: $confidence);
        $this->recordMetric($this->websiteAnalysisId, 'organic_traffic_estimate', $status, normalizedValue: $domain['organic_traffic_estimate'] ?? null, rawValue: $domain, evidence: $evidence, confidence: $confidence);
        $this->recordMetric($this->websiteAnalysisId, 'organic_keywords_count', $status, normalizedValue: $domain['organic_keywords_count'] ?? null, rawValue: $domain, evidence: $evidence, confidence: $confidence);
        $this->recordMetric($this->websiteAnalysisId, 'top10_keywords_count', $status, normalizedValue: $keywords['top10_keywords_count'] ?? null, rawValue: $keywords, evidence: $evidence, confidence: $confidence);
        $this->recordMetric($this->websiteAnalysisId, 'backlinks_count', $status, normalizedValue: $backlinks['backlinks_count'] ?? null, rawValue: $backlinks, evidence: $evidence, confidence: $confidence);
        $this->recordMetric($this->websiteAnalysisId, 'referring_domains_count', $status, normalizedValue: $backlinks['referring_domains_count'] ?? null, rawValue: $backlinks, evidence: $evidence, confidence: $confidence);

        $this->recordMetric($this->websiteAnalysisId, 'top3_keywords_count', $status, normalizedValue: $keywords['top3_keywords_count'] ?? null, rawValue: $keywords, evidence: $evidence, confidence: $confidence);
        $this->recordMetric($this->websiteAnalysisId, 'paid_search_present', $status, normalizedValue: $domain['paid_search_present'] ?? null, rawValue: $domain, evidence: $evidence, confidence: $confidence);
        $this->recordMetric($this->websiteAnalysisId, 'competitor_domains_count', $status, normalizedValue: $competitors['competitor_domains_count'] ?? null, rawValue: $competitors, evidence: $evidence, confidence: $confidence);
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
