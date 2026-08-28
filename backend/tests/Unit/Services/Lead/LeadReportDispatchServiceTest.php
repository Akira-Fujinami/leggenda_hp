<?php

namespace Tests\Unit\Services\Lead;

use App\Enums\AnalysisStatus;
use App\Jobs\Report\GenerateLeadReportJob;
use App\Jobs\Report\WaitForBrandWheelImprovementSuggestionJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Lead\LeadReportDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 依頼AM-1(2026-08-28、本番analysis_id=110): GenerateLeadReportJobは
 * ViewModelを1回だけ組み立てるため、改善提案(BrandWheelImprovementSuggestion)
 * がまだ確定していない状態でReport行を作成・GenerateLeadReportJobを起動して
 * しまうと、理由・中長期の差別化ポイントが入らないレポートが完成する
 * (依頼AJ-1で改善提案の起動条件を正しく遅らせた結果、診断終端との時間差が
 * 縮まりこのレースが表面化した)。
 */
class LeadReportDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLeadAnalysis(bool $skipBrandWheel = false): Analysis
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create([
            'project_id' => $project->id,
            'status' => AnalysisStatus::Completed,
            'skip_brand_wheel' => $skipBrandWheel,
        ]);
        $selfWebsite = Website::factory()->create(['project_id' => $project->id, 'is_primary' => true]);
        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);

        return $analysis;
    }

    private function addCompetitor(Analysis $analysis): WebsiteAnalysis
    {
        $competitorWebsite = Website::factory()->create(['project_id' => $analysis->project_id, 'is_primary' => false]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);
    }

    private function selfWebsiteAnalysis(Analysis $analysis): WebsiteAnalysis
    {
        return WebsiteAnalysis::query()
            ->where('analysis_id', $analysis->id)
            ->whereHas('website', fn ($q) => $q->where('is_primary', true))
            ->firstOrFail();
    }

    private function makeReportableSelfBrandWheelResult(Analysis $analysis): void
    {
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $this->selfWebsiteAnalysis($analysis)->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => 'x1'], ['key' => 'business_expansion', 'evidence' => 'x2'],
                ['key' => 'project_initiative', 'evidence' => 'x3'], ['key' => 'social_contribution', 'evidence' => 'x4'],
            ]], ['axis_key' => 'asset', 'matched_sub_elements' => [
                ['key' => 'brand_recognition', 'evidence' => 'x5'], ['key' => 'competitiveness', 'evidence' => 'x6'],
            ]]],
        ]);
    }

    // ------------------------------------------------------------------
    // isBrandWheelImprovementSuggestionSettled()
    // ------------------------------------------------------------------

    public function test_settled_is_true_when_skip_brand_wheel_is_true(): void
    {
        $analysis = $this->makeLeadAnalysis(skipBrandWheel: true);

        $this->assertTrue(app(LeadReportDispatchService::class)->isBrandWheelImprovementSuggestionSettled($analysis));
    }

    public function test_settled_is_false_when_a_brand_wheel_analysis_result_row_is_missing(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->addCompetitor($analysis);
        $this->makeReportableSelfBrandWheelResult($analysis);
        // 競合のBrandWheelAnalysisResultは意図的に作らない。

        $this->assertFalse(app(LeadReportDispatchService::class)->isBrandWheelImprovementSuggestionSettled($analysis));
    }

    public function test_settled_is_true_when_all_rows_exist_and_no_suggestion_was_created(): void
    {
        $analysis = $this->makeLeadAnalysis();
        // 競合なし、自社のBrandWheelAnalysisResultも読み取れない
        // (insufficient_input) ―― dispatchIfReady()が生成不要と判定済みの状態。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $this->selfWebsiteAnalysis($analysis)->id,
            'status' => 'insufficient_input',
            'axes' => null,
        ]);

        $this->assertTrue(app(LeadReportDispatchService::class)->isBrandWheelImprovementSuggestionSettled($analysis));
    }

    public function test_settled_is_false_while_the_suggestion_is_pending(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);

        $this->assertFalse(app(LeadReportDispatchService::class)->isBrandWheelImprovementSuggestionSettled($analysis));
    }

    public function test_settled_is_true_when_the_suggestion_succeeded(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'success']);

        $this->assertTrue(app(LeadReportDispatchService::class)->isBrandWheelImprovementSuggestionSettled($analysis));
    }

    public function test_settled_is_true_when_the_suggestion_errored(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'error']);

        $this->assertTrue(app(LeadReportDispatchService::class)->isBrandWheelImprovementSuggestionSettled($analysis));
    }

    // ------------------------------------------------------------------
    // createPendingReportsAndDispatch() / dispatchIfReportable()
    // ------------------------------------------------------------------

    /**
     * 改善提案がpendingの間は、Report行を作らずWaitForBrandWheelImprovementSuggestionJob
     * へ委ねること(ViewModelがまだ組み立てられないこと、の裏返し ――
     * Report行が無ければGenerateLeadReportJobはdispatchされない)。
     */
    public function test_does_not_create_reports_while_the_suggestion_is_pending(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);

        app(LeadReportDispatchService::class)->dispatchIfReportable($analysis);

        $this->assertSame(0, Report::where('analysis_id', $analysis->id)->count());
        Queue::assertNotPushed(GenerateLeadReportJob::class);
        Queue::assertPushed(WaitForBrandWheelImprovementSuggestionJob::class, 1);
    }

    /**
     * 改善提案が終端に達したあと、理由・中長期を含んだレポートが1本だけ
     * 作られること(Report行・GenerateLeadReportJobのdispatchが1回だけ)。
     */
    public function test_creates_reports_once_the_suggestion_settles(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'success']);

        app(LeadReportDispatchService::class)->dispatchIfReportable($analysis);

        $this->assertSame(2, Report::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateLeadReportJob::class, 1);
        Queue::assertNotPushed(WaitForBrandWheelImprovementSuggestionJob::class);
    }

    /**
     * 改善提案が失敗(status='error')した場合、上限を待たずにレポートが
     * 完成すること(errorも「終端状態」として扱われる)。
     */
    public function test_creates_reports_immediately_when_the_suggestion_failed(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'error']);

        app(LeadReportDispatchService::class)->dispatchIfReportable($analysis);

        $this->assertSame(2, Report::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateLeadReportJob::class, 1);
    }

    /**
     * 改善提案の行がそもそも作られない診断(自社が読み取れない)で、
     * 待たずに従来どおりレポートが作られること。
     */
    public function test_creates_reports_without_waiting_when_self_is_not_readable(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        // 自社が読み取れない場合、eligibility自体がfalseになりReport(Skipped)は
        // このサービスからは作られない(クラスdocblock参照、コントローラ側の
        // 責務)。ここではeligibilityがtrueになる最小のケース(閾値未満でも
        // isReportable自体は別ルール)ではなく、「行はそろっているが
        // 改善提案が無い」ケースをsettled判定の観点から確認する。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $this->selfWebsiteAnalysis($analysis)->id,
            'status' => 'insufficient_input',
            'axes' => null,
        ]);

        app(LeadReportDispatchService::class)->dispatchIfReportable($analysis);

        // eligibility=falseのため、そもそもこのサービスからはReportを
        // 作らない(待たされてもいない ―― WaitForBrandWheelImprovementSuggestionJobも
        // dispatchされない)。
        Queue::assertNotPushed(WaitForBrandWheelImprovementSuggestionJob::class);
        Queue::assertNotPushed(GenerateLeadReportJob::class);
    }

    /**
     * skip_brand_wheel=trueの診断で、待たずに従来どおりレポートが
     * 作られること。
     */
    public function test_creates_reports_without_waiting_when_brand_wheel_is_skipped(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis(skipBrandWheel: true);
        $this->makeReportableSelfBrandWheelResult($analysis);

        app(LeadReportDispatchService::class)->dispatchIfReportable($analysis);

        $this->assertSame(2, Report::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateLeadReportJob::class, 1);
        Queue::assertNotPushed(WaitForBrandWheelImprovementSuggestionJob::class);
    }

    /**
     * forceWithoutImprovementSuggestion=trueのとき、settled=falseでも
     * 待たずにレポートが完成すること(上限到達時の強行、悪化しないこと)。
     */
    public function test_force_flag_creates_reports_without_waiting_even_when_not_settled(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);

        app(LeadReportDispatchService::class)->createPendingReportsAndDispatch($analysis, forceWithoutImprovementSuggestion: true);

        $this->assertSame(2, Report::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateLeadReportJob::class, 1);
    }

    /**
     * レポートが二重に作られないこと ―― 既にReport行がある場合は
     * settled判定の前に早期returnする(dispatchIfReportable()自体の
     * 既存ガード、無改修)。
     */
    public function test_does_not_create_duplicate_reports_when_a_report_already_exists(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        $this->makeReportableSelfBrandWheelResult($analysis);
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);
        Report::factory()->create(['analysis_id' => $analysis->id, 'format' => 'pdf', 'status' => 'completed']);

        app(LeadReportDispatchService::class)->dispatchIfReportable($analysis);

        Queue::assertNotPushed(WaitForBrandWheelImprovementSuggestionJob::class);
        Queue::assertNotPushed(GenerateLeadReportJob::class);
    }
}
