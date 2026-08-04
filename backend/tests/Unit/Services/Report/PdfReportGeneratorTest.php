<?php

namespace Tests\Unit\Services\Report;

use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use App\Services\Report\PdfReportGenerator;
use App\Support\Lead\LeadMetricCatalog;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;
use Tests\TestCase;

class PdfReportGeneratorTest extends TestCase
{
    private function viewModel(): ReportViewModel
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']]],
        ];
        $competitorAxes = [
            ['key' => 'relationship', 'group' => 'company_distance', 'name' => '就業環境', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'colleagues', 'name' => '同僚・先輩像']]],
        ];
        $comparisonComposer = app(BrandWheelSubElementComparisonComposer::class);
        $subElementComparison = $comparisonComposer->compose($selfAxes, $competitorAxes);

        return new ReportViewModel(
            companyDisplayName: '株式会社サンプル様',
            generatedAtLabel: '2026年7月27日',
            selfWebsiteUrl: 'https://example.com',
            competitorWebsiteUrl: 'https://competitor.example.com',
            selfScore: ['display_score' => 76, 'configured_max_score' => 100, 'coverage_rate' => 92.5, 'confidence_rate' => 88.0],
            competitorScore: null,
            overallSummaryText: '株式会社サンプル様の自社サイトは、総合スコア76点(100点満点)という結果になりました。',
            comparisonSentence: null,
            perspectives: [
                [
                    'key' => LeadMetricCatalog::PERSPECTIVE_COMPLETENESS,
                    'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_COMPLETENESS],
                    'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[LeadMetricCatalog::PERSPECTIVE_COMPLETENESS],
                    'note' => LeadMetricCatalog::COMPLETENESS_LEGAL_ITEMS_NOTE,
                    'status' => 'not_detected',
                    'summary' => '採用ページを検出できませんでした。',
                    'items' => [],
                    'one_liner' => '採用ページを検出できませんでした。',
                ],
                [
                    'key' => LeadMetricCatalog::PERSPECTIVE_USABILITY,
                    'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_USABILITY],
                    'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[LeadMetricCatalog::PERSPECTIVE_USABILITY],
                    'note' => LeadMetricCatalog::USABILITY_SECTION_NOTE,
                    'status' => 'needs_improvement',
                    'items' => [
                        ['label' => 'スマートフォンでの表示対応', 'status' => 'needs_improvement', 'detail' => null],
                    ],
                    'one_liner' => '確認した1項目のうち1項目で改善の余地がありました。',
                ],
            ],
            topRecommendations: [
                new ReportRecommendationRow('画像を圧縮してください', '表示速度の改善につながります。', '緊急', '高', '小'),
            ],
            isPartial: false,
            brandWheelSelf: [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://example.com/careers',
                'axes' => $selfAxes,
                'key_message' => '技術で社会基盤を支える、という主題が置かれています。',
                'impression' => '情緒的便益の記述が薄いのがもったいないところです。',
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
            brandWheelCompetitor: [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://competitor.example.com/careers',
                'axes' => $competitorAxes,
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
            brandWheelComparison: ['self_points' => ['活動的魅力が最も内容として充足しています。'], 'one_point' => ['key' => 'well_covered', 'text' => '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。']],
            brandWheelRadarPng: null,
            selfBrandWheelEvidenceItems: [
                ['axis_key' => 'will_activity', 'axis_name' => '活動的魅力', 'group' => 'company_appeal', 'sub_element_name' => 'パーパス', 'evidence' => '技術で社会の「あたり前」を支える。それが私たちの存在意義です。'],
            ],
            selfTotalMatched: 2,
            selfTotalMax: 4,
            competitorTotalMatched: 1,
            competitorTotalMax: 4,
            subElementComparison: $subElementComparison,
            gapAnalysis: $comparisonComposer->splitByGap($subElementComparison),
            improvementFocus: app(BrandWheelImprovementFocusComposer::class)->compose($subElementComparison, [
                'relationship' => ['colleagues' => '入社3年目の先輩が、日々どんな判断をしているかを紹介しています。'],
            ]),
        );
    }

    public function test_it_generates_a_valid_pdf_document(): void
    {
        $pdf = app(PdfReportGenerator::class)->generate($this->viewModel());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
