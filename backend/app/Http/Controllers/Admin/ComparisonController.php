<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Report\ComparisonSlideInsertionException;
use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Services\Admin\AdminComparisonService;
use App\Services\Admin\AnalysisAttachmentService;
use App\Services\Report\AdminComparisonPptxInserter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * 依頼AB(2026-08-27): 無料診断を起点に、管理画面から自社+競合3〜5社の
 * 比較を実行する。起点は既存の無料診断詳細画面(admin.analyses.show)のみ
 * ―― 独立した起票フォームは作らない(依頼者指定)。
 *
 * 依頼BI(2026-09-08): 起票フォームに、営業資料(PPTX)の添付を追加した。
 * 差し込みが行えない資料(スライドサイズ不一致・参照元ページなし)を
 * 送信時点で弾く ―― 比較(自社+競合3〜5社、それぞれ最大50ページ巡回、
 * 数十分かかる)を実行してから気づく事故を防ぐため(依頼BI-3、主目的)。
 */
class ComparisonController extends Controller
{
    public function __construct(
        private readonly AdminComparisonService $comparisons,
        private readonly AnalysisAttachmentService $attachments,
        private readonly AdminComparisonPptxInserter $pptxInserter,
    ) {}

    /**
     * 比較の起票フォーム。自社URLは起点の無料診断から引き継いで初期値に
     * するが、編集可能にする(依頼者への提案どおり ―― 診断後にサイトの
     * URLが変わっている場合等に対応するため)。競合URLの1件目には、起点の
     * 無料診断で使った競合URLがあれば初期値として入れる(提案どおり)。
     */
    public function create(Analysis $analysis): View
    {
        abort_unless($analysis->project?->lead_company_id !== null, 404);
        abort_if($analysis->source_analysis_id !== null, 404, '比較を起点に、さらに比較を作ることはできません。');

        $analysis->loadMissing(['project.websites', 'project.leadCompany']);

        $selfWebsite = $analysis->project->websites->firstWhere('is_primary', true);
        $existingCompetitorUrl = $analysis->project->websites->firstWhere('is_primary', false)?->url;

        // 依頼BI-1: 20MB・スライドサイズ・検出語は画面に直書きせず、
        // configから出す(依頼者指定)。EMU→cmの換算はここで行い、
        // blade側には計算を持たせない(1EMU=1/360000cm)。
        $requiredCx = (int) config('admin_comparison_pptx.required_slide_width_emu');
        $requiredCy = (int) config('admin_comparison_pptx.required_slide_height_emu');

        return view('admin.comparisons.create', [
            'analysis' => $analysis,
            'selfUrl' => $selfWebsite?->url,
            'existingCompetitorUrl' => $existingCompetitorUrl,
            'minCompetitors' => (int) config('analysis.admin_comparison.min_competitors', 3),
            'maxCompetitors' => (int) config('analysis.admin_comparison.max_competitors', 5),
            'salesDeckMaxSizeMb' => (int) round((int) config('analysis_attachment.max_file_size_bytes') / 1024 / 1024),
            'salesDeckSlideSizeLabel' => sprintf('%.2f × %.2f cm', $requiredCx / 360000, $requiredCy / 360000),
            'salesDeckReferenceKeywords' => (array) config('admin_comparison_pptx.reference_page_keywords'),
        ]);
    }

    public function store(Request $request, Analysis $analysis): RedirectResponse
    {
        abort_unless($analysis->project?->lead_company_id !== null, 404);

        $data = $request->validate([
            'self_url' => ['nullable', 'string', 'max:2048'],
            'competitor_urls' => ['required', 'array'],
            'competitor_urls.*' => ['nullable', 'string', 'max:2048'],
            'competitor_names' => ['nullable', 'array'],
            'competitor_names.*' => ['nullable', 'string', 'max:255'],
            'sales_deck' => ['nullable', 'file'],
        ]);

        // 依頼BI-3(この依頼の主目的、必須の順序): 比較を作成する前に、
        // 添付予定の営業資料を検証する。比較は自社+競合3〜5社をそれぞれ
        // 最大50ページ巡回し、数十分かかる ―― 差し込めない資料のために
        // それを走らせてから気づくのが最悪の出方(依頼者指摘)。
        $salesDeck = $request->file('sales_deck');
        if ($salesDeck !== null) {
            $this->validateSalesDeck($salesDeck);
        }

        $comparison = $this->comparisons->createFromSourceAnalysis(
            $analysis,
            $data['self_url'] ?? null,
            $data['competitor_urls'],
            $data['competitor_names'] ?? [],
        );

        if ($salesDeck !== null) {
            // 依頼BI-2: 添付先は「新しく作られた比較Analysis」(起点の診断
            // ではない) ―― 差し込みの導線は比較側の詳細画面に出るため。
            try {
                $this->attachments->store($comparison, $salesDeck);
            } catch (Throwable $e) {
                report($e);
                // 依頼BI-2: 保存(手順3)に失敗しても、既に走り始めた比較は
                // 止めない。詳細画面の添付欄から再アップロードしてもらう。
                Log::warning('Failed to attach the sales deck after starting a comparison analysis', [
                    'analysis_id' => $comparison->id,
                ]);

                return redirect()
                    ->route('admin.analyses.show', $comparison->id)
                    ->with('status', "比較(診断ID: {$comparison->id})を開始しましたが、資料の添付に失敗しました。この画面の添付欄からもう一度アップロードしてください。");
            }
        }

        return redirect()
            ->route('admin.analyses.show', $comparison->id)
            ->with('status', "比較(診断ID: {$comparison->id})を開始しました。");
    }

    /**
     * 依頼BI-3: 二重実装しない ―― 拡張子・実際の中身(マジックバイト)・
     * サイズは既存のAnalysisAttachmentService::assertBasicUploadIsValid()、
     * スライドサイズ・参照元ページはAdminComparisonPptxInserter::validate()
     * (依頼BG/BHの差し込み判定そのもの)をそのまま呼ぶ。
     *
     * この比較作成フォームはPPTX専用(差し込み専用の欄のため) ―― PDF/DOCXは
     * ここでは拒否する(詳細画面の添付欄は引き続き3拡張子とも受け付ける、
     * 依頼者指定)。
     */
    private function validateSalesDeck(UploadedFile $file): void
    {
        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if ($extension !== 'pptx') {
            throw ValidationException::withMessages([
                'sales_deck' => ['営業資料はPowerPoint(.pptx)のみ添付できます。'],
            ]);
        }

        try {
            // 依頼BI-3: assertBasicUploadIsValid()はAnalysisAttachmentController
            // (フィールド名"file")と共用のため、エラーを常に"file"キーで
            // 投げる。この画面のフィールド名は"sales_deck"のため、詰め替えて
            // 投げ直す(そのままだと画面にエラーが表示されない、依頼者指摘の
            // 「入力が消えたまま原因不明になる」事故そのもの)。
            $this->attachments->assertBasicUploadIsValid($file);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'sales_deck' => $e->errors()['file'] ?? ['営業資料を確認してください。'],
            ]);
        }

        try {
            $this->pptxInserter->validate($file->getRealPath());
        } catch (ComparisonSlideInsertionException $e) {
            throw ValidationException::withMessages(['sales_deck' => [$e->getMessage()]]);
        }
    }
}
