<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼D(巡回機能の実運用化)。CrawlWebsiteJobを「1本の長時間ジョブ」から
 * 「1ジョブ=1ページ」の連鎖に作り替えるため、探索フロンティア
 * (未取得URL・取得済み・失敗・除外)をこのテーブル自身で表現する。
 *
 * status: 'pending'(未取得、フロンティアに乗っている) |
 *         'fetched'(取得成功、http_status 2xx) |
 *         'failed'(取得失敗、または2xx以外) |
 *         'excluded_by_pattern' | 'excluded_by_robots' | 'excluded_by_scope'
 * (2026_08_25_000001時点ではhttp_status/raw_html_pathの有無だけで状態を
 * 表現していたが、「未取得」を表現できずフロンティアを兼ねられなかった
 * ため追加する。旧テーブルはこのマイグレーション作成時点でまだ本番に
 * 反映されていない(未commit)ため、新規マイグレーションとして追加のみ行う
 * ―― 既存マイグレーションファイル自体は編集しない、という通常のLaravelの
 * 作法に従う)。
 *
 * content_length: 取得した本文のバイト数。旧実装はJob内のローカル変数
 * ($storageUsed)で合計容量の上限を判定していたが、1ジョブ=1ページに
 * 分割すると各ジョブが独立プロセスになりローカル変数を共有できないため、
 * DBに保存してSUM()で毎回再計算する。
 *
 * render_candidate: 条件付きレンダリング(依頼D-4)の対象として選ばれた
 * ページかどうか。true=レンダリング未処理(対象として選ばれ、まだ
 * 処理されていない)、false=対象外、またはレンダリング処理済み
 * (成功・失敗いずれも)。「まだ処理すべきか」を単一のbooleanで表現する
 * ことで、RenderCrawledPageJobが安全に「次に処理すべき行」をクエリできる
 * ようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_crawled_pages', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('discovered_via');
            $table->unsignedInteger('content_length')->nullable()->after('content_type');
            $table->boolean('render_candidate')->default(false)->after('rendered_html_path');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_crawled_pages', function (Blueprint $table) {
            $table->dropColumn(['status', 'content_length', 'render_candidate']);
        });
    }
};
