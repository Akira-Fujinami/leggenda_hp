<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\WebsiteAnalysisStatus;
use App\Jobs\Analysis\FinalizeAnalysisJob;
use App\Jobs\Report\GenerateAdminComparisonReportJob;
use App\Jobs\Report\GenerateLeadReportJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadCompany;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 依頼Y-3(2026-08-26): 診断が completed/partial に到達した時点(パイプライン
 * 側の終端処理、FinalizeAnalysisJob)からリード向けレポート生成を起動する。
 * 起動条件・Report行の重複作成防止はLeadReportDispatchServiceへ集約し、
 * LeadAnalysisController::maybeDispatchReportGeneration()と共有する
 * (このテストではJobを直接handle()して検証する、既存のFinalizeAnalysisJob
 * には専用テストが無かったため新設)。
 */
class FinalizeAnalysisJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeLeadAnalysis(WebsiteAnalysisStatus $selfStatus = WebsiteAnalysisStatus::Completed): Analysis
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Running]);
        $website = Website::factory()->create(['project_id' => $project->id, 'is_primary' => true]);
        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => $selfStatus]);

        return $analysis;
    }

    private function makeReportableSelfBrandWheelResult(Analysis $analysis): void
    {
        $selfWebsiteAnalysis = WebsiteAnalysis::query()
            ->where('analysis_id', $analysis->id)
            ->whereHas('website', fn ($q) => $q->where('is_primary', true))
            ->firstOrFail();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWebsiteAnalysis->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => 'x1'], ['key' => 'business_expansion', 'evidence' => 'x2'],
                ['key' => 'project_initiative', 'evidence' => 'x3'], ['key' => 'social_contribution', 'evidence' => 'x4'],
            ]], ['axis_key' => 'asset', 'matched_sub_elements' => [
                ['key' => 'brand_recognition', 'evidence' => 'x5'], ['key' => 'competitiveness', 'evidence' => 'x6'],
            ]]],
        ]);
    }

    public function test_it_dispatches_report_generation_once_the_analysis_becomes_completed(): void
    {
        Queue::fake([GenerateLeadReportJob::class]);
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);

        (new FinalizeAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(AnalysisStatus::Completed, $analysis->fresh()->status);
        Queue::assertPushed(GenerateLeadReportJob::class, 1);
        $this->assertSame(2, Report::where('analysis_id', $analysis->id)->count());
        $this->assertTrue(Report::where('analysis_id', $analysis->id)->where('status', 'pending')->exists());
    }

    public function test_it_also_dispatches_for_a_partial_analysis(): void
    {
        Queue::fake([GenerateLeadReportJob::class]);
        $analysis = $this->makeLeadAnalysis(WebsiteAnalysisStatus::Partial);
        $this->makeReportableSelfBrandWheelResult($analysis);

        (new FinalizeAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(AnalysisStatus::Partial, $analysis->fresh()->status);
        Queue::assertPushed(GenerateLeadReportJob::class, 1);
        $this->assertSame(2, Report::where('analysis_id', $analysis->id)->count());
    }

    /**
     * 情報量が閾値未満(BrandWheelReportEligibility::isReportable()=false)の
     * ときは、パイプライン側からはReport行を作成せず、GenerateLeadReportJobも
     * 起動しない ―― 見送り通知(生トークンが必要)はLeadAnalysisController側の
     * ポーリング経由でのみ送るため(LeadReportDispatchServiceのdocblock参照)。
     */
    public function test_it_does_not_create_report_rows_when_not_reportable(): void
    {
        Queue::fake([GenerateLeadReportJob::class]);
        $analysis = $this->makeLeadAnalysis();
        // makeReportableSelfBrandWheelResult()を呼ばない = BrandWheelAnalysisResultが無い
        // (isReportable()はnullを渡されfalseになる)。

        (new FinalizeAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(AnalysisStatus::Completed, $analysis->fresh()->status);
        Queue::assertNotPushed(GenerateLeadReportJob::class);
        $this->assertSame(0, Report::where('analysis_id', $analysis->id)->count());
    }

    /**
     * 内部向け分析(LeadSessionを持たないProject)は対象外 ―― リード診断
     * 以外からレポートが生成されないことを確認する。
     */
    public function test_it_does_nothing_for_an_analysis_without_a_lead_session(): void
    {
        Queue::fake([GenerateLeadReportJob::class]);

        $project = Project::factory()->create();
        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Running]);
        $website = Website::factory()->create(['project_id' => $project->id, 'is_primary' => true]);
        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => WebsiteAnalysisStatus::Completed]);

        (new FinalizeAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(AnalysisStatus::Completed, $analysis->fresh()->status);
        Queue::assertNotPushed(GenerateLeadReportJob::class);
        $this->assertSame(0, Report::where('analysis_id', $analysis->id)->count());
    }

    /**
     * コントローラ側の呼び出しを安全網として残しているため、パイプライン側が
     * 既にReport行を作成した後に、コントローラ側の判定(直接呼び出しで代用)が
     * 走っても二重に作成・二重にdispatchされないことを確認する
     * (Report::exists()による重複防止、Y-3の要件)。
     */
    public function test_report_rows_are_not_duplicated_when_the_controller_side_check_runs_afterward(): void
    {
        Queue::fake([GenerateLeadReportJob::class]);
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);

        (new FinalizeAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));
        // コントローラのmaybeDispatchReportGeneration()と同じ「reportable時の
        // 実装」を、安全網としてもう一度呼ぶ(exists()チェックで何もしないはず)。
        app(\App\Services\Lead\LeadReportDispatchService::class)->dispatchIfReportable($analysis->fresh());

        Queue::assertPushed(GenerateLeadReportJob::class, 1);
        $this->assertSame(2, Report::where('analysis_id', $analysis->id)->count());
    }

    // ------------------------------------------------------------------
    // 依頼AC(2026-08-27): 管理者起点の多社比較(source_analysis_idが非null)
    // がcompleted/partialに到達したときの、GenerateAdminComparisonReportJob
    // ディスパッチ。
    // ------------------------------------------------------------------

    private function makeComparisonAnalysis(WebsiteAnalysisStatus $selfStatus = WebsiteAnalysisStatus::Completed): Analysis
    {
        $company = LeadCompany::factory()->create();
        $sourceProject = new Project(['name' => '起点']);
        $sourceProject->user_id = User::factory()->create()->id;
        $sourceProject->lead_company_id = $company->id;
        $sourceProject->save();
        $sourceAnalysis = Analysis::factory()->create(['project_id' => $sourceProject->id, 'status' => AnalysisStatus::Completed]);

        $project = new Project(['name' => '比較']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();

        $analysis = Analysis::factory()->create([
            'project_id' => $project->id,
            'status' => AnalysisStatus::Running,
            'source_analysis_id' => $sourceAnalysis->id,
        ]);
        $website = Website::factory()->create(['project_id' => $project->id, 'is_primary' => true]);
        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => $selfStatus]);

        return $analysis;
    }

    public function test_it_dispatches_admin_comparison_report_generation_for_a_completed_comparison_analysis(): void
    {
        Queue::fake([GenerateAdminComparisonReportJob::class, GenerateLeadReportJob::class]);
        $analysis = $this->makeComparisonAnalysis();

        (new FinalizeAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(AnalysisStatus::Completed, $analysis->fresh()->status);
        Queue::assertPushed(GenerateAdminComparisonReportJob::class, 1);
        // 依頼AE(2026-08-27): このJobにキュー指定が一切無く、ワーカーが
        // 監視しない`default`キューへ積まれて永久に実行されなかった事故の
        // 再発確認。`reports`キューに載ることを明示的に検証する。
        Queue::assertPushedOn('reports', GenerateAdminComparisonReportJob::class);
        // 比較Analysis(lead_session_idを持たないProject)からはリード向け
        // レポートは一切起動しない ―― 完全に別経路であることの確認。
        Queue::assertNotPushed(GenerateLeadReportJob::class);
    }

    public function test_it_also_dispatches_admin_comparison_report_for_a_partial_comparison_analysis(): void
    {
        Queue::fake([GenerateAdminComparisonReportJob::class]);
        $analysis = $this->makeComparisonAnalysis(WebsiteAnalysisStatus::Partial);

        (new FinalizeAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(AnalysisStatus::Partial, $analysis->fresh()->status);
        Queue::assertPushed(GenerateAdminComparisonReportJob::class, 1);
    }

    /**
     * 通常のリード診断(source_analysis_idがnull)からは、多社比較レポートの
     * Jobは一切起動しない。
     */
    public function test_it_does_not_dispatch_admin_comparison_report_for_a_regular_lead_analysis(): void
    {
        Queue::fake([GenerateAdminComparisonReportJob::class, GenerateLeadReportJob::class]);
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);

        (new FinalizeAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertNotPushed(GenerateAdminComparisonReportJob::class);
    }
}
