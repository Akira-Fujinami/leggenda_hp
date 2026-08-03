<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ブランド・ホイール(6軸)分析を、診断実行時に自社・競合の両方について
 * dispatchWebsiteFanOut()から起動するようにするための切り替えフラグ。
 *
 * skip_lighthouse/skip_screenshots(既定false=実行する)とは意図的に
 * 既定値の向きを逆にする ―― この処理はOpenAIへの課金呼び出しであり、
 * サイト本文を外部(OpenAI)へ送信する処理でもある。将来Analysisを作る
 * 経路が増えたとき、明示的な指定を忘れても黙って実行されない(=コストも
 * 外部送信も発生しない)側を既定にする。実行したい呼び出し元
 * (LeadAnalysisController::store())だけが明示的にfalseを渡す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->boolean('skip_brand_wheel')->default(true)->after('skip_screenshots');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn('skip_brand_wheel');
        });
    }
};
