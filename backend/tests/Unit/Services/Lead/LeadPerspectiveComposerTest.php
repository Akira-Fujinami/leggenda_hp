<?php

namespace Tests\Unit\Services\Lead;

use App\Enums\MetricResultStatus;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\WebsiteAnalysis;
use App\Services\Lead\LeadPerspectiveComposer;
use App\Support\Lead\LeadMetricCatalog;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LeadPerspectiveComposerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
    }

    private function composer(): LeadPerspectiveComposer
    {
        return app(LeadPerspectiveComposer::class);
    }

    /**
     * MetricScorerは採点をnormalized_valueから再計算するため(score列は
     * 出力専用のキャッシュに過ぎない)、ここではnormalized_valueのみを
     * 与える。
     */
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

    private function perspective(array $items, string $key): array
    {
        $results = new Collection($items);
        $perspectives = $this->composer()->compose($results);

        return collect($perspectives)->firstWhere('key', $key);
    }

    public function test_a_fully_good_perspective_is_reported_as_good_overall(): void
    {
        $items = [
            $this->makeResult('title_present', MetricResultStatus::Success, true), // boolean, higher_is_better -> ratio 1.0
            $this->makeResult('meta_description_present', MetricResultStatus::Success, true),
        ];

        $clarity = $this->perspective($items, LeadMetricCatalog::PERSPECTIVE_CLARITY);

        $this->assertSame(LeadPerspectiveComposer::STATUS_GOOD, $clarity['status']);
    }

    public function test_a_low_achievement_ratio_item_drives_the_overall_status_to_needs_improvement(): void
    {
        $items = [
            $this->makeResult('title_present', MetricResultStatus::Success, true), // ratio 1.0 -> good
            // word_count_sufficient: linear, minimum_value=0, target_value=300 -> ratio=10/300≈0.03 -> needs_improvement
            $this->makeResult('word_count_sufficient', MetricResultStatus::Success, 10),
        ];

        $clarity = $this->perspective($items, LeadMetricCatalog::PERSPECTIVE_CLARITY);

        $this->assertSame(LeadPerspectiveComposer::STATUS_NEEDS_IMPROVEMENT, $clarity['status']);
    }

    public function test_unavailable_status_is_reported_as_not_measured_not_needs_improvement(): void
    {
        $items = [
            $this->makeResult('title_present', MetricResultStatus::Unavailable),
        ];

        $clarity = $this->perspective($items, LeadMetricCatalog::PERSPECTIVE_CLARITY);
        $titleItem = collect($clarity['items'])->firstWhere('label', app(\App\Support\Lead\LeadMetricCatalog::class)->label('title_present'));

        $this->assertSame(LeadPerspectiveComposer::STATUS_NOT_MEASURED, $titleItem['status']);
        // 未測定は「良好」でも「改善の余地」でもない -> 全体もnot_measuredに倒れる
        // (needs_improvement/needs_review/goodのいずれにも該当する項目が無いため)。
        $this->assertSame(LeadPerspectiveComposer::STATUS_NOT_MEASURED, $clarity['status']);
    }

    public function test_a_not_scored_boolean_item_is_bucketed_by_its_raw_presence_value(): void
    {
        $present = [$this->makeResult('faq_link_present', MetricResultStatus::Success, true)];
        $absent = [$this->makeResult('faq_link_present', MetricResultStatus::NotFound, false)];

        $findabilityPresent = $this->perspective($present, LeadMetricCatalog::PERSPECTIVE_FINDABILITY);
        $findabilityAbsent = $this->perspective($absent, LeadMetricCatalog::PERSPECTIVE_FINDABILITY);

        $faqItemPresent = collect($findabilityPresent['items'])->firstWhere('label', app(\App\Support\Lead\LeadMetricCatalog::class)->label('faq_link_present'));
        $faqItemAbsent = collect($findabilityAbsent['items'])->firstWhere('label', app(\App\Support\Lead\LeadMetricCatalog::class)->label('faq_link_present'));

        $this->assertSame(LeadPerspectiveComposer::STATUS_GOOD, $faqItemPresent['status']);
        $this->assertSame(LeadPerspectiveComposer::STATUS_NEEDS_IMPROVEMENT, $faqItemAbsent['status']);
    }

    public function test_usability_perspective_always_carries_the_design_disclaimer_note(): void
    {
        $usability = $this->perspective([], LeadMetricCatalog::PERSPECTIVE_USABILITY);

        $this->assertSame(LeadMetricCatalog::USABILITY_SECTION_NOTE, $usability['note']);
    }

    public function test_completeness_is_not_detected_when_no_recruit_link_was_found(): void
    {
        $items = [$this->makeResult('recruit_link_present', MetricResultStatus::NotFound, false)];

        $completeness = $this->perspective($items, LeadMetricCatalog::PERSPECTIVE_COMPLETENESS);

        $this->assertSame(LeadPerspectiveComposer::STATUS_NOT_DETECTED, $completeness['status']);
        $this->assertEmpty($completeness['items']);
        $this->assertNotNull($completeness['note']);
    }

    public function test_completeness_is_unavailable_when_a_recruit_link_was_found_but_the_page_could_not_be_analyzed(): void
    {
        $items = [
            $this->makeResult('recruit_link_present', MetricResultStatus::Success, true),
            $this->makeResult('recruit_title_present', MetricResultStatus::Unavailable),
            $this->makeResult('recruit_meta_description_present', MetricResultStatus::Unavailable),
            $this->makeResult('recruit_h1_single', MetricResultStatus::Unavailable),
            $this->makeResult('recruit_heading_structure_present', MetricResultStatus::Unavailable),
            $this->makeResult('recruit_word_count_sufficient', MetricResultStatus::Unavailable),
        ];

        $completeness = $this->perspective($items, LeadMetricCatalog::PERSPECTIVE_COMPLETENESS);

        $this->assertSame(LeadPerspectiveComposer::STATUS_UNAVAILABLE, $completeness['status']);
        $this->assertEmpty($completeness['items']);
    }

    public function test_completeness_shows_real_items_when_the_recruit_page_was_successfully_analyzed(): void
    {
        $items = [
            $this->makeResult('recruit_link_present', MetricResultStatus::Success, true),
            $this->makeResult('recruit_title_present', MetricResultStatus::Success, true),
            $this->makeResult('recruit_meta_description_present', MetricResultStatus::Success, true),
            $this->makeResult('recruit_h1_single', MetricResultStatus::Success, true),
            $this->makeResult('recruit_heading_structure_present', MetricResultStatus::Success, true),
            $this->makeResult('recruit_word_count_sufficient', MetricResultStatus::Success, 500),
        ];

        $completeness = $this->perspective($items, LeadMetricCatalog::PERSPECTIVE_COMPLETENESS);

        $this->assertNotSame(LeadPerspectiveComposer::STATUS_NOT_DETECTED, $completeness['status']);
        $this->assertNotSame(LeadPerspectiveComposer::STATUS_UNAVAILABLE, $completeness['status']);
        $this->assertCount(6, $completeness['items']);
    }
}
