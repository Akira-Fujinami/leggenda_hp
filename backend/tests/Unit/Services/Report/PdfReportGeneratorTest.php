<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\PdfReportGenerator;
use App\Support\Report\ReportCategoryRow;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;
use Tests\TestCase;

class PdfReportGeneratorTest extends TestCase
{
    private function viewModel(): ReportViewModel
    {
        return new ReportViewModel(
            companyDisplayName: '株式会社サンプル様',
            generatedAtLabel: '2026年7月27日',
            selfWebsiteUrl: 'https://example.com',
            competitorWebsiteUrl: null,
            selfScore: ['display_score' => 76, 'coverage_rate' => 92.5, 'confidence_rate' => 88.0],
            competitorScore: null,
            overallSummaryText: '株式会社サンプル様の自社サイトは、総合スコア76点(100点満点)という結果になりました。',
            comparisonSentence: null,
            categoryBreakdown: [
                new ReportCategoryRow('technical_seo', '技術SEO', '検索エンジンに正しく認識されるための基本設定', 15, 20, 100, null),
                new ReportCategoryRow('authority', '外部SEO・ドメイン評価', '外部からの評価やドメインの信頼性', 0, 15, 0, 'not_measured'),
            ],
            topRecommendations: [
                new ReportRecommendationRow('画像を圧縮してください', '表示速度の改善につながります。', '緊急', '高', '小'),
            ],
            isPartial: false,
        );
    }

    public function test_it_generates_a_valid_pdf_document(): void
    {
        $pdf = app(PdfReportGenerator::class)->generate($this->viewModel());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
