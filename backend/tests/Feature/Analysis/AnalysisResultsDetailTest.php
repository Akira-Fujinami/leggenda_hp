<?php

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\MetricResultStatus;
use App\Models\Analysis;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 結果画面の詳細化(サマリー・優先改善項目・カテゴリ別カード・SEO/コンテンツ/
 * 集客/表示速度/技術/外部SEO詳細)のためにAnalysisResultsResourceへ追加した
 * metrics/recommendationsフィールドの回帰テスト。
 */
class AnalysisResultsDetailTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(User $user): WebsiteAnalysis
    {
        $project = Project::factory()->for($user)->create();
        $website = Website::factory()->for($project)->create();
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Partial]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => 'partial', 'progress' => 100]);
    }

    public function test_results_includes_a_full_metric_list_with_definition_context(): void
    {
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);

        CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'title_length_optimal', 'name' => 'title文字数', 'category_key' => 'technical_seo',
            'scoring_type' => 'range', 'unit' => 'chars', 'minimum_value' => 10, 'maximum_value' => 65,
        ]);
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => 50],
            'raw_value' => ['text' => 'サンプルタイトル', 'length' => 50], 'confidence' => 1.0,
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$websiteAnalysis->analysis_id}/results");

        $response->assertOk();
        $metric = collect($response->json('data.websites.0.metrics'))->firstWhere('key', 'title_length_optimal');
        $this->assertNotNull($metric);
        $this->assertSame('title文字数', $metric['name']);
        $this->assertSame('technical_seo', $metric['category_key']);
        $this->assertSame(50, $metric['value']);
        $this->assertEquals(10.0, $metric['min_value']);
        $this->assertEquals(65.0, $metric['max_value']);
        $this->assertSame('サンプルタイトル', $metric['raw_value']['text']);
    }

    public function test_unavailable_metric_reports_null_value_not_zero(): void
    {
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);

        CategoryDefinition::factory()->create(['key' => 'performance', 'weight' => 15]);
        $definition = MetricDefinition::factory()->create(['key' => 'lighthouse_performance', 'category_key' => 'performance']);
        MetricResult::factory()->unavailable()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'error_code' => 'ANALYZER_LIGHTHOUSE_FAILED', 'error_message' => 'Lighthouse計測に失敗しました。',
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$websiteAnalysis->analysis_id}/results");

        $metric = collect($response->json('data.websites.0.metrics'))->firstWhere('key', 'lighthouse_performance');
        $this->assertSame('unavailable', $metric['status']);
        $this->assertNull($metric['value']);
        $this->assertSame('ANALYZER_LIGHTHOUSE_FAILED', $metric['error_code']);
        // 採点対象から除外されるため、score/max_scoreはnullのまま(0点にしない)。
        $this->assertFalse($metric['counts_toward_score']);
        $this->assertNull($metric['score']);
        $this->assertNull($metric['max_score']);
    }

    public function test_category_with_no_measurable_metrics_reports_zero_max_available_not_a_fabricated_score(): void
    {
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);

        CategoryDefinition::factory()->create(['key' => 'authority', 'weight' => 15]);
        $definition = MetricDefinition::factory()->create(['key' => 'authority_score', 'category_key' => 'authority', 'scoring_type' => 'threshold']);
        MetricResult::factory()->unavailable()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id, 'error_code' => 'SEMRUSH_NOT_CONFIGURED',
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$websiteAnalysis->analysis_id}/results");

        $category = collect($response->json('data.websites.0.score.category_scores'))->firstWhere('key', 'authority');
        $this->assertEquals(0.0, $category['score']);
        $this->assertEquals(0.0, $category['max_available_score']);
        $this->assertEquals(15.0, $category['configured_max_score']);
    }

    public function test_recommendations_are_embedded_sorted_by_priority_with_evidence(): void
    {
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);

        Recommendation::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'title' => '低優先度の提案', 'sort_score' => 10,
            'evidence' => ['found' => false],
        ]);
        Recommendation::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'title' => '高優先度の提案', 'sort_score' => 90,
            'evidence' => ['count' => 3], 'current_value' => ['count' => 0], 'recommended_value' => ['count' => 1],
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$websiteAnalysis->analysis_id}/results");

        $recommendations = $response->json('data.websites.0.recommendations');
        $this->assertCount(2, $recommendations);
        $this->assertSame('高優先度の提案', $recommendations[0]['title']);
        $this->assertSame(3, $recommendations[0]['evidence']['count']);
        $this->assertSame(0, $recommendations[0]['current_value']['count']);
        $this->assertSame(1, $recommendations[0]['recommended_value']['count']);
    }

    public function test_response_never_includes_raw_html_or_lighthouse_json_despite_richer_metrics(): void
    {
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);

        CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create(['key' => 'title_present', 'category_key' => 'technical_seo']);
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true],
            'raw_value' => ['present' => true, 'length' => 10],
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$websiteAnalysis->analysis_id}/results");

        $raw = $response->getContent();
        $this->assertStringNotContainsString('<html', $raw);
        $this->assertStringNotContainsString('lighthouseResult', $raw);
    }
}
