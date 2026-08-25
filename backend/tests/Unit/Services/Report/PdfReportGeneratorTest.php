<?php

namespace Tests\Unit\Services\Report;

use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use App\Services\Report\PdfReportGenerator;
use App\Support\Report\ReportViewModel;
use Tests\TestCase;

/**
 * 2026-08-08: レポートを7ページ構成へ再編したことに伴うReportViewModelの
 * 構成変更(selfScore/perspectives/topRecommendations等の削除、
 * brandWheelRadarPngSelf/Competitor/Comparisonへの分割)に合わせて
 * fixtureを全面書き直し。文面の分岐そのものはLeadPdfViewTest側で検証
 * するため、ここでは有効なPDFバイナリが生成されることだけを確認する
 * (このファイル自体の既存方針)。
 */
class PdfReportGeneratorTest extends TestCase
{
    private function viewModel(): ReportViewModel
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
        ];
        $competitorAxes = [
            ['key' => 'relationship', 'group' => 'company_distance', 'name' => '就業環境', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'colleagues', 'name' => '同僚・先輩像']], 'label_only_sub_elements' => []],
        ];
        $comparisonComposer = app(BrandWheelSubElementComparisonComposer::class);
        $subElementComparison = $comparisonComposer->compose($selfAxes, $competitorAxes);

        return new ReportViewModel(
            companyDisplayName: '株式会社サンプル様',
            generatedAtLabel: '2026年8月8日',
            selfWebsiteUrl: 'https://example.com',
            competitorWebsiteUrl: 'https://competitor.example.com',
            isPartial: false,
            brandWheelSelf: [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://example.com/careers',
                'axes' => $selfAxes,
                'key_message' => '技術で社会基盤を支える、という主題が置かれています。',
                'impression' => '情緒的便益の記述が薄いのがもったいないところです。',
                'impression_items' => ['情緒的便益の記述が薄いのがもったいないところです。'],
                'positive_impression' => '事業内容への取り組み姿勢が伝わり、良い印象を与える可能性があります。',
                'negative_impression' => '働く環境の具体像がイメージしづらい可能性があります。',
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
            brandWheelCompetitor: [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://competitor.example.com/careers',
                'axes' => $competitorAxes,
                'key_message' => null,
                'impression' => null,
                'impression_items' => [],
                'positive_impression' => null,
                'negative_impression' => null,
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
            brandWheelComparison: [
                'self_points' => ['活動的魅力が最も内容として充足しています。'],
                'competitor_points' => ['就業環境が最も内容として充足しています。'],
                'one_point' => ['key' => 'well_covered', 'text' => '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。'],
            ],
            brandWheelRadarPngSelf: null,
            brandWheelRadarPngCompetitor: null,
            brandWheelRadarPngComparison: null,
            selfTotalMatched: 2,
            selfTotalMax: 4,
            competitorTotalMatched: 1,
            competitorTotalMax: 4,
            selfTotalLabelOnly: 0,
            competitorTotalLabelOnly: 0,
            subElementComparison: $subElementComparison,
            groupTotals: $comparisonComposer->groupTotals($subElementComparison),
            comparisonOverview: [],
            improvementFocus: app(BrandWheelImprovementFocusComposer::class)->compose($subElementComparison, [
                'relationship' => ['colleagues' => '入社3年目の先輩が、日々どんな判断をしているかを紹介しています。'],
            ]),
            improvementFocusSelfOnly: null,
            improvementOnePoint: '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。',
            improvementRecommendation: null,
            improvementReason: '就業環境は競合が読み取れているのに対し自社は0件で、候補者が働くイメージを持ちにくい状態です。',
            improvementRecommendedContents: ['入社数年目の社員の1日の過ごし方'],
            improvementMidTermAction: '中長期的には、部署横断プロジェクトの事例をシリーズ化することも検討できます。',
            selfLowContentNotice: null,
            crawlSiteEnabled: false,
        );
    }

    public function test_it_generates_a_valid_pdf_document(): void
    {
        $pdf = app(PdfReportGenerator::class)->generate($this->viewModel());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
