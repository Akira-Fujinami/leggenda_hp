<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Services\Admin\AdminComparisonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 依頼AB(2026-08-27): 無料診断を起点に、管理画面から自社+競合3〜5社の
 * 比較を実行する。起点は既存の無料診断詳細画面(admin.analyses.show)のみ
 * ―― 独立した起票フォームは作らない(依頼者指定)。
 */
class ComparisonController extends Controller
{
    public function __construct(private readonly AdminComparisonService $comparisons) {}

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

        return view('admin.comparisons.create', [
            'analysis' => $analysis,
            'selfUrl' => $selfWebsite?->url,
            'existingCompetitorUrl' => $existingCompetitorUrl,
            'minCompetitors' => (int) config('analysis.admin_comparison.min_competitors', 3),
            'maxCompetitors' => (int) config('analysis.admin_comparison.max_competitors', 5),
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
        ]);

        $comparison = $this->comparisons->createFromSourceAnalysis(
            $analysis,
            $data['self_url'] ?? null,
            $data['competitor_urls'],
            $data['competitor_names'] ?? [],
        );

        return redirect()
            ->route('admin.analyses.show', $comparison->id)
            ->with('status', "比較(診断ID: {$comparison->id})を開始しました。");
    }
}
