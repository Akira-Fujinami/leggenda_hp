<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SalesStatus;
use App\Http\Controllers\Controller;
use App\Models\LeadCompany;
use App\Services\Admin\LeadCompanyQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function __construct(private readonly LeadCompanyQueryService $companies) {}

    public function index(Request $request): View
    {
        $filters = $request->only(['search', 'sales_status', 'diagnosis_status', 're_diagnosed', 'sort', 'direction']);

        return view('admin.companies.index', [
            'companies' => $this->companies->paginate($filters),
            'filters' => $filters,
            'salesStatusOptions' => SalesStatus::options(),
        ]);
    }

    public function show(LeadCompany $company): View
    {
        // 診断履歴(依頼#14・#15)。projects.lead_company_id経由で、この企業に
        // 紐づくanalysesのみを取得する(他企業のデータが混ざらないこと ――
        // 依頼#28のテスト要件)。1診断=1projectのため、自社/競合サイトは
        // project.websitesからis_primaryで判別する。
        $analyses = $company->analyses()
            ->with([
                'project.websites',
                'reports',
                'project' => fn ($q) => $q->select('id', 'name', 'lead_session_id'),
            ])
            ->orderByDesc('created_at')
            ->paginate(10)
            // scheme+hostを含まないパスに固定する(App\Services\Admin\
            // LeadCompanyQueryService::paginate()と同じ理由 ―― Render上での
            // mixed content対策)。
            ->setPath(request()->getPathInfo());

        $analyses->getCollection()->transform(function ($analysis) {
            $analysis->setRelation(
                'brandWheelResults',
                \App\Models\BrandWheelAnalysisResult::query()
                    ->where('analysis_id', $analysis->id)
                    ->orderByDesc('id')
                    ->get()
                    ->unique('website_analysis_id')
            );

            return $analysis;
        });

        return view('admin.companies.show', [
            'company' => $company,
            'analyses' => $analyses,
            'salesStatusOptions' => SalesStatus::options(),
        ]);
    }

    public function updateSalesStatus(Request $request, LeadCompany $company): RedirectResponse
    {
        $data = $request->validate([
            'sales_status' => ['required', 'string', 'in:'.implode(',', array_column(SalesStatus::cases(), 'value'))],
        ]);

        $company->update(['sales_status' => $data['sales_status']]);

        return back()->with('status', '営業ステータスを更新しました。');
    }

    public function updateSalesNote(Request $request, LeadCompany $company): RedirectResponse
    {
        $data = $request->validate([
            'sales_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $company->update(['sales_note' => $data['sales_note'] ?? null]);

        return back()->with('status', '営業メモを保存しました。');
    }
}
