<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ナビゲーション「診断管理」(依頼#21)。診断企業(LeadCompany)を軸にした
 * CompanyController@showの履歴とは別に、診断(Analysis)を軸に横断的へ
 * 一覧・確認できる画面。依頼#16「/admin/analyses/{id}への導線」のURL設計を
 * 実際に満たす(詳細画面もMVPで作成する)。
 */
class AnalysisController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();

        $analyses = Analysis::query()
            ->whereHas('project', fn ($q) => $q->whereNotNull('lead_company_id'))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->with(['project.leadCompany', 'project.websites'])
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            // scheme+hostを含まないパスに固定する(App\Services\Admin\
            // LeadCompanyQueryService::paginate()と同じ理由)。
            ->setPath($request->getPathInfo());

        return view('admin.analyses.index', [
            'analyses' => $analyses,
            'status' => $status,
        ]);
    }

    public function show(Analysis $analysis): View
    {
        abort_unless($analysis->project?->lead_company_id !== null, 404);

        $analysis->load(['project.leadCompany', 'project.websites', 'websiteAnalyses.website', 'reports', 'sourceAnalysis', 'comparisons', 'attachments']);

        $brandWheelResults = BrandWheelAnalysisResult::query()
            ->where('analysis_id', $analysis->id)
            ->orderByDesc('id')
            ->get()
            ->unique('website_analysis_id');

        return view('admin.analyses.show', [
            'analysis' => $analysis,
            'brandWheelResults' => $brandWheelResults,
        ]);
    }

    /**
     * Worker停止・OOM・例外で終端処理に到達できず、statusがPending/Queued/
     * Runningのまま残った「停止した」Analysisを、営業が管理画面から即座に
     * 終端(Cancelled)にできる導線(依頼者指摘)。config('lead.stale_analysis_
     * after_minutes')(既定30分)を待たずに、hasAnalysisInProgress()・
     * isCongested()の両ガードから即座に外すための手動介入。B-4のリセット
     * (analyses_used→0)とは別のアクション ―― 停止したAnalysisはそもそも
     * analyses_usedが未消費(0)のことが多く、リセットしても復旧しない。
     */
    public function forceTerminate(Request $request, Analysis $analysis): RedirectResponse
    {
        if ($analysis->status->isTerminal()) {
            return back()->with('status', 'この診断は既に終了しています。');
        }

        $previousStatus = $analysis->status->value;

        $analysis->update([
            'status' => AnalysisStatus::Cancelled,
            'failed_at' => now(),
            'error_summary' => '管理者により強制終了されました。',
        ]);

        Log::warning('Admin force-terminated a stuck analysis', [
            'analysis_id' => $analysis->id,
            'previous_status' => $previousStatus,
            'ip' => $request->ip(),
        ]);

        return back()->with('status', '診断を強制終了しました。');
    }

    /**
     * 依頼AC(2026-08-27): 多社比較レポート(PDFのみ)のダウンロード。
     * admin.auth配下(共有アカウント)のため、リード向けdownloadReport()の
     * ようなオーナーシップ検証は不要 ―― 比較Analysis以外(source_analysis_id
     * がnull)からのアクセスは404にする(このエンドポイントの対象外)。
     */
    public function downloadComparisonReport(Analysis $analysis): StreamedResponse
    {
        abort_if($analysis->source_analysis_id === null, 404);

        $report = Report::query()
            ->where('analysis_id', $analysis->id)
            ->where('format', ReportFormat::Pdf->value)
            ->first();

        abort_if($report === null || $report->status !== ReportGenerationStatus::Completed, 404, 'レポートはまだ準備できていません。');
        abort_unless(Storage::disk('analysis')->exists($report->storage_path), 404);

        return Storage::disk('analysis')->download($report->storage_path, "多社比較レポート_{$analysis->id}.pdf", [
            'Content-Type' => ReportFormat::Pdf->contentType(),
        ]);
    }
}
