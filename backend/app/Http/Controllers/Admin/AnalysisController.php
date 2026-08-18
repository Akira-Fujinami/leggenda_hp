<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
            ->withQueryString();

        return view('admin.analyses.index', [
            'analyses' => $analyses,
            'status' => $status,
        ]);
    }

    public function show(Analysis $analysis): View
    {
        abort_unless($analysis->project?->lead_company_id !== null, 404);

        $analysis->load(['project.leadCompany', 'project.websites', 'websiteAnalyses.website', 'reports']);

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
}
