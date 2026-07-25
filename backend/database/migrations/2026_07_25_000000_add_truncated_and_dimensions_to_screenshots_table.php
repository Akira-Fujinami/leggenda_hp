<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ページ高さがANALYZER_SCREENSHOT_MAX_HEIGHTを超える巨大ページ(例: ユニクロ等の
 * ECサイト)向けに、fullPageを撮影しきらず部分的にクリップした場合の記録用。
 * truncated=trueの場合でも撮影自体は成功しているため、Job/分析全体は失敗
 * 扱いにしない(部分成功として表示する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screenshots', function (Blueprint $table) {
            $table->boolean('truncated')->default(false)->after('mime_type');
            $table->unsignedInteger('document_height')->nullable()->after('truncated');
            $table->unsignedInteger('captured_height')->nullable()->after('document_height');
        });
    }

    public function down(): void
    {
        Schema::table('screenshots', function (Blueprint $table) {
            $table->dropColumn(['truncated', 'document_height', 'captured_height']);
        });
    }
};
