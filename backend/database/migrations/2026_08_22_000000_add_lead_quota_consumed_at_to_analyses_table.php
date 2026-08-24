<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リード診断の実行回数消費(LeadSession.analyses_used)を、診断開始時
 * (LeadAnalysisController::store())ではなく自社サイト(is_primary=true)の
 * 本文取得成功時点(GenerateBrandWheelAnalysisJob)へ後ろにずらす変更に伴う
 * 冪等性マーカー。同じAnalysisに対してGenerateBrandWheelAnalysisJobが
 * リトライ(AI呼び出しのレート制限等)で複数回実行されても、消費が
 * 二重に記録されないよう「このAnalysisは消費済みか」をここで1回だけ確定させる
 * (LeadSessionService::recordConsultationRequested()と同じ「null列への
 * 条件付きUPDATEで一度だけ勝者を決める」方式)。全ての分析(社内向けを含む)に
 * 対して発行されるカラムだが、リード診断以外では一切参照されない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->timestamp('lead_quota_consumed_at')->nullable()->after('skip_brand_wheel');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn('lead_quota_consumed_at');
        });
    }
};
