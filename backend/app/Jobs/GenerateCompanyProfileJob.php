<?php

namespace App\Jobs;

use App\Models\Analysis;
use App\Services\CompanyProfile\CompanyProfileDispatcher;
use App\Services\CompanyProfile\CompanyProfileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 依頼DK: 比較の各社の「私たちが理解している、{社名}という会社」を作る(AIは1社1回・Web検索つき)。全社の巡回が終わったあとに起動する。
 * 失敗しても比較全体は失敗させない(失敗の記録を結果のファイルに置き、ページを入れないだけ)。再試行はしない(呼び出し回数を増やさない)。
 */
class GenerateCompanyProfileJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout;

    public $uniqueFor;

    public function __construct(public readonly int $analysisId)
    {
        // 1社あたり、AI(続きを含む)と出典の取りに行きの分。競合3社+自社を見込む。
        $perCompany = ((int) config('company_profile.timeout', 180)) * (1 + (int) config('company_profile.max_continuations', 2))
            + ((int) config('company_profile.source_fetch_timeout', 15)) * (int) config('company_profile.source_fetch_max', 15);
        $this->timeout = $perCompany * 4 + 60;
        $this->uniqueFor = $this->timeout * 2;
    }

    public function uniqueId(): string
    {
        return "company-profile:{$this->analysisId}";
    }

    public function handle(CompanyProfileService $service, CompanyProfileDispatcher $dispatcher): void
    {
        $analysis = Analysis::find($this->analysisId);
        if ($analysis === null) {
            return;
        }

        $failure = null;
        try {
            $service->generate($analysis);
        } catch (\Throwable $e) {
            Log::error('Company profile failed', ['analysis_id' => $analysis->id, 'exception' => $e::class]);
            $failure = $e::class;
        }

        if ($failure !== null) {
            $dispatcher->recordFailureAndFinish((int) $analysis->id, $failure);
        } else {
            $dispatcher->afterFinished((int) $analysis->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Company profile job failed', ['analysis_id' => $this->analysisId, 'exception' => $exception !== null ? $exception::class : null]);
        app(CompanyProfileDispatcher::class)->recordFailureAndFinish($this->analysisId, $exception !== null ? $exception::class : 'job_failed');
    }
}
