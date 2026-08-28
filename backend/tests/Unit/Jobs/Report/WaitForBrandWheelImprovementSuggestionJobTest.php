<?php

namespace Tests\Unit\Jobs\Report;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 依頼AM-1(2026-08-28): 改善提案が確定するまで、短い間隔でReport生成を
 * 待たせるJob。$tries回まで待ち、確定しないまま上限に達したら改善提案
 * なしで強行する(レポート生成自体は失敗させない)。
 */
class WaitForBrandWheelImprovementSuggestionJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeLeadAnalysis(): Analysis
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create([
            'project_id' => $project->id,
            'status' => AnalysisStatus::Completed,
            'skip_brand_wheel' => false,
        ]);
        $selfWebsite = Website::factory()->create(['project_id' => $project->id, 'is_primary' => true]);
        $selfWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => 'x1'], ['key' => 'business_expansion', 'evidence' => 'x2'],
                ['key' => 'project_initiative', 'evidence' => 'x3'], ['key' => 'social_contribution', 'evidence' => 'x4'],
            ]], ['axis_key' => 'asset', 'matched_sub_elements' => [
                ['key' => 'brand_recognition', 'evidence' => 'x5'], ['key' => 'competitiveness', 'evidence' => 'x6'],
            ]]],
        ]);

        return $analysis;
    }

    public function test_releases_itself_while_the_suggestion_is_still_pending_and_tries_remain(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);

        $job = (new WaitForBrandWheelImprovementSuggestionJob($analysis->id))->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(\App\Services\Lead\LeadReportDispatchService::class));

        $job->assertReleased(15);
        $this->assertSame(0, Report::where('analysis_id', $analysis->id)->count());
    }

    public function test_creates_the_report_once_the_suggestion_settles(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'success']);

        $job = (new WaitForBrandWheelImprovementSuggestionJob($analysis->id))->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(\App\Services\Lead\LeadReportDispatchService::class));

        $job->assertNotReleased();
        $this->assertSame(2, Report::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateLeadReportJob::class, 1);
    }

    /**
     * 上限(tries)に達しても改善提案が確定しない場合、改善提案なしで
     * レポートを強行完成させること(悪化しない ―― 依頼AF-3の代替文言が
     * 出る状態に戻るだけ)。
     */
    public function test_forces_the_report_through_once_tries_are_exhausted(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);

        $job = (new WaitForBrandWheelImprovementSuggestionJob($analysis->id))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;
        $job->handle(app(\App\Services\Lead\LeadReportDispatchService::class));

        $job->assertNotReleased();
        $this->assertSame(2, Report::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateLeadReportJob::class, 1);
    }

    /**
     * 依頼AN-1(2026-08-28): 上限到達は正常系ではない(改善提案が正常に
     * 失敗した場合はsettled=trueとなりこの分岐に来ない)ため、infoではなく
     * warningで記録すること。依頼AN-2の実測判断材料として、実際に
     * 待った秒数(改善提案の行が作られてからの経過秒数)も含める。
     */
    public function test_logs_a_warning_with_the_elapsed_wait_time_once_tries_are_exhausted(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        $suggestion = BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'pending',
        ]);
        // created_atはEloquentのタイムスタンプ自動管理で上書きされるため、
        // factoryのcreate()引数ではなく、作成後に直接更新して過去日時にする。
        $suggestion->forceFill(['created_at' => now()->subSeconds(80)])->save();

        Log::spy();

        $job = (new WaitForBrandWheelImprovementSuggestionJob($analysis->id))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;
        $job->handle(app(\App\Services\Lead\LeadReportDispatchService::class));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($analysis, $job) {
                return str_contains($message, 'Giving up waiting for the brand wheel improvement suggestion')
                    && $context['analysis_id'] === $analysis->id
                    && $context['attempts'] === $job->tries
                    // created_atを80秒前にしたため、経過秒数はおおよそ80秒
                    // (実行時間の誤差を許容して範囲で確認する)。
                    && $context['waited_seconds_since_suggestion_created'] >= 79
                    && $context['waited_seconds_since_suggestion_created'] <= 90;
            })
            ->once();
        Log::shouldNotHaveReceived('info');
    }

    /**
     * 改善提案が確定して(=正常系)強行が不要な場合は、このwarning自体が
     * 出ないこと。
     */
    public function test_does_not_log_a_warning_when_the_suggestion_settles_before_tries_are_exhausted(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'success']);

        Log::spy();

        $job = (new WaitForBrandWheelImprovementSuggestionJob($analysis->id))->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(\App\Services\Lead\LeadReportDispatchService::class));

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * 既に別経路(リードのポーリング等)でReport行が作られていれば
     * 何もしないこと(二重生成防止)。
     */
    public function test_does_nothing_when_a_report_already_exists(): void
    {
        Queue::fake();
        $analysis = $this->makeLeadAnalysis();
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);
        Report::factory()->create(['analysis_id' => $analysis->id, 'format' => 'pdf', 'status' => 'completed']);

        $job = (new WaitForBrandWheelImprovementSuggestionJob($analysis->id))->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(\App\Services\Lead\LeadReportDispatchService::class));

        $job->assertNotReleased();
        Queue::assertNotPushed(GenerateLeadReportJob::class);
    }

    public function test_does_nothing_for_a_deleted_analysis(): void
    {
        Queue::fake();

        $job = (new WaitForBrandWheelImprovementSuggestionJob(999999))->withFakeQueueInteractions();
        $job->handle(app(\App\Services\Lead\LeadReportDispatchService::class));

        $job->assertNotReleased();
        Queue::assertNotPushed(GenerateLeadReportJob::class);
    }
}
