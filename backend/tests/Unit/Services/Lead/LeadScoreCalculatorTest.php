<?php

namespace Tests\Unit\Services\Lead;

use App\Enums\MetricResultStatus;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\WebsiteAnalysis;
use App\Services\Lead\LeadScoreCalculator;
use App\Support\Lead\LeadMetricCatalog;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 2026-07-28のユーザー指摘(総合点が非表示の約35項目も含めて算出されるため、
 * 4観点表示と数値が食い違う/Semrush未設定で85点上限になる)への対応の回帰テスト。
 * LeadScoreCalculatorは、LeadMetricCatalogに登録されている指標(4観点の
 * いずれかにマッピングされている項目)だけを対象にscore/max_scoreを
 * 再集計する ―― 社内版のOverallScoreCalculatorとは完全に別建て。
 */
class LeadScoreCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
    }

    private function calculator(): LeadScoreCalculator
    {
        return app(LeadScoreCalculator::class);
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<int, MetricDefinition>
     */
    private function definitionsFor(array $keys): Collection
    {
        return MetricDefinition::query()->whereIn('key', $keys)->get();
    }

    private function makeResult(string $key, MetricResultStatus $status, mixed $normalizedValue = null): MetricResult
    {
        $definition = MetricDefinition::query()->where('key', $key)->firstOrFail();
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $result = MetricResult::factory()->make([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $definition->id,
            'status' => $status,
            'normalized_value' => $normalizedValue === null ? null : ['value' => $normalizedValue],
        ]);
        $result->setRelation('metricDefinition', $definition);

        return $result;
    }

    /**
     * MetricDefinitionSeederはカテゴリ内の相対配点(points)をCategoryDefinition.
     * weightに収まるよう自動スケーリングするため、max_scoreの実値はpointsの
     * 生の数値と一致しない(端数吸収の対象になった項目は特に)。テストでは
     * 生のpoints値をハードコードせず、実際に永続化されたmax_scoreを都度読む。
     */
    private function maxScoreOf(string $key): float
    {
        return (float) MetricDefinition::query()->where('key', $key)->firstOrFail()->max_score;
    }

    public function test_configured_max_score_excludes_metrics_outside_the_4_perspectives(): void
    {
        // title_present(②メッセージ)はカタログに含まれるが、
        // authority_score(外部SEO)はどの観点にも含まれない。
        $definitions = $this->definitionsFor(['title_present', 'authority_score']);
        $results = new Collection([$this->makeResult('title_present', MetricResultStatus::Success, true)]);

        $score = $this->calculator()->calculate($definitions, $results);

        $this->assertEqualsWithDelta($this->maxScoreOf('title_present'), $score->configuredMaxScore, 0.01);
    }

    public function test_score_is_unaffected_by_non_catalog_metric_results(): void
    {
        $definitions = $this->definitionsFor(['title_present', 'authority_score']);

        $withHighAuthority = new Collection([
            $this->makeResult('title_present', MetricResultStatus::Success, true),
            $this->makeResult('authority_score', MetricResultStatus::Success, 90),
        ]);
        $withUnavailableAuthority = new Collection([
            $this->makeResult('title_present', MetricResultStatus::Success, true),
            $this->makeResult('authority_score', MetricResultStatus::Unavailable),
        ]);

        $scoreHigh = $this->calculator()->calculate($definitions, $withHighAuthority);
        $scoreUnavailable = $this->calculator()->calculate($definitions, $withUnavailableAuthority);

        // Semrush(authority_score)の値・有無に関わらず、4観点外の指標は
        // リード向けスコアを一切動かさない(2026-07-28指摘の問題②の解消)。
        $this->assertEqualsWithDelta($scoreHigh->overallScore, $scoreUnavailable->overallScore, 0.01);
        $this->assertEqualsWithDelta($scoreHigh->configuredMaxScore, $scoreUnavailable->configuredMaxScore, 0.01);
        $this->assertSame($scoreHigh->displayScore, $scoreUnavailable->displayScore);
    }

    public function test_not_scored_recruit_metrics_never_contribute_points(): void
    {
        $definitions = $this->definitionsFor(['recruit_link_present']);
        $results = new Collection([$this->makeResult('recruit_link_present', MetricResultStatus::Success, true)]);

        $score = $this->calculator()->calculate($definitions, $results);

        $this->assertSame(0.0, $score->configuredMaxScore);
        $this->assertSame(0.0, $score->overallScore);
    }

    public function test_coverage_rate_reflects_only_the_catalog_scoped_subset(): void
    {
        // title_present・meta_description_presentともに②メッセージ。
        $definitions = $this->definitionsFor(['title_present', 'meta_description_present']);
        $results = new Collection([
            $this->makeResult('title_present', MetricResultStatus::Success, true),
            $this->makeResult('meta_description_present', MetricResultStatus::Unavailable),
        ]);

        $score = $this->calculator()->calculate($definitions, $results);

        $titleMax = $this->maxScoreOf('title_present');
        $configuredMax = $titleMax + $this->maxScoreOf('meta_description_present');

        $this->assertEqualsWithDelta($configuredMax, $score->configuredMaxScore, 0.01);
        // meta_description_presentはUnavailableのため分子に含まれない。
        $this->assertEqualsWithDelta($titleMax, $score->availableScore, 0.01);
        $this->assertEqualsWithDelta($titleMax / $configuredMax * 100, $score->coverageRate, 0.1);
    }

    public function test_overall_score_matches_the_sum_of_individual_metric_ratios(): void
    {
        // title_present: boolean, true -> ratio 1.0 -> 満点。
        // word_count_sufficient: linear(min=0, target=300), value=150 -> ratio 0.5 -> 満点の半分。
        $definitions = $this->definitionsFor(['title_present', 'word_count_sufficient']);
        $results = new Collection([
            $this->makeResult('title_present', MetricResultStatus::Success, true),
            $this->makeResult('word_count_sufficient', MetricResultStatus::Success, 150),
        ]);

        $score = $this->calculator()->calculate($definitions, $results);

        $titleMax = $this->maxScoreOf('title_present');
        $wordMax = $this->maxScoreOf('word_count_sufficient');
        $expectedOverall = $titleMax * 1.0 + $wordMax * 0.5;

        $this->assertEqualsWithDelta($titleMax + $wordMax, $score->configuredMaxScore, 0.01);
        $this->assertEqualsWithDelta($expectedOverall, $score->overallScore, 0.01);
        $this->assertSame((int) round($expectedOverall), $score->displayScore);
    }

    public function test_category_scores_are_grouped_by_perspective_not_internal_category(): void
    {
        $definitions = $this->definitionsFor(['title_present']);
        $results = new Collection([$this->makeResult('title_present', MetricResultStatus::Success, true)]);

        $score = $this->calculator()->calculate($definitions, $results);

        $this->assertEqualsCanonicalizing(
            ['completeness', 'clarity', 'findability', 'usability'],
            $score->categoryScores->pluck('key')->all(),
        );
        $this->assertSame(
            LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_CLARITY],
            $score->categoryScores->firstWhere('key', LeadMetricCatalog::PERSPECTIVE_CLARITY)->name,
        );
    }
}
