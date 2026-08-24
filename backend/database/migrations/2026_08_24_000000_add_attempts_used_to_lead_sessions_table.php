<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リード診断の「成果を受け取った回数」(LeadSession.analyses_used、AIまで
 * 到達し実際にマッチした結果が得られた場合のみ増える)とは別に、「試行した
 * 回数」(成否を問わず診断を開始した回数)を独立してカウントする列。
 *
 * 2026-08-24: ブランド・ホイールの判定がerror/insufficient_input/
 * matched=0(いずれもレポートを生成しない=analyses_used未消費)で終端した
 * 場合、同一トークンは何度でも新しい診断を試行できてしまう
 * (analyses_usedのみに基づく既存のcanStartAnalysis()では歯止めが効かない)。
 * これ自体はサイト側の事情(insufficient_input)やシステム障害時の一斉
 * リトライを許してしまうリスクがあるため、試行回数だけで別途上限を設ける
 * (config('lead.max_attempts_per_token')、既定5)。
 *
 * SELF_URL_UNREACHABLE(#B-1、URLへ到達できず診断そのものを開始しなかった
 * 場合)は「試行」に数えない ―― 既にリードへ「この診断はご利用回数に
 * 含まれておりません」と明示している経路のため。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts_used')->default(0)->after('analyses_used');
        });
    }

    public function down(): void
    {
        Schema::table('lead_sessions', function (Blueprint $table) {
            $table->dropColumn('attempts_used');
        });
    }
};
