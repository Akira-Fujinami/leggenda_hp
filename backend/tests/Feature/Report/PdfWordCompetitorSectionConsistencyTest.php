<?php

namespace Tests\Feature\Report;

use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use App\Services\Report\WordReportGenerator;
use App\Support\Report\ReportViewModel;
use Tests\TestCase;
use ZipArchive;

/**
 * 2026-08-18: 競合サイトの分析結果セクションの出し分け判定
 * ($competitorReadable相当)は、ReportViewModel冒頭コメントの方針どおり
 * PDF側(lead-pdf.blade.php)とWord側(WordReportGenerator::competitorReadable())
 * それぞれの生成コード内に独立して存在する ―― 統一(共通クラスへ抽出等)は
 * 依頼者判断により行わない。ただし判定が2箇所に分かれている以上、どちらか
 * 片方だけ直されて食い違う事故を検知する仕組みが必要なため、同一
 * ReportViewModelからPDF・Wordを両方生成し、「競合サイトの分析結果」
 * セクションの有無が一致することをこのテストで保証する(依頼者指定)。
 */
class PdfWordCompetitorSectionConsistencyTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function wheel(array $overrides = []): array
    {
        return array_merge([
            'status' => 'success',
            'status_message' => null,
            'analyzed_url' => 'https://example.com/careers',
            'axes' => [],
            'key_message' => null,
            'impression' => null,
            'impression_items' => [],
            'positive_impression' => null,
            'negative_impression' => null,
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ], $overrides);
    }

    private function viewModel(array $overrides = []): ReportViewModel
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
        ];

        $defaults = [
            'companyDisplayName' => '株式会社サンプル様',
            'generatedAtLabel' => '2026年8月8日',
            'selfWebsiteUrl' => 'https://example.com',
            'competitorWebsiteUrl' => null,
            'isPartial' => false,
            'brandWheelSelf' => $this->wheel(['axes' => $selfAxes]),
            'brandWheelCompetitor' => null,
            'brandWheelComparison' => [
                'self_points' => ['活動的魅力が最も内容として充足しています。'],
                'competitor_points' => [],
                'one_point' => ['key' => 'well_covered', 'text' => '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。'],
            ],
            'brandWheelRadarPngSelf' => null,
            'brandWheelRadarPngCompetitor' => null,
            'brandWheelRadarPngComparison' => null,
            'selfTotalMatched' => 1,
            'selfTotalMax' => 4,
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 0,
            'selfTotalLabelOnly' => 0,
            'competitorTotalLabelOnly' => 0,
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose($selfAxes, []),
            'groupTotals' => [],
            'comparisonOverview' => [],
            'improvementFocus' => null,
            'improvementFocusSelfOnly' => null,
            'improvementOnePoint' => null,
            'improvementRecommendation' => null,
            'improvementReason' => null,
            'improvementRecommendedContents' => [],
            'improvementMidTermAction' => null,
        ];

        $values = array_merge($defaults, $overrides);

        return new ReportViewModel(...$values);
    }

    private function renderPdfHtml(ReportViewModel $viewModel): string
    {
        return view('reports.lead-pdf', [
            'viewModel' => $viewModel,
            'ipaexGothicFontPath' => 'file:///dev/null',
            'brandWheelFrameworkImageBase64' => base64_encode((string) file_get_contents(resource_path('images/brand-wheel-framework.png'))),
            'leggendaLogoImageBase64' => base64_encode((string) file_get_contents(resource_path('images/leggenda-logo.png'))),
        ])->render();
    }

    private function renderWordXml(ReportViewModel $viewModel): string
    {
        $docx = app(WordReportGenerator::class)->generate($viewModel);
        $tempPath = tempnam(sys_get_temp_dir(), 'pdf-word-consistency-').'.docx';
        file_put_contents($tempPath, $docx);

        $zip = new ZipArchive;
        $zip->open($tempPath);
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tempPath);

        $this->assertNotFalse($documentXml);

        return $documentXml;
    }

    /**
     * @return array<string, array{0: ReportViewModel, 1: bool}>
     */
    private function scenarios(): array
    {
        return [
            '競合サイトが読み取れる' => [
                $this->viewModel([
                    'competitorWebsiteUrl' => 'https://competitor.example.com',
                    'brandWheelCompetitor' => $this->wheel([
                        'analyzed_url' => 'https://competitor.example.com/careers',
                        'axes' => [
                            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
                        ],
                    ]),
                    'competitorTotalMatched' => 1,
                    'competitorTotalMax' => 4,
                ]),
                true,
            ],
            '競合サイトのURLが無い' => [
                $this->viewModel(),
                false,
            ],
            '競合サイトのURLはあるが読み取れない(403等)' => [
                $this->viewModel([
                    'competitorWebsiteUrl' => 'https://competitor.example.com',
                    'brandWheelCompetitor' => $this->wheel([
                        'status' => 'insufficient_input',
                        'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
                    ]),
                    'competitorTotalMatched' => 0,
                    'competitorTotalMax' => 0,
                ]),
                false,
            ],
        ];
    }

    public function test_competitor_section_presence_matches_between_pdf_and_word_for_every_scenario(): void
    {
        foreach ($this->scenarios() as $label => [$viewModel, $expectedPresent]) {
            $pdfHtml = $this->renderPdfHtml($viewModel);
            $wordXml = $this->renderWordXml($viewModel);

            $pdfHasSection = str_contains($pdfHtml, '競合サイトの分析結果');
            $wordHasSection = str_contains($wordXml, '競合サイトの分析結果');

            $this->assertSame(
                $expectedPresent,
                $pdfHasSection,
                "PDF側のシナリオ「{$label}」で期待値と一致しませんでした。",
            );
            $this->assertSame(
                $expectedPresent,
                $wordHasSection,
                "Word側のシナリオ「{$label}」で期待値と一致しませんでした。",
            );
            $this->assertSame(
                $pdfHasSection,
                $wordHasSection,
                "シナリオ「{$label}」でPDFとWordの間で競合サイトの分析結果セクションの有無が食い違いました。",
            );
        }
    }
}
