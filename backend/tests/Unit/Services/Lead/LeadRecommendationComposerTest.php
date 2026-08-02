<?php

namespace Tests\Unit\Services\Lead;

use App\Enums\RecommendationEffort;
use App\Enums\RecommendationImpact;
use App\Enums\RecommendationPriority;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Recommendation;
use App\Models\WebsiteAnalysis;
use App\Services\Lead\LeadRecommendationComposer;
use App\Support\Lead\LeadMetricCatalog;
use App\Support\Lead\LeadRecommendationCatalog;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * top_recommendations組み立ての唯一の定義元(画面・Word/PDFレポート共通)。
 * 2026-08-03のユーザー指摘3点を直接検証する:
 * 1. 許可リストによる絞り込みはsort_score順の上位N件を取るより先に行う
 *    (逆だと、上位N件が全て対象外のとき0件になる)
 * 2. 表示速度系(FCP/LCP/CLS/TBT)は重複を排除し1件にまとめる
 * 3. 対象外の提案を件数埋めのために混ぜない(絞り込んだ結果0件なら0件のまま)
 */
class LeadRecommendationComposerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
    }

    private function composer(): LeadRecommendationComposer
    {
        return new LeadRecommendationComposer(new LeadRecommendationCatalog(new LeadMetricCatalog));
    }

    private function recommendationFor(string $metricKey, float $sortScore, ?WebsiteAnalysis $websiteAnalysis = null): Recommendation
    {
        $definition = MetricDefinition::query()->where('key', $metricKey)->firstOrFail();
        $websiteAnalysis ??= WebsiteAnalysis::factory()->create();

        $result = MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $definition->id,
        ]);

        return Recommendation::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_result_id' => $result->id,
            'title' => $definition->name,
            'description' => $definition->recommendation_template ?? 'raw technical text',
            'sort_score' => $sortScore,
        ]);
    }

    /**
     * @param  list<Recommendation>  $recommendations
     * @return Collection<int, Recommendation>
     */
    private function withRelationsLoaded(array $recommendations): Collection
    {
        return Recommendation::query()
            ->whereIn('id', array_map(fn (Recommendation $r) => $r->id, $recommendations))
            ->with('metricResult.metricDefinition')
            ->get();
    }

    public function test_an_uncataloged_metric_never_appears_even_if_it_would_otherwise_rank_first(): void
    {
        // analytics_configuredは採用担当の関心事ではない(4観点にも許可
        // リストにも無い)。sort_scoreが他より高くても、上位を絞り込む前に
        // 除外されなければならない。
        $wa = WebsiteAnalysis::factory()->create();
        $offTopic = $this->recommendationFor('analytics_configured', sortScore: 999.0, websiteAnalysis: $wa);
        $allowed = $this->recommendationFor('form_present', sortScore: 1.0, websiteAnalysis: $wa);

        $result = $this->composer()->compose($this->withRelationsLoaded([$offTopic, $allowed]), limit: 3);

        $this->assertCount(1, $result);
        $this->assertSame('form_present', $result[0]['recommendation']->metricResult->metricDefinition->key);
    }

    public function test_returns_an_empty_list_when_nothing_survives_the_allow_list_instead_of_backfilling(): void
    {
        $offTopic = $this->recommendationFor('analytics_configured', sortScore: 10.0);

        $result = $this->composer()->compose($this->withRelationsLoaded([$offTopic]), limit: 3);

        $this->assertSame([], $result);
    }

    public function test_the_four_performance_sub_metrics_collapse_into_one_entry(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $fcp = $this->recommendationFor('fcp', sortScore: 5.0, websiteAnalysis: $wa);
        $lcp = $this->recommendationFor('lcp', sortScore: 9.0, websiteAnalysis: $wa);
        $cls = $this->recommendationFor('cls', sortScore: 3.0, websiteAnalysis: $wa);
        $tbt = $this->recommendationFor('tbt', sortScore: 1.0, websiteAnalysis: $wa);

        $result = $this->composer()->compose($this->withRelationsLoaded([$fcp, $lcp, $cls, $tbt]), limit: 3);

        $this->assertCount(1, $result, 'the 4 performance sub-metrics must collapse into a single entry');
        // 最もsort_scoreが高い代表(lcp)が残る。
        $this->assertSame('lcp', $result[0]['recommendation']->metricResult->metricDefinition->key);
        $this->assertSame((new LeadMetricCatalog)->label('lighthouse_performance'), $result[0]['title']);
    }

    public function test_orders_by_sort_score_descending_after_filtering_and_respects_the_limit(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $low = $this->recommendationFor('form_present', sortScore: 1.0, websiteAnalysis: $wa);
        $high = $this->recommendationFor('title_present', sortScore: 9.0, websiteAnalysis: $wa);
        $mid = $this->recommendationFor('viewport_present', sortScore: 5.0, websiteAnalysis: $wa);

        $result = $this->composer()->compose($this->withRelationsLoaded([$low, $high, $mid]), limit: 2);

        $this->assertCount(2, $result);
        $this->assertSame('title_present', $result[0]['recommendation']->metricResult->metricDefinition->key);
        $this->assertSame('viewport_present', $result[1]['recommendation']->metricResult->metricDefinition->key);
    }

    public function test_an_allowed_key_uses_the_catalog_title_and_description_not_the_raw_recommendation_fields(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $recommendation = $this->recommendationFor('form_present', sortScore: 1.0, websiteAnalysis: $wa);

        $result = $this->composer()->compose($this->withRelationsLoaded([$recommendation]), limit: 3);

        $catalog = new LeadRecommendationCatalog(new LeadMetricCatalog);
        $this->assertSame($catalog->title('form_present'), $result[0]['title']);
        $this->assertSame($catalog->description('form_present'), $result[0]['description']);
        $this->assertNotSame($recommendation->title, $result[0]['title']);
        $this->assertNotSame($recommendation->description, $result[0]['description']);
    }

    public function test_preserves_the_underlying_recommendations_priority_impact_and_effort(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $definition = MetricDefinition::query()->where('key', 'form_present')->firstOrFail();
        $result = MetricResult::factory()->create([
            'website_analysis_id' => $wa->id,
            'metric_definition_id' => $definition->id,
        ]);
        $recommendation = Recommendation::factory()->create([
            'website_analysis_id' => $wa->id,
            'metric_result_id' => $result->id,
            'priority' => RecommendationPriority::Critical,
            'impact' => RecommendationImpact::High,
            'effort' => RecommendationEffort::Small,
            'sort_score' => 1.0,
        ]);

        $composed = $this->composer()->compose($this->withRelationsLoaded([$recommendation]), limit: 3);

        $this->assertSame(RecommendationPriority::Critical, $composed[0]['recommendation']->priority);
        $this->assertSame(RecommendationImpact::High, $composed[0]['recommendation']->impact);
        $this->assertSame(RecommendationEffort::Small, $composed[0]['recommendation']->effort);
    }
}
