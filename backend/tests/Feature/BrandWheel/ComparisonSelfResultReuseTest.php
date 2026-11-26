<?php

namespace Tests\Feature\BrandWheel;

use App\Enums\AnalysisKind;
use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼DU: 無料診断を起点にした3〜5社比較で、自社サイトの判定結果を起点の無料診断からそのまま写す。
 */
class ComparisonSelfResultReuseTest extends TestCase
{
    use RefreshDatabase;

    private array $sourceAxes = [['key' => 'activity', 'name' => '活動的魅力', 'state' => 'read', 'sub_elements' => ['purpose' => ['matched' => true, 'evidence' => '起点の根拠']]]];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        // 写さなかったときは従来どおり判定する(ここでは材料が無いので insufficient_input になる)。写したかどうかは provider で見分ける。
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
    }

    private function site(Analysis $analysis, string $url, bool $primary): WebsiteAnalysis
    {
        $website = Website::factory()->for($analysis->project)->create(['is_primary' => $primary, 'url' => $url, 'normalized_url' => $url]);

        return WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    /** @return array{0: WebsiteAnalysis, 1: WebsiteAnalysis, 2: BrandWheelAnalysisResult} 比較の自社・比較の競合・起点の自社の結果 */
    private function setUpComparison(string $comparisonSelfUrl = 'https://self.example.co.jp/recruit/', string $sourceStatus = 'success'): array
    {
        $sourceAnalysis = Analysis::factory()->for(Project::factory())->completed()->create();
        $sourceSelf = $this->site($sourceAnalysis, 'https://self.example.co.jp/recruit/', true);
        $sourceResult = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $sourceAnalysis->id,
            'website_analysis_id' => $sourceSelf->id,
            'status' => $sourceStatus,
            'provider' => 'openai',
            'model' => 'source-model',
            'axes' => $this->sourceAxes,
            'key_message' => '起点のキーメッセージ',
            'axis_state_counts' => ['read' => 1, 'partial' => 0, 'unread' => 5],
            'input_hash' => 'source-hash',
            'generated_at' => now()->subDay(),
        ]);

        $comparison = Analysis::factory()->for(Project::factory())->create(['kind' => AnalysisKind::AdminComparison, 'source_analysis_id' => $sourceAnalysis->id]);
        $self = $this->site($comparison, $comparisonSelfUrl, true);
        $rival = $this->site($comparison, 'https://rival.example.co.jp/recruit/', false);

        return [$self, $rival, $sourceResult];
    }

    private function judge(WebsiteAnalysis $websiteAnalysis): BrandWheelAnalysisResult
    {
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        return $record->fresh();
    }

    public function test_the_comparison_self_site_gets_the_same_result_as_the_source_diagnosis(): void
    {
        [$self, , $source] = $this->setUpComparison();

        $result = $this->judge($self);

        $this->assertSame('success', $result->status);
        $this->assertSame('openai', $result->provider, 'AIを呼ばずに写した');
        $this->assertSame($this->sourceAxes, $result->axes);
        $this->assertSame('起点のキーメッセージ', $result->key_message);
        $this->assertSame(['read' => 1, 'partial' => 0, 'unread' => 5], $result->axis_state_counts);
        $this->assertSame(0, $result->usage_input_tokens);
        $this->assertSame('起点のキーメッセージ', $source->fresh()->key_message, '起点の結果は変えない');
    }

    public function test_the_competitor_sites_are_judged_as_before(): void
    {
        [, $rival] = $this->setUpComparison();

        $this->assertNotSame('openai', $this->judge($rival)->provider, '写さずに従来どおり判定した');
    }

    public function test_it_is_not_copied_when_the_self_url_was_changed_for_the_comparison(): void
    {
        [$self] = $this->setUpComparison('https://self.example.co.jp/careers/');

        $this->assertNotSame('openai', $this->judge($self)->provider, '写さずに従来どおり判定した');
    }

    public function test_it_is_not_copied_when_the_source_judgement_did_not_succeed(): void
    {
        [$self] = $this->setUpComparison(sourceStatus: 'error');

        $this->assertNotSame('openai', $this->judge($self)->provider, '写さずに従来どおり判定した');
    }

    public function test_it_can_be_turned_off(): void
    {
        config(['brand_wheel.comparison_reuse_source_self_result_enabled' => false]);
        [$self] = $this->setUpComparison();

        $this->assertNotSame('openai', $this->judge($self)->provider, '写さずに従来どおり判定した');
    }
}
