<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\AnalyzerClient;
use Illuminate\Support\Facades\Storage;

/**
 * Lighthouseの実行。生のLighthouseレポートはAPIレスポンスに含めず、
 * ストレージにのみ保存する。取得できなかった指標はnullのまま(0にしない)。
 * normalized_valueには生の値(スコアは0-100、コアウェブバイタルは実測値)を
 * 保存し、採点(0-1への変換)はMetricScorer(lighthouse/inverse_linear/threshold
 * strategy)側で行う。
 */
class RunLighthouseJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    public $tries = 2;

    public $timeout = 180;

    public $backoff = [30, 90];

    public function jobType(): JobType
    {
        return JobType::RunLighthouse;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $website = $websiteAnalysis->website;

        /** @var AnalyzerClient $client */
        $client = app(AnalyzerClient::class);
        $data = $client->lighthouse($website->normalized_url);

        if (isset($data['raw_report'])) {
            /** @var AnalysisStoragePaths $paths */
            $paths = app(AnalysisStoragePaths::class);
            $metadataPath = $paths->metadataPath($this->analysisId, $this->websiteAnalysisId, 'lighthouse.json');
            Storage::disk('analysis')->put($metadataPath, json_encode($data['raw_report'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $scores = $data['scores'] ?? [];
        $metrics = $data['metrics'] ?? [];
        $metadata = $data['metadata'] ?? null;
        $evidence = ['url' => $website->normalized_url, 'metadata' => $metadata];
        // run_countが1(単発計測)の場合、外部広告・ネットワーク状況・Cookie
        // 表示等の影響を受けた1回限りの結果である可能性があるため、
        // 通常より低いconfidenceで記録する(3回計測・中央値化は将来課題)。
        $confidence = (($metadata['run_count'] ?? 1) <= 1) ? 0.75 : 0.95;

        $this->recordScore('lighthouse_performance', $scores['performance'] ?? null, ['scores' => $scores], $evidence, $confidence);
        $this->recordScore('lighthouse_accessibility', $scores['accessibility'] ?? null, ['scores' => $scores], $evidence, $confidence);
        $this->recordScore('lighthouse_best_practices', $scores['best_practices'] ?? null, ['scores' => $scores], $evidence, $confidence);
        $this->recordScore('lighthouse_seo_score', $scores['seo'] ?? null, ['scores' => $scores], $evidence, $confidence);

        $this->recordMetricValue('fcp', $metrics['fcp_ms'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('lcp', $metrics['lcp_ms'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('cls', $metrics['cls'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('speed_index', $metrics['speed_index_ms'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('tbt', $metrics['tbt_ms'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('lighthouse_request_count', $metrics['request_count'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('lighthouse_transfer_size', $metrics['transfer_size_bytes'] ?? null, $metrics, $evidence, $confidence);
    }

    /**
     * @param  array<string, mixed>  $rawValue
     * @param  array<string, mixed>  $evidence
     */
    private function recordScore(string $key, ?float $value, array $rawValue, array $evidence, float $confidence): void
    {
        $this->recordMetric(
            $this->websiteAnalysisId,
            $key,
            $value !== null ? MetricResultStatus::Success : MetricResultStatus::Unavailable,
            normalizedValue: $value,
            rawValue: $rawValue,
            evidence: $evidence,
            confidence: $confidence,
        );
    }

    /**
     * @param  array<string, mixed>  $rawValue
     * @param  array<string, mixed>  $evidence
     */
    private function recordMetricValue(string $key, mixed $value, array $rawValue, array $evidence, float $confidence): void
    {
        $this->recordMetric(
            $this->websiteAnalysisId,
            $key,
            $value !== null ? MetricResultStatus::Success : MetricResultStatus::Unavailable,
            normalizedValue: $value,
            rawValue: $rawValue,
            evidence: $evidence,
            confidence: $confidence,
        );
    }
}
