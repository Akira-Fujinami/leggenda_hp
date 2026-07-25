<?php

namespace App\Jobs\Analysis;

use App\Enums\Device;
use App\Enums\JobType;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\Screenshot;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalyzerClient;
use Illuminate\Support\Facades\Log;

/**
 * デスクトップ/モバイルのスクリーンショット取得。画像本体はanalyzerが
 * 共有Dockerボリュームへ直接書き込み、Laravelへはstorage_pathとメタデータ
 * (width/height/file_size/mime_type)のみが返る(base64は使わない)。
 */
class CaptureScreenshotJob extends BaseWebsiteAnalysisJob
{
    public $tries = 2;

    // AnalyzerClient::screenshot()のHTTP timeout(90秒)と同値だと、RunLighthouseJobで
    // 実際に発生した障害と同じクラスの不具合(ジョブtimeoutとHTTP timeoutの
    // 競合によるWorkerプロセス強制終了)が起こり得る。30秒以上のマージンを保つ。
    public $timeout = 120;

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

        // 巨大ページ(例: ECサイトのfullPage)でANALYZER_SCREENSHOT_MAX_HEIGHTを
        // 超える場合、analyzerはviewport幅×最大高さでクリップした画像を
        // truncated=trueとして返す。撮影自体は成功しているためJobは失敗に
        // せず、原因調査できるようwarning logのみ残す。
        if ($data['truncated'] ?? false) {
            Log::warning('CaptureScreenshotJob received a truncated screenshot from analyzer', [
                'analysis_id' => $this->analysisId,
                'website_analysis_id' => $this->websiteAnalysisId,
                'device' => $this->device->value,
                'document_height' => $data['document_height'] ?? null,
                'captured_height' => $data['captured_height'] ?? null,
            ]);
        }

        Screenshot::query()->updateOrCreate(
            ['website_analysis_id' => $this->websiteAnalysisId, 'device' => $this->device],
            [
                'storage_path' => $data['storage_path'],
                'width' => $data['width'] ?? $this->device->width(),
                'height' => $data['height'] ?? $this->device->height(),
                'file_size' => $data['file_size'] ?? 0,
                'mime_type' => $data['mime_type'] ?? 'image/jpeg',
                'truncated' => $data['truncated'] ?? false,
                'document_height' => $data['document_height'] ?? null,
                'captured_height' => $data['captured_height'] ?? null,
                'captured_at' => now(),
            ],
        );
    }
}
