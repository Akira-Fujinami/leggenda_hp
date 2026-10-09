<?php

namespace App\Jobs;

use App\Models\WebsiteAnalysis;
use App\Services\CorporateTop\CorporateTopService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CR-1: 比較の自社サイトについて、階層図のTOPにするコーポレートサイトのTOPを1回だけ取得する
 * (CorporateTopService)。取得に失敗しても、比較は失敗させない ―― AnalysisJobの行を作らず(進捗・
 * 完了判定に関わらない)、例外はここで受けてログに残すだけにする。階層図はいまの描き方になる。
 */
class FetchCorporateTopJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout;

    public $uniqueFor;

    public function __construct(public readonly int $websiteAnalysisId)
    {
        // 候補は最大3つ。1つあたりの締切り+余裕。
        $this->timeout = ((int) config('admin_comparison_pptx.corporate_top_fetch_timeout_seconds', 15)) * 3 + 30;
        $this->uniqueFor = $this->timeout * 3;
    }

    public function uniqueId(): string
    {
        return "corporate-top:{$this->websiteAnalysisId}";
    }

    public function handle(CorporateTopService $service): void
    {
        $websiteAnalysis = WebsiteAnalysis::find($this->websiteAnalysisId);
        if ($websiteAnalysis === null) {
            return;
        }

        try {
            $service->run($websiteAnalysis);
        } catch (\Throwable $e) {
            Log::warning('Corporate top fetch failed', [
                'analysis_id' => $websiteAnalysis->analysis_id,
                'website_analysis_id' => $websiteAnalysis->id,
                'exception' => $e::class,
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::warning('Corporate top job failed', [
            'website_analysis_id' => $this->websiteAnalysisId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
