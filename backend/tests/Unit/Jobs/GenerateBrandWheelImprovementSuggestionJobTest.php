<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateBrandWheelImprovementSuggestionJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BRAND_WHEEL_AI_PROVIDERは既定でmock(phpunit.xmlのALLOW_MOCK_PROVIDERS=true)
 * のため、ここでは実際のOpenAI呼び出しは行わない ―― Provider解決・入力組み立て・
 * 結果の永続化という配線が正しいことを検証する(プロンプトの実際の判定品質は
 * OpenAiBrandWheelImprovementSuggestionProviderTest/実PDF確認で別途検証する)。
 */
class GenerateBrandWheelImprovementSuggestionJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuggestion(bool $withCompetitor = true): BrandWheelImprovementSuggestion
    {
        $project = Project::factory()->create();
        $selfWebsite = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create();
        $selfWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        if ($withCompetitor) {
            $competitorWebsite = Website::factory()->for($project)->create(['is_primary' => false]);
            $competitorWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);
            BrandWheelAnalysisResult::factory()->create([
                'analysis_id' => $analysis->id,
                'website_analysis_id' => $competitorWa->id,
                'status' => 'success',
                'axes' => [['axis_key' => 'relationship', 'matched_sub_elements' => [['key' => 'colleagues', 'evidence' => 'y']]]],
            ]);
        }

        return BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending', 'one_point' => null, 'recommendation' => null]);
    }

    public function test_generates_a_suggestion_using_the_mock_provider(): void
    {
        $suggestion = $this->makeSuggestion();

        (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->handle(
            app(\App\Services\BrandWheel\BrandWheelLeadResponseComposer::class),
            app(\App\Services\BrandWheel\BrandWheelSubElementComparisonComposer::class),
            app(\App\Services\BrandWheel\BrandWheelImprovementSuggestionInputFactory::class),
            app(\App\Services\BrandWheel\BrandWheelEvidenceLookupBuilder::class),
        );

        $suggestion->refresh();

        $this->assertSame('success', $suggestion->status);
        $this->assertSame('mock', $suggestion->provider);
        $this->assertTrue($suggestion->is_mock);
        $this->assertNotNull($suggestion->one_point);
        $this->assertNotNull($suggestion->generated_at);
    }

    public function test_marks_as_error_when_self_is_not_readable(): void
    {
        $project = Project::factory()->create();
        $selfWebsite = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create();
        $selfWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'insufficient_input', 'axes' => null,
        ]);
        $suggestion = BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);

        (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->handle(
            app(\App\Services\BrandWheel\BrandWheelLeadResponseComposer::class),
            app(\App\Services\BrandWheel\BrandWheelSubElementComparisonComposer::class),
            app(\App\Services\BrandWheel\BrandWheelImprovementSuggestionInputFactory::class),
            app(\App\Services\BrandWheel\BrandWheelEvidenceLookupBuilder::class),
        );

        $suggestion->refresh();

        $this->assertSame('error', $suggestion->status);
        $this->assertSame('SELF_NOT_READABLE', $suggestion->error_code);
    }

    public function test_returns_early_when_the_suggestion_record_no_longer_exists(): void
    {
        // find()がnullを返すケース(レコード削除済み等)でも例外を投げない。
        $job = new GenerateBrandWheelImprovementSuggestionJob(999999);

        $job->handle(
            app(\App\Services\BrandWheel\BrandWheelLeadResponseComposer::class),
            app(\App\Services\BrandWheel\BrandWheelSubElementComparisonComposer::class),
            app(\App\Services\BrandWheel\BrandWheelImprovementSuggestionInputFactory::class),
            app(\App\Services\BrandWheel\BrandWheelEvidenceLookupBuilder::class),
        );

        $this->assertTrue(true);
    }
}
