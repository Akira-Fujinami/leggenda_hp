<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼AS-2(2026-09-03): 「診断が完了しました」メール(相談リクエストの
 * 有無にかかわらず送る、新設のBrandWheelLeadDiagnosisCompletedMail)の
 * 二重送信防止マーカー。既存のBrandWheelAnalysisResult.lead_notified_at
 * (依頼者指定でこの依頼では変更しない、相談リクエスト起点の別のメール用)
 * とは別物 ―― こちらはAnalysis単位(GenerateLeadReportJobの成功時点)で
 * 1回だけ確定させる。Analysis.lead_quota_consumed_atと同じ「nullの行だけを
 * 対象にした条件付きUPDATE」方式(LeadDiagnosisCompletedNotifier参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->timestamp('lead_diagnosis_completed_notified_at')->nullable()->after('lead_quota_consumed_at');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn('lead_diagnosis_completed_notified_at');
        });
    }
};
