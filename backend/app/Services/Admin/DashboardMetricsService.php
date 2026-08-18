<?php

namespace App\Services\Admin;

use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadCompany;
use App\Models\LeadSession;
use App\Models\Report;
use Illuminate\Support\Collection;

/**
 * 管理者ダッシュボード上部KPI・「最近の診断企業」「注目企業」「要確認・
 * エラー」セクションの集計(依頼#4〜19)。無料診断(project.lead_company_id
 * が非null)のみを対象とする ―― 社内ユーザーが直接作成したprojectの
 * analysesは営業リードではないため含めない(既存のisCongested()と同じ
 * スコープ、LeadAnalysisController参照)。
 */
class DashboardMetricsService
{
    /**
     * 「要確認・エラー」の対象期間。無期限にすると企業数増加とともに
     * 重くなり、かつ古いエラーは営業上のアクションに繋がらないため、
     * 直近30日に限定する(KPI件数・一覧セクションとも同じ窓を使う)。
     */
    private const NEEDS_ATTENTION_WINDOW_DAYS = 30;

    private const RECENT_COMPANIES_LIMIT = 8;

    private const NOTABLE_COMPANIES_LIMIT = 5;

    private const NEEDS_ATTENTION_LIMIT = 10;

    /**
     * @return array{today_count: int, month_count: int, company_count: int, re_diagnosed_count: int, consultation_count: int, needs_attention_count: int}
     */
    public function kpis(): array
    {
        return [
            'today_count' => $this->leadAnalysesQuery()->whereDate('analyses.created_at', now()->toDateString())->count(),
            'month_count' => $this->leadAnalysesQuery()
                ->whereYear('analyses.created_at', now()->year)
                ->whereMonth('analyses.created_at', now()->month)
                ->count(),
            'company_count' => LeadCompany::query()->count(),
            're_diagnosed_count' => LeadCompany::query()->has('analyses', '>=', 2)->count(),
            // 既存のlead_sessions.consultation_requested_atをそのまま再利用する
            // (依頼#4「既存テーブル・イベントがあれば再利用」)。
            'consultation_count' => LeadSession::query()->whereNotNull('consultation_requested_at')->count(),
            'needs_attention_count' => $this->needsAttention()->count(),
        ];
    }

    /**
     * @return Collection<int, array{company_id: int, company_name: string, diagnosis_count: int, last_diagnosed_at: \Illuminate\Support\Carbon, sales_status: string}>
     */
    public function recentCompanies(): Collection
    {
        return LeadCompany::query()
            ->withCount('analyses')
            ->withMax('analyses', 'created_at')
            ->has('analyses')
            ->orderByDesc('analyses_max_created_at')
            ->limit(self::RECENT_COMPANIES_LIMIT)
            ->get()
            ->map(fn (LeadCompany $company) => [
                'company_id' => $company->id,
                'company_name' => $company->company_name,
                'diagnosis_count' => (int) $company->analyses_count,
                'last_diagnosed_at' => $company->analyses_max_created_at,
                'sales_status' => $company->sales_status,
            ]);
    }

    /**
     * 再診断企業(diagnosis_count >= 2)を診断回数の多い順に(依頼#18、
     * AIスコアリングは行わない機械的な件数順)。
     *
     * @return Collection<int, array{company_id: int, company_name: string, diagnosis_count: int, last_diagnosed_at: \Illuminate\Support\Carbon}>
     */
    public function notableCompanies(): Collection
    {
        return LeadCompany::query()
            ->withCount('analyses')
            ->withMax('analyses', 'created_at')
            ->has('analyses', '>=', 2)
            ->orderByDesc('analyses_count')
            ->limit(self::NOTABLE_COMPANIES_LIMIT)
            ->get()
            ->map(fn (LeadCompany $company) => [
                'company_id' => $company->id,
                'company_name' => $company->company_name,
                'diagnosis_count' => (int) $company->analyses_count,
                'last_diagnosed_at' => $company->analyses_max_created_at,
            ]);
    }

    /**
     * 要確認・エラー一覧(依頼#19)。analysis.status(failed/partial)・
     * report.status(failed)・Brand Wheel(insufficient_input/error)・
     * source_pagesのunreadableを対象にする。件数が多くなりうるため
     * ダッシュボード表示用に上限を設ける(依頼#25、無制限スキャンにしない)。
     *
     * @return Collection<int, array{analysis_id: int, company_name: ?string, reason: string, occurred_at: \Illuminate\Support\Carbon}>
     */
    public function needsAttentionForDashboard(): Collection
    {
        return $this->needsAttention()->take(self::NEEDS_ATTENTION_LIMIT)->values();
    }

    /**
     * @return Collection<int, array{analysis_id: int, company_name: ?string, reason: string, occurred_at: \Illuminate\Support\Carbon}>
     */
    private function needsAttention(): Collection
    {
        $since = now()->subDays(self::NEEDS_ATTENTION_WINDOW_DAYS);

        $failedOrPartial = Analysis::query()
            ->whereNotNull('project_id')
            ->whereHas('project', fn ($q) => $q->whereNotNull('lead_company_id'))
            ->whereIn('status', ['failed', 'partial'])
            ->where('created_at', '>=', $since)
            ->with('project.leadCompany')
            ->get()
            ->map(fn (Analysis $analysis) => [
                'analysis_id' => $analysis->id,
                'company_name' => $analysis->project?->leadCompany?->company_name,
                'reason' => $analysis->status === \App\Enums\AnalysisStatus::Failed ? '診断失敗(failed)' : '一部取得失敗(partial)',
                'occurred_at' => $analysis->updated_at,
            ]);

        $failedReports = Report::query()
            ->where('status', 'failed')
            ->where('reports.created_at', '>=', $since)
            ->whereHas('analysis.project', fn ($q) => $q->whereNotNull('lead_company_id'))
            ->with('analysis.project.leadCompany')
            ->get()
            ->map(fn (Report $report) => [
                'analysis_id' => $report->analysis_id,
                'company_name' => $report->analysis?->project?->leadCompany?->company_name,
                'reason' => 'PDF/Wordレポート生成失敗',
                'occurred_at' => $report->updated_at,
            ]);

        $brandWheelIssues = BrandWheelAnalysisResult::query()
            ->whereIn('status', ['insufficient_input', 'error'])
            ->where('brand_wheel_analysis_results.created_at', '>=', $since)
            ->whereHas('analysis.project', fn ($q) => $q->whereNotNull('lead_company_id'))
            ->with('analysis.project.leadCompany')
            ->get()
            ->map(fn (BrandWheelAnalysisResult $result) => [
                'analysis_id' => $result->analysis_id,
                'company_name' => $result->analysis?->project?->leadCompany?->company_name,
                'reason' => $result->status === 'insufficient_input'
                    ? 'Brand Wheel: 情報不足(insufficient_input)'
                    : 'Brand Wheel: 生成エラー',
                'occurred_at' => $result->updated_at,
            ]);

        // source_pagesの値(read/unreadable)はJSON列のため、DBドライバ差異
        // (Postgres/SQLite)を避けてPHP側で判定する。対象母数は上のwhereで
        // 既に「直近30日・リード紐付き」に絞られたBrand Wheel結果のみ
        // (依頼#25、無制限スキャンを避ける)。
        $unreadablePages = BrandWheelAnalysisResult::query()
            ->where('status', 'success')
            ->where('brand_wheel_analysis_results.created_at', '>=', $since)
            ->whereHas('analysis.project', fn ($q) => $q->whereNotNull('lead_company_id'))
            ->with('analysis.project.leadCompany')
            ->get()
            ->filter(fn (BrandWheelAnalysisResult $result) => in_array('unreadable', (array) $result->source_pages, true))
            ->map(fn (BrandWheelAnalysisResult $result) => [
                'analysis_id' => $result->analysis_id,
                'company_name' => $result->analysis?->project?->leadCompany?->company_name,
                'reason' => 'ページ読み取り失敗(source page unreadable)',
                'occurred_at' => $result->updated_at,
            ]);

        return $failedOrPartial
            ->concat($failedReports)
            ->concat($brandWheelIssues)
            ->concat($unreadablePages)
            ->sortByDesc('occurred_at')
            ->values();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Analysis>
     */
    private function leadAnalysesQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Analysis::query()->whereHas('project', fn ($q) => $q->whereNotNull('lead_company_id'));
    }
}
