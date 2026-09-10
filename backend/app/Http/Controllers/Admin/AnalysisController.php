<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Exceptions\Report\ComparisonSlideInsertionException;
use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Report;
use App\Services\Admin\CrawlDiagnosticsService;
use App\Services\Report\AdminComparisonPptxDataBuilder;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\AdminComparisonPptxInserter;
use App\Services\Report\MultiSiteReportViewModelBuilder;
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

    public function show(Analysis $analysis, CrawlDiagnosticsService $crawlDiagnostics): View
    {
        abort_unless($analysis->project?->lead_company_id !== null, 404);

        $analysis->load(['project.leadCompany', 'project.websites', 'websiteAnalyses.website', 'reports', 'sourceAnalysis', 'comparisons', 'attachments']);

        $brandWheelResults = BrandWheelAnalysisResult::query()
            ->where('analysis_id', $analysis->id)
            ->orderByDesc('id')
            ->get()
            ->unique('website_analysis_id');

        // 依頼BU-2(2026-09-11): サイトごとの巡回実績。website_analysis_id
        // をキーにしたコレクションにしておき、blade側は
        // $crawlSummaries[$wa->id]で引くだけにする(集計ロジックをここに
        // 置かず、CrawlDiagnosticsServiceへ寄せる)。
        $crawlSummaries = $analysis->websiteAnalyses
            ->mapWithKeys(fn ($wa) => [$wa->id => $crawlDiagnostics->summarize($wa)]);

        return view('admin.analyses.show', [
            'analysis' => $analysis,
            'brandWheelResults' => $brandWheelResults,
            'crawlSummaries' => $crawlSummaries,
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

    /**
     * 依頼BG(2026-09-08): 多社比較スライド1枚を、アップロード済みの営業資料
     * (PPTX)の「参照元」ページの直前へ差し込んだPPTXをダウンロードする。
     *
     * 事前生成しない(Reportの行もJobも作らない) ―― 営業資料は差し替えられる
     * ため、ダウンロード時にその場で生成する(依頼者指定、事前生成すると
     * 差し替え後に古い資料へ差し込んだファイルを配ってしまう)。
     *
     * 添付が無い/PPTXでない場合、および比較Analysisでない場合は、画面で
     * ボタンを隠すだけでなくこのエンドポイント自体も404にする(依頼者指定)。
     *
     * 差し込みが行えない場合(参照元ページが無い・スライドサイズ不一致等)は
     * ComparisonSlideInsertionExceptionを捕捉し、理由が分かる文言を添えて
     * 診断詳細画面へ戻す(500エラーにしない ―― 想定内の中止のため)。
     *
     * 一時ファイル(比較スライド単体のpptx・差し込み後の完成品)は、成功・
     * 失敗のいずれの経路でもfinallyで必ず削除する。
     */
    public function downloadComparisonPptxInsert(
        Analysis $analysis,
        MultiSiteReportViewModelBuilder $viewModelBuilder,
        AdminComparisonPptxDataBuilder $dataBuilder,
        AdminComparisonPptxGenerator $slideGenerator,
        AdminComparisonPptxInserter $inserter,
    ): StreamedResponse|RedirectResponse {
        abort_if($analysis->source_analysis_id === null, 404);

        $analysis->loadMissing('attachments');
        $attachment = $analysis->attachments->first();
        abort_if($attachment === null || $attachment->extension !== 'pptx', 404);
        abort_unless(Storage::disk('analysis')->exists($attachment->storage_path), 404);

        $mergedPath = null;

        try {
            $viewModel = $viewModelBuilder->build($analysis);
            $data = $dataBuilder->build($viewModel);
            $slideBytes = $slideGenerator->generate($data);

            $baseDeckPath = Storage::disk('analysis')->path($attachment->storage_path);
            $mergedPath = $inserter->insert($baseDeckPath, $slideBytes);

            $mergedBytes = (string) file_get_contents($mergedPath);
            $downloadName = pathinfo($attachment->original_filename, PATHINFO_FILENAME).'_比較ページ差し込み.pptx';

            // ダウンロード後に消してよい一時ファイルからバイト列を読んだ後は
            // メモリ上のコンテンツを返すだけでよいため、Storage::download()
            // (ディスク上のパスをストリーミング配信する用途)ではなく
            // streamDownload()(日本語ファイル名のContent-Dispositionを
            // 正しく組み立てる、既存のAnalysisAttachmentController::download()
            // 等と同じ土台)を使う。
            return response()->streamDownload(
                fn () => print($mergedBytes),
                $downloadName,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            );
        } catch (ComparisonSlideInsertionException $e) {
            Log::info('Admin comparison pptx insertion aborted', [
                'analysis_id' => $analysis->id,
                'reason' => $e->getMessage(),
            ]);

            // admin.layoutはsession('status')のみを汎用フラッシュとして表示する
            // (session('error')の表示枠は無い ―― 承認外のadmin/layout.blade.php
            // を今回変更しないため、既存のキーをそのまま使う)。
            return back()->with('status', $e->getMessage());
        } finally {
            if ($mergedPath !== null && file_exists($mergedPath)) {
                unlink($mergedPath);
            }
        }
    }

    /**
     * 依頼AG-1(2026-08-27): 無料診断のレポート(PDF/Word)のダウンロード。
     * 管理者は自分で発行したレポートを取得し直す手段が無かった
     * (lead_sessionsはtoken_hashのみを保存しており、管理者側から生トークンを
     * 復元してリード向けURLを組み立てることはできない・すべきでない ――
     * この設計自体は変更しない)。admin.auth配下のため、リード向け
     * downloadReport()のようなオーナーシップ検証は不要
     * (downloadComparisonReport()と同じ方針)。
     *
     * 多社比較(source_analysis_idが非null)は対象外 ―― 既存の
     * downloadComparisonReport()/comparison-report専用リンクのまま変更しない
     * (このメソッドで404にすることで、同じPDFが2つの異なる導線から
     * 別ファイル名で配信されるような紛らわしい重複を避ける)。
     *
     * ダウンロード時のファイル名は、リード向けdownloadReport()
     * (LeadAnalysisController)と完全に同じ「診断レポート.拡張子」にする ――
     * 管理者と顧客が同じファイル名で会話できるようにする(依頼者指定)。
     */
    public function downloadLeadReport(Analysis $analysis, string $format): StreamedResponse
    {
        abort_if($analysis->source_analysis_id !== null, 404);

        $formatEnum = ReportFormat::tryFrom($format);
        abort_if($formatEnum === null, 404);

        $report = Report::query()
            ->where('analysis_id', $analysis->id)
            ->where('format', $formatEnum->value)
            ->first();

        abort_if($report === null || $report->status !== ReportGenerationStatus::Completed, 404);
        abort_unless(Storage::disk('analysis')->exists($report->storage_path), 404);

        $filename = '診断レポート.'.$formatEnum->fileExtension();

        return Storage::disk('analysis')->download($report->storage_path, $filename, [
            'Content-Type' => $formatEnum->contentType(),
        ]);
    }
}
