<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\AdminComparisonPptxDataBuilder;
use App\Support\Report\MultiSiteReportViewModel;
use Tests\TestCase;

class AdminComparisonPptxDataBuilderTest extends TestCase
{
    private function viewModel(array $overrides = []): MultiSiteReportViewModel
    {
        $defaults = [
            'selfCompanyDisplayName' => 'テスト株式会社',
            'generatedAtLabel' => '2026年9月8日',
            'selfWebsiteUrl' => 'https://example.com',
            'competitors' => [
                ['name' => '競合A社', 'url' => 'https://a.example.com'],
                ['name' => '競合B社', 'url' => 'https://b.example.com'],
            ],
            'competitorCount' => 2,
            'majorityThreshold' => 2,
            'selfReadable' => true,
            'selfTotalMatched' => 10,
            'selfTotalMax' => 24,
            'brandWheelRadarPngCombined' => null,
            'missingFromSelf' => [
                ['axis_name' => '金銭的便益', 'sub_name' => '福利厚生', 'definition' => '', 'recommendation' => '', 'competitor_matched_count' => 2, 'representative_company_name' => '競合A社', 'quote' => '引用文', 'quote_translation' => null],
            ],
            'selfStrengths' => [],
            'comparisonTable' => [
                ['axis_name' => 'A', 'group' => 'g', 'sub_name' => 's1', 'self_matched' => true, 'competitor_matched' => [true, false]],
                ['axis_name' => 'A', 'group' => 'g', 'sub_name' => 's2', 'self_matched' => false, 'competitor_matched' => [true, true]],
                ['axis_name' => 'A', 'group' => 'g', 'sub_name' => 's3', 'self_matched' => false, 'competitor_matched' => [false, true]],
            ],
            'selfEvidenceByAxis' => [],
            'hasQuoteTranslations' => false,
        ];

        return new MultiSiteReportViewModel(...array_merge($defaults, $overrides));
    }

    public function test_self_company_is_first_and_marked_is_self(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        $this->assertSame('テスト株式会社', $data['self_company_name']);
        $this->assertSame(['name' => 'テスト株式会社', 'matched' => 10, 'total' => 24, 'is_self' => true], $data['companies'][0]);
    }

    public function test_competitor_matched_counts_are_summed_from_the_comparison_table_by_index(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        // 競合A社(index 0): s1,s2がtrue = 2件。競合B社(index 1): s2,s3がtrue = 2件。
        $this->assertSame(['name' => '競合A社', 'matched' => 2, 'total' => 3, 'is_self' => false], $data['companies'][1]);
        $this->assertSame(['name' => '競合B社', 'matched' => 2, 'total' => 3, 'is_self' => false], $data['companies'][2]);
    }

    public function test_rows_are_mapped_from_missing_from_self(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        $this->assertSame([
            ['sub_name' => '福利厚生', 'axis_name' => '金銭的便益', 'matched_count' => 2, 'quote' => '引用文'],
        ], $data['rows']);
    }

    public function test_source_note_includes_the_generated_at_label(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['generatedAtLabel' => '2026年1月1日']));

        $this->assertStringContainsString('2026年1月1日', $data['source_note']);
    }

    public function test_page_number_is_null(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        $this->assertNull($data['page_number']);
    }

    public function test_competitor_count_matches_view_model(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['competitorCount' => 2]));

        $this->assertSame(2, $data['competitor_count']);
    }
}
