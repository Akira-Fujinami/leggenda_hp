<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\Device;
use App\Jobs\Analysis\CaptureScreenshotJob;
use App\Jobs\Analysis\DetectTechnologyJob;
use App\Jobs\Analysis\RenderPageJob;
use App\Services\Analysis\AnalyzerClient;
use Tests\TestCase;

/**
 * RenderPageJob/CaptureScreenshotJob/DetectTechnologyJobのJob timeoutが、
 * 対応するAnalyzerClientのHTTP timeoutと同値(マージンゼロ)だった不具合の
 * 回帰テスト。RunLighthouseJobで実際に発生した障害(2026-07-24)と同じ
 * クラスの不具合で、ユニクロの分析で render/screenshot/technology detection
 * が「待機中」のまま止まる原因調査(2026-07-25)で判明した。
 *
 * 同値だと、analyzerが応答をハングさせた場合にLaravelのHTTP timeoutと
 * Laravelキュー基盤のジョブtimeout(pcntl_alarm)が競合し、ジョブtimeoutが
 * 先に発火するとhandle()のtry/catchを経由せずWorkerプロセスごと強制終了
 * されてしまう。
 */
class AnalyzerJobTimeoutMarginTest extends TestCase
{
    private const MIN_MARGIN_SECONDS = 30;

    public function test_render_page_job_timeout_keeps_a_margin_over_the_analyzer_http_timeout(): void
    {
        $jobTimeout = (new RenderPageJob(1, 1))->timeout;

        $this->assertGreaterThanOrEqual(
            self::MIN_MARGIN_SECONDS,
            $jobTimeout - AnalyzerClient::RENDER_TIMEOUT_SECONDS,
        );
    }

    public function test_capture_screenshot_job_timeout_keeps_a_margin_over_the_analyzer_http_timeout(): void
    {
        $jobTimeout = (new CaptureScreenshotJob(1, 1, Device::Desktop))->timeout;

        $this->assertGreaterThanOrEqual(
            self::MIN_MARGIN_SECONDS,
            $jobTimeout - AnalyzerClient::SCREENSHOT_TIMEOUT_SECONDS,
        );
    }

    public function test_detect_technology_job_timeout_keeps_a_margin_over_the_analyzer_http_timeout(): void
    {
        $jobTimeout = (new DetectTechnologyJob(1, 1))->timeout;

        $this->assertGreaterThanOrEqual(
            self::MIN_MARGIN_SECONDS,
            $jobTimeout - AnalyzerClient::TECHNOLOGY_TIMEOUT_SECONDS,
        );
    }

    public function test_all_analyzer_job_timeouts_stay_well_under_the_worker_timeout(): void
    {
        $recommendedWorkerTimeoutSeconds = 600;

        $this->assertLessThan($recommendedWorkerTimeoutSeconds, (new RenderPageJob(1, 1))->timeout);
        $this->assertLessThan($recommendedWorkerTimeoutSeconds, (new CaptureScreenshotJob(1, 1, Device::Desktop))->timeout);
        $this->assertLessThan($recommendedWorkerTimeoutSeconds, (new DetectTechnologyJob(1, 1))->timeout);
    }
}
