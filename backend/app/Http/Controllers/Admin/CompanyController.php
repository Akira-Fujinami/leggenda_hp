<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SalesStatus;
use App\Exceptions\Admin\LeadCompanyDeletionBlockedException;
use App\Http\Controllers\Controller;
use App\Models\LeadCompany;
use App\Models\LeadSession;
use App\Services\Admin\LeadCompanyDeletionService;
use App\Services\Admin\LeadCompanyQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function __construct(
        private readonly LeadCompanyQueryService $companies,
        private readonly LeadCompanyDeletionService $deletions,
    ) {}

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
                'project' => fn ($q) => $q->select('id', 'name', 'lead_session_id')->with(['websites', 'leadSession']),
                'reports',
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

    /**
     * 本番環境にトークン再発行の導線が一切無いため(IssueTestLeadSessionCommandは
     * 非本番限定、routes/admin.phpにも該当エンドポイントが無かった)、営業が
     * 個別の申込(LeadSession)についてanalyses_usedを0へ戻すための導線。
     * リクエストボディに値を持たせず常に0固定にする(任意の数値を入力させない
     * ―― 依頼要件)。誰が・いつ・どのリードに対して実行したかをログに残す
     * (管理画面はADMIN_USERNAME/PASSWORDの共有アカウントのため、個人を
     * 特定できる識別子は無く、IPアドレスのみを合わせて記録する)。
     * 確認ダイアログはBlade側(resources/views/admin/companies/show.blade.php)の
     * onsubmit="return confirm(...)"で表示する。
     */
    /**
     * 依頼CI-2(2026-10-01): 会社単位の物理削除の確認画面。押した瞬間に
     * 消える経路を作らないため、GETで内訳とガードの判定結果だけを見せる
     * (削除の実行は別のdestroy()、DELETEメソッド)。
     */
    public function confirmDelete(LeadCompany $company): View
    {
        return view('admin.companies.delete', [
            'company' => $company,
            'preview' => $this->deletions->preview($company),
        ]);
    }

    /**
     * 依頼CI-2(2026-10-01)必須: サーバー側でもガード・会社名の一致を
     * 必ず再確認する(画面でボタンを隠すだけにしない)。実際の削除・
     * 2つのガード・会社名照合はApp\Services\Admin\LeadCompanyDeletionServiceに
     * 集約している(新しい判定ロジックをここで作らない)。
     */
    public function destroy(Request $request, LeadCompany $company): RedirectResponse
    {
        $data = $request->validate([
            'confirmation_company_name' => ['required', 'string'],
        ]);

        try {
            $this->deletions->destroy($company, $data['confirmation_company_name']);
        } catch (LeadCompanyDeletionBlockedException $e) {
            $reason = $e->preview->hasRunningAnalyses
                ? (string) config('lead_company_deletion.running_analyses_blocked_reason')
                : sprintf(
                    (string) config('lead_company_deletion.shared_session_blocked_reason'),
                    implode('、', array_column($e->preview->blockingCompanies, 'company_name')),
                );

            return redirect()->route('admin.companies.delete', $company->id)->with('status', $reason);
        }

        return redirect()->route('admin.companies.index')->with('status', 'この会社のデータを削除しました。');
    }

    public function resetAnalysesUsed(Request $request, LeadSession $leadSession): RedirectResponse
    {
        $previousAnalysesUsed = $leadSession->analyses_used;

        $leadSession->update(['analyses_used' => 0]);

        Log::warning('Admin reset lead session analyses_used', [
            'lead_session_id' => $leadSession->id,
            'previous_analyses_used' => $previousAnalysesUsed,
            'ip' => $request->ip(),
        ]);

        return back()->with('status', '診断回数をリセットしました。');
    }
}
