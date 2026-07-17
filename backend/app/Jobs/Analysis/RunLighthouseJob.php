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

        $performance = $scores['performance'] ?? null;
        $this->recordMetric(
            $this->websiteAnalysisId,
            'lighthouse_performance',
            $performance !== null ? MetricResultStatus::Success : MetricResultStatus::Unavailable,
            achievedRatio: $performance !== null ? max(0.0, min(1.0, $performance / 100)) : 0.0,
            rawValue: ['scores' => $scores, 'metrics' => $metrics],
            evidence: ['url' => $website->normalized_url],
        );

        $seo = $scores['seo'] ?? null;
        $this->recordMetric(
            $this->websiteAnalysisId,
            'lighthouse_seo',
            $seo !== null ? MetricResultStatus::Success : MetricResultStatus::Unavailable,
            achievedRatio: $seo !== null ? max(0.0, min(1.0, $seo / 100)) : 0.0,
            rawValue: ['score' => $seo],
        );

        $accessibility = $scores['accessibility'] ?? null;
        $this->recordMetric(
            $this->websiteAnalysisId,
            'lighthouse_accessibility',
            $accessibility !== null ? MetricResultStatus::Success : MetricResultStatus::Unavailable,
            achievedRatio: $accessibility !== null ? max(0.0, min(1.0, $accessibility / 100)) : 0.0,
            rawValue: ['score' => $accessibility],
        );
    }
}
