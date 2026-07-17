<?php

namespace App\Jobs\Analysis;

use App\Enums\Device;
use App\Enums\JobType;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\Screenshot;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalyzerClient;

/**
 * デスクトップ/モバイルのスクリーンショット取得。画像本体はanalyzerが
 * 共有Dockerボリュームへ直接書き込み、Laravelへはstorage_pathとメタデータ
 * (width/height/file_size/mime_type)のみが返る(base64は使わない)。
 */
class CaptureScreenshotJob extends BaseWebsiteAnalysisJob
{
    public $tries = 2;

    public $timeout = 60;

    public $backoff = [10, 30];

    public function __construct(
        int $analysisId,
        int $websiteAnalysisId,
        public readonly Device $device,
    ) {
        parent::__construct($analysisId, $websiteAnalysisId);
    }

    public function jobType(): JobType
    {
        return match ($this->device) {
            Device::Desktop => JobType::CaptureScreenshotDesktop,
            Device::Mobile => JobType::CaptureScreenshotMobile,
        };
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $website = $websiteAnalysis->website;

        /** @var AnalyzerClient $client */
        $client = app(AnalyzerClient::class);
        $data = $client->screenshot($this->analysisId, $this->websiteAnalysisId, $website->normalized_url, $this->device->value);

        Screenshot::query()->updateOrCreate(
            ['website_analysis_id' => $this->websiteAnalysisId, 'device' => $this->device],
            [
                'storage_path' => $data['storage_path'],
                'width' => $data['width'] ?? $this->device->width(),
                'height' => $data['height'] ?? $this->device->height(),
                'file_size' => $data['file_size'] ?? 0,
                'mime_type' => $data['mime_type'] ?? 'image/png',
                'captured_at' => now(),
            ],
        );
    }
}
