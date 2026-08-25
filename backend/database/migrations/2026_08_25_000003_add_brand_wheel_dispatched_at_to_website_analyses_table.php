<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼D-2。CrawlWebsiteJob/CrawlWebsitePageJob/RenderCrawledPageJobの
 * いずれの終端(正常終了・上限打ち切り・failed())からも
 * AnalysisPipeline::dispatchBrandWheelAnalysisAfterCrawl()が呼ばれうるため、
 * 二重にBrandWheelAnalysisResultが作られない(=改善提案AI・判定AIが
 * 二重に呼ばれない)ことを保証する一意なマーカー。
 *
 * website_analysis_idごとに1回だけ更新される「nullの行だけを対象にした
 * 条件付きUPDATE」で一度だけ勝者を決める ―― Analysis.lead_quota_consumed_at
 * (GenerateBrandWheelAnalysisJob::maybeConsumeLeadQuota())と同じ方式。
 *
 * このガードはcrawl_site=trueの診断だけでなく、既定(crawl_site=false)の
 * 診断にも同様にかかる(RenderPageJobから1回しか呼ばれない現状の経路では
 * 実質no-opの安全網)。RunBrandWheelAnalysisCommand(--force再実行)は
 * このメソッドを経由せず直接GenerateBrandWheelAnalysisJobを呼ぶ独立した
 * 経路のため、このガードの影響を受けない(#99の複数回測定は従来どおり
 * 可能なまま)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_analyses', function (Blueprint $table) {
            $table->timestamp('brand_wheel_dispatched_at')->nullable()->after('response_time_ms');
        });
    }

    public function down(): void
    {
        Schema::table('website_analyses', function (Blueprint $table) {
            $table->dropColumn('brand_wheel_dispatched_at');
        });
    }
};
