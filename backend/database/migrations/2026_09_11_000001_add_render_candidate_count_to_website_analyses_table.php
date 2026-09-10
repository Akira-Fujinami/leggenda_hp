<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼BV-2(2026-09-11): レンダリング枚数「0」が、依頼者が実データで確認した
 * とおり2つの意味を持ってしまっていた ―― (a)候補が0件だった(取得した
 * ページがすべて本文200字以上で、静的HTMLで足りていた、正常)と、
 * (b)候補はN件あったが1枚も成功しなかった(Analyzerが混雑時に
 * TOO_BUSYを返し、RenderCrawledPageJobが警告1行で静的HTMLへ黙って
 * 降格した、異常)を、画面側では区別できなかった。
 *
 * analysis_crawled_pages.render_candidate は、RenderCrawledPageJobが
 * 処理を終えるとfalseに戻ってしまうため(処理待ち中だけtrueを示す
 * フラグとして設計されている、依頼D-4)、後から「もともと何件が候補
 * だったか」を数え直せない。CrawlWebsitePageJob::finalizeCrawl()が
 * 候補を選定した瞬間の件数($candidates->count())を、ここに保存する。
 *
 * 成功件数は既存のrendered_html_path(analysis_crawled_pages)を
 * 数えれば足りるため、そちらの列は追加しない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_analyses', function (Blueprint $table) {
            $table->unsignedInteger('render_candidate_count')->nullable()->after('crawl_finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('website_analyses', function (Blueprint $table) {
            $table->dropColumn('render_candidate_count');
        });
    }
};
