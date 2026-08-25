<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼C(サイト全ページ巡回・Phase 1)。トップページ・採用ページ以外に
 * 巡回で新規発見したページを保持する。既存の`analysis_pages`
 * (page_type単位でwebsite_analysis_idにつき1行しか持てない設計)には
 * 一切触れない ―― homepage/recruit/robots/sitemapの既存4行の意味・
 * 参照箇所(GenerateBrandWheelAnalysisJob::maybeConsumeLeadQuota()の
 * トップページHTTPステータス判定、FetchRecruitPageJobの自己参照判定等)を
 * 壊さないため、意図的に別テーブルとして追加する(依頼者提出の設計案どおり)。
 *
 * トップページ・採用ページ自身は巡回のseedとして使うが再取得しない
 * (CrawlWebsiteJob参照) ―― このテーブルにはそれ以外の新規発見ページのみを
 * 保存する。
 *
 * url_hash: PostgreSQLのbtreeインデックスは1エントリ約2,704バイトが上限。
 * urlはvarchar(2048)で、日本語パスがパーセントエンコードされると
 * この上限を超えうる(依頼者指摘)。urlに直接uniqueを張るとINSERT時に
 * インデックスエラーで落ちるため、固定長のsha256ハッシュに対してunique制約を
 * 張る。生成箇所はAnalysisCrawledPageモデルのミューテータに集約し、
 * 呼び出し側でhash()を書かせない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_crawled_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_analysis_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('final_url', 2048)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->string('raw_html_path')->nullable();
            // Phase 1では常にnull(依頼C-8、レンダリングは行わない)。
            // 将来のレンダリング対応に備えてカラムだけ用意する。
            $table->string('rendered_html_path')->nullable();
            $table->string('title')->nullable();
            // seed(トップページ・採用ページの既存analysis_pages行)からの
            // BFS深さ。seed自体はこのテーブルに行を持たないため、このテーブル
            // 内で最小のdepthは1になる。
            $table->unsignedTinyInteger('depth');
            // 'sitemap' | 'link'。このURLをどう発見したかの記録(調査・
            // チューニング用途)。
            $table->string('discovered_via');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['website_analysis_id', 'url_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_crawled_pages');
    }
};
