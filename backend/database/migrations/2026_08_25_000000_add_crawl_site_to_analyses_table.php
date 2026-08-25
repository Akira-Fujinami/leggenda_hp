<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼C(サイト全ページ巡回・Phase 1)。skip_lighthouse/skip_screenshots/
 * skip_brand_wheelと同じ「フラグでオプトインする分析の付加機能」パターン。
 *
 * 既定はfalse(巡回しない) ―― この診断が本番のリード向け自己申告フロー
 * (frontend/src/features/lead/)から来たものであっても挙動が一切変わらない
 * ことを保証する。管理画面から明示的にtrueを指定した診断だけが、
 * RenderPageJob終端でGenerateBrandWheelAnalysisJobを直接起動する代わりに
 * CrawlWebsiteJobを起動する(AnalysisPipeline::dispatchBrandWheelAnalysisIfDue()
 * 参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->boolean('crawl_site')->default(false)->after('lead_quota_consumed_at');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn('crawl_site');
        });
    }
};
