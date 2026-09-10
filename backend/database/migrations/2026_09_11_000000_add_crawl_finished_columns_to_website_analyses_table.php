<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼BU-1(2026-09-11): 巡回(CrawlWebsitePageJob)の終了理由は、従来
 * Log::info('brand_wheel_crawl_completed', ['reason' => $reason, ...])に
 * 出すだけで、DBには保存していなかった。本番でメルカリ・DeNAの比較結果に
 * 説明のつかない数字が出た際、「巡回がページに届いていない」という推測を
 * 確かめる手段がどこにも無かった(依頼者指摘)。finalizeCrawl()が既に
 * ログへ出している値をそのままここへも保存する(新しい理由は作らない)。
 *
 * 件数(fetched/failed/excluded系/pending)はanalysis_crawled_pagesの
 * 実データを表示のたびに集計すれば足りるため、列を追加しない。保存が
 * 必要なのは終了理由と終了時刻だけ(依頼者指定)。
 *
 * crawl_site=falseの診断(CrawlWebsitePageJob自体が呼ばれない)では
 * どちらもnullのままになる ―― 既存データと同じ「不明」表示になることを
 * 管理画面側で保証する(依頼BU-2)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_analyses', function (Blueprint $table) {
            $table->string('crawl_finished_reason')->nullable()->after('recruitment_track_exclusion_fallback_at');
            $table->timestamp('crawl_finished_at')->nullable()->after('crawl_finished_reason');
        });
    }

    public function down(): void
    {
        Schema::table('website_analyses', function (Blueprint $table) {
            $table->dropColumn(['crawl_finished_reason', 'crawl_finished_at']);
        });
    }
};
