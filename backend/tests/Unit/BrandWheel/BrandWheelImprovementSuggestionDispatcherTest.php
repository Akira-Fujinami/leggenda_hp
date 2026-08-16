<?php

namespace Tests\Unit\BrandWheel;

use App\Jobs\GenerateBrandWheelImprovementSuggestionJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelImprovementSuggestionDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 改善提案(page6)AIの生成タイミング判定。BrandWheelCompletionNotifierと同じ
 * 「side-effectとしてJobから呼ばれる」パターンだが、判定対象は「Analysisに
 * 紐づく全BrandWheelAnalysisResult(自社・競合)が終端状態に達したか」。
 */
class BrandWheelImprovementSuggestionDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnalysisWithWebsites(bool $withCompetitor = true): Analysis
    {
        $project = Project::factory()->create();
        $selfWebsite = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create();

        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);

        if ($withCompetitor) {
            $competitorWebsite = Website::factory()->for($project)->create(['is_primary' => false]);
            WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);
        }

        return $analysis;
    }

    public function test_does_not_dispatch_while_any_side_is_still_pending(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa, $competitorWa] = $analysis->websiteAnalyses()->get();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);
        // 競合側は実運用と同じく、診断実行時のfan-outでstatus='pending'の行が
        // 先に作られている(AnalysisPipeline::dispatchWebsiteFanOut())。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $competitorWa->id, 'status' => 'pending', 'axes' => null,
        ]);

        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        Queue::assertNothingPushed();
        $this->assertSame(0, BrandWheelImprovementSuggestion::count());
    }

    public function test_dispatches_once_both_sides_reach_a_terminal_status(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa, $competitorWa] = $analysis->websiteAnalyses()->get();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $competitorWa->id, 'status' => 'error',
        ]);

        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        $this->assertSame(1, BrandWheelImprovementSuggestion::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateBrandWheelImprovementSuggestionJob::class);
    }

    public function test_does_not_dispatch_when_self_is_not_readable(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites(withCompetitor: false);
        $selfWa = $analysis->websiteAnalyses()->first();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'insufficient_input', 'axes' => null,
        ]);

        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        Queue::assertNothingPushed();
        $this->assertSame(0, BrandWheelImprovementSuggestion::count());
    }

    public function test_is_idempotent_when_called_more_than_once(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites(withCompetitor: false);
        $selfWa = $analysis->websiteAnalyses()->first();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        $dispatcher = app(BrandWheelImprovementSuggestionDispatcher::class);
        $dispatcher->dispatchIfReady($analysis->id);
        $dispatcher->dispatchIfReady($analysis->id);

        $this->assertSame(1, BrandWheelImprovementSuggestion::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateBrandWheelImprovementSuggestionJob::class, 1);
    }
}
