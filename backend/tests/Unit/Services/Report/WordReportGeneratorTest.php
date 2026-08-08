<?php

namespace Tests\Unit\Services\Report;

use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use App\Services\Report\WordReportGenerator;
use App\Support\Report\ReportViewModel;
use Tests\TestCase;
use ZipArchive;

/**
 * 2026-08-08: レポートを9ページ構成から7ページ構成へ再編したPDF版
 * (lead-pdf.blade.php)にWord版(WordReportGenerator)も1:1で合わせて全面
 * 書き直したことに伴い、このテストファイルも全面書き直し。旧「総合結果」
 * (社内向け4観点スコアのWord版限定セクション)・「サイトから読み取れた
 * 記述」・「採用担当の視点で見た診断結果」(4観点)・「サイトで触れられて
 * いなかった項目」関連のテストはすべて削除し、新しい7セクション構成
 * (表紙/前置き/自社サイトの分析結果/競合サイトの分析結果/○△－の対比表/
 * 改善提案/新CTA)に沿ったテストへ置き換えた。
 */
class WordReportGeneratorTest extends TestCase
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
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ], $overrides);
    }

    private function viewModel(array $overrides = []): ReportViewModel
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
        ];

        $defaults = [
            'companyDisplayName' => '株式会社サンプル様',
            'generatedAtLabel' => '2026年8月8日',
            'selfWebsiteUrl' => 'https://example.com',
            'competitorWebsiteUrl' => null,
            'isPartial' => false,
            'brandWheelSelf' => $this->wheel([
                'axes' => $selfAxes,
                'key_message' => '技術で社会基盤を支える、という主題が置かれています。',
                'impression' => '情緒的便益の記述が薄いのがもったいないところです。',
                'impression_items' => ['情緒的便益の記述が薄いのがもったいないところです。'],
            ]),
            'brandWheelCompetitor' => null,
            'brandWheelComparison' => [
                'self_points' => ['活動的魅力が最も内容として充足しています。'],
                'competitor_points' => [],
                'one_point' => ['key' => 'well_covered', 'text' => '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。'],
            ],
            'brandWheelRadarPngSelf' => null,
            'brandWheelRadarPngCompetitor' => null,
            'brandWheelRadarPngComparison' => null,
            'selfTotalMatched' => 2,
            'selfTotalMax' => 4,
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 0,
            'selfTotalLabelOnly' => 0,
            'competitorTotalLabelOnly' => 0,
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose($selfAxes, []),
            'improvementFocus' => null,
        ];

        $values = array_merge($defaults, $overrides);

        return new ReportViewModel(...$values);
    }

    /**
     * 自社・競合ともにブランド・ホイールが揃っている状態のfixture
     * (競合サイトの分析結果・○△－の対比表・改善提案セクション用)。
     * LeadPdfViewTest::comparisonViewModel()と同じ組み立て方。
     */
    private function comparisonViewModel(array $overrides = []): ReportViewModel
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
        ];
        $competitorAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
            ['key' => 'relationship', 'group' => 'company_distance', 'name' => '就業環境', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [
                ['key' => 'colleagues', 'name' => '同僚・先輩像'], ['key' => 'atmosphere', 'name' => '職場の雰囲気'],
            ], 'label_only_sub_elements' => []],
        ];

        $comparisonComposer = app(BrandWheelSubElementComparisonComposer::class);
        $subElementComparison = $comparisonComposer->compose($selfAxes, $competitorAxes);
        $improvementFocus = app(BrandWheelImprovementFocusComposer::class)->compose($subElementComparison, [
            'relationship' => [
                'colleagues' => '入社3年目の先輩が、日々どんな判断をしているかを紹介しています。',
                'atmosphere' => '部署をまたいだ相談が日常的に起きる、フラットな環境です。',
            ],
        ]);

        return $this->viewModel(array_merge([
            'competitorWebsiteUrl' => 'https://competitor.example.com',
            'brandWheelSelf' => $this->wheel(['axes' => $selfAxes]),
            'brandWheelCompetitor' => $this->wheel([
                'analyzed_url' => 'https://competitor.example.com/careers',
                'axes' => $competitorAxes,
            ]),
            'brandWheelComparison' => [
                'self_points' => ['活動的魅力が最も内容として充足しています。'],
                'competitor_points' => ['就業環境が最も内容として充足しています。'],
                'one_point' => ['key' => 'well_covered', 'text' => '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。'],
            ],
            'selfTotalMatched' => 1,
            'selfTotalMax' => 4,
            'competitorTotalMatched' => 3,
            'competitorTotalMax' => 8,
            'subElementComparison' => $subElementComparison,
            'improvementFocus' => $improvementFocus,
        ], $overrides));
    }

    private function extractDocumentXml(string $docx): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'word-report-test-').'.docx';
        file_put_contents($tempPath, $docx);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tempPath) === true, '生成されたファイルが有効なzip(docx)であること');

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tempPath);

        $this->assertNotFalse($documentXml);

        return $documentXml;
    }

    private function generate(ReportViewModel $viewModel): string
    {
        return $this->extractDocumentXml(app(WordReportGenerator::class)->generate($viewModel));
    }

    public function test_it_generates_a_valid_docx_document_with_correct_japanese_text(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('株式会社サンプル様', $documentXml);
        $this->assertStringContainsString('自社サイトの分析結果', $documentXml);
        $this->assertStringNotContainsString('job_type', $documentXml);
        $this->assertStringNotContainsString('error_code', $documentXml);
    }

    public function test_includes_the_self_analysis_results_section_with_counts_and_summary(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('自社サイトの分析結果', $documentXml);
        $this->assertStringContainsString('活動的魅力', $documentXml);
        $this->assertStringContainsString('2 / 4件', $documentXml);
        $this->assertStringContainsString('パーパス', $documentXml);
        $this->assertStringContainsString('技術で社会基盤を支える', $documentXml);
        $this->assertStringContainsString('情緒的便益の記述が薄い', $documentXml);
        $this->assertStringContainsString('活動的魅力が最も内容として充足しています', $documentXml);
        $this->assertStringContainsString('AIを使用', $documentXml);
    }

    /**
     * 0件の軸セルは「該当する記述は見つかりませんでした」と明示する
     * (PDF版の.none2と表記を揃える)。
     */
    public function test_zero_matched_axis_shows_the_no_evidence_message_not_a_dash(): void
    {
        $documentXml = $this->generate($this->viewModel([
            'brandWheelSelf' => $this->wheel([
                'axes' => [
                    ['key' => 'asset', 'group' => 'company_appeal', 'name' => '資産的魅力', 'matched_count' => 0, 'max_count' => 4, 'matched_sub_elements' => [], 'label_only_sub_elements' => []],
                ],
            ]),
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose([
                ['key' => 'asset', 'group' => 'company_appeal', 'name' => '資産的魅力', 'matched_sub_elements' => [], 'label_only_sub_elements' => []],
            ], []),
        ]));

        $this->assertStringContainsString('該当する記述は見つかりませんでした', $documentXml);
    }

    public function test_does_not_render_the_brand_wheel_table_when_status_is_not_success(): void
    {
        $documentXml = $this->generate($this->viewModel([
            'brandWheelSelf' => $this->wheel([
                'status' => 'recruit_page_unreadable',
                'status_message' => '採用ページの内容を取得できなかったため、この項目の分析は行っていません。',
                'source_pages' => ['recruit_page' => 'unreadable', 'home_page' => 'read'],
            ]),
            'brandWheelComparison' => ['self_points' => [], 'competitor_points' => [], 'one_point' => null],
            'selfTotalMatched' => 0,
            'selfTotalMax' => 0,
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose([], []),
        ]));

        $this->assertStringContainsString('採用ページの内容を取得できなかったため', $documentXml);
        $this->assertStringNotContainsString('パーパス', $documentXml);
        // 前置きセクション(addBrandWheelFrameworkIntroSection)の3領域説明に
        // 「活動的魅力」等が固定文言として含まれるため、このアサーションは
        // 実際の解析結果テーブルにだけ出る列見出しで判定する。
        $this->assertStringNotContainsString('読み取れた内容', $documentXml);
    }

    public function test_includes_the_brand_wheel_framework_intro_section_with_the_required_caveat_verbatim(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('採用ブランドの捉え方', $documentXml);
        $this->assertStringContainsString(
            '読み取れなかった項目は、その魅力が『無い』という意味ではありません。'
            .'サイトにそう書かれていない、というだけです。',
            $documentXml,
        );
    }

    public function test_intro_section_group_labels_do_not_include_color_names(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('会社の魅力', $documentXml);
        $this->assertStringContainsString('会社との距離', $documentXml);
        $this->assertStringContainsString('仕事の魅力', $documentXml);
        $this->assertStringNotContainsString('青／会社の魅力', $documentXml);
        $this->assertStringNotContainsString('緑／会社との距離', $documentXml);
        $this->assertStringNotContainsString('赤／仕事の魅力', $documentXml);
    }

    public function test_includes_the_brand_wheel_framework_intro_section_even_when_brand_wheel_is_not_success(): void
    {
        $documentXml = $this->generate($this->viewModel([
            'brandWheelSelf' => $this->wheel([
                'status' => 'insufficient_input',
                'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
                'analyzed_url' => null,
                'source_pages' => ['recruit_page' => 'absent', 'home_page' => 'read'],
            ]),
            'brandWheelComparison' => ['self_points' => [], 'competitor_points' => [], 'one_point' => null],
            'selfTotalMatched' => 0,
            'selfTotalMax' => 0,
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose([], []),
        ]));

        $this->assertStringContainsString('採用ブランドの捉え方', $documentXml);
        $this->assertStringContainsString('読み取れなかった項目は、その魅力が『無い』という意味ではありません。', $documentXml);
    }

    // ------------------------------------------------------------------
    // 競合サイトの分析結果(2026-08-08新設)。
    // ------------------------------------------------------------------

    public function test_competitor_section_is_omitted_when_there_is_no_competitor_website(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringNotContainsString('競合サイトの分析結果', $documentXml);
    }

    public function test_competitor_section_uses_the_same_format_as_the_self_section_when_a_competitor_exists(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString('競合サイトの分析結果', $documentXml);
        $this->assertStringContainsString('就業環境', $documentXml);
        $this->assertStringContainsString('就業環境が最も内容として充足しています', $documentXml);
        $this->assertStringContainsString('競合サイト: 3 / 8項目', $documentXml);
    }

    // ------------------------------------------------------------------
    // ○△－の対比表(2026-08-08、●／－の2値から3値へ変更)。
    // ------------------------------------------------------------------

    public function test_comparison_section_uses_circle_triangle_and_dash_marks(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $start = mb_strpos($documentXml, '○△－の対比表');
        $this->assertNotFalse($start);
        $end = mb_strpos($documentXml, '改善提案', $start) ?: mb_strlen($documentXml);
        $sectionXml = mb_substr($documentXml, $start, $end - $start);

        $this->assertStringContainsString('○', $sectionXml);
        $this->assertStringContainsString('－', $sectionXml);
        $this->assertStringNotContainsString('●', $sectionXml);
    }

    public function test_comparison_section_shows_a_triangle_mark_for_label_only_items(): void
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4,
                'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']],
                'label_only_sub_elements' => [['key' => 'business_expansion', 'name' => '事業展開']]],
        ];
        $subElementComparison = app(BrandWheelSubElementComparisonComposer::class)->compose($selfAxes, []);

        $documentXml = $this->generate($this->viewModel([
            'brandWheelSelf' => $this->wheel(['axes' => $selfAxes]),
            'subElementComparison' => $subElementComparison,
            'selfTotalMatched' => 1,
            'selfTotalMax' => 4,
            'selfTotalLabelOnly' => 1,
        ]));

        $start = mb_strpos($documentXml, '○△－の対比表');
        $end = mb_strpos($documentXml, '改善提案', $start) ?: mb_strlen($documentXml);
        $sectionXml = mb_substr($documentXml, $start, $end - $start);

        $this->assertStringContainsString('△', $sectionXml);
    }

    public function test_comparison_section_keeps_the_required_caveat_sentence_verbatim(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString(
            '該当する記述が見つからなかった項目(『魅力が無い』という意味ではありません)',
            $documentXml,
        );
    }

    public function test_comparison_section_total_matches_the_same_source_as_the_self_results_section(): void
    {
        $viewModel = $this->comparisonViewModel();
        $documentXml = $this->generate($viewModel);

        $this->assertStringContainsString("自社サイト {$viewModel->selfTotalMatched} / {$viewModel->selfTotalMax}項目", $documentXml);
        $this->assertStringContainsString("比較サイト {$viewModel->competitorTotalMatched} / {$viewModel->competitorTotalMax}項目", $documentXml);
    }

    public function test_comparison_section_shows_the_label_only_reference_counts_separately_from_the_total(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel([
            'selfTotalLabelOnly' => 2,
            'competitorTotalLabelOnly' => 4,
        ]));

        $this->assertStringContainsString('△自社 2件', $documentXml);
        $this->assertStringContainsString('△比較 4件', $documentXml);
    }

    // ------------------------------------------------------------------
    // 改善提案。
    // ------------------------------------------------------------------

    public function test_improvement_section_keeps_the_required_sentence_verbatim(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString(
            'なお、これらを『サイトに書き足す』ことで解決するとは限りません。実態はあるのに伝えられていないのか、'
            .'まだ言葉になっていないのか ―― その切り分けについては最終ページをご覧ください。',
            $documentXml,
        );
    }

    public function test_improvement_section_shows_the_selected_group_and_competitor_evidence(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString('会社との距離', $documentXml);
        $this->assertStringContainsString('入社3年目の先輩が、日々どんな判断をしているかを紹介しています。', $documentXml);
        $this->assertStringContainsString('部署をまたいだ相談が日常的に起きる、フラットな環境です。', $documentXml);
        $this->assertStringContainsString('記述が見つかりませんでした', $documentXml);
    }

    /**
     * 旧CTA「他社比較(3〜5社)」は改善提案ページの個別項目カードとしては
     * 出さない ―― 最終ページのCTAとしては意図的に「3〜5社の競合他社」を
     * 案内するようになった(2026-08-08)ため、このアサーションは改善提案
     * セクションだけに範囲を絞る。
     */
    public function test_improvement_section_never_pitches_the_competitor_comparison_service_inline(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $start = mb_strpos($documentXml, '改善提案');
        $this->assertNotFalse($start);
        $end = mb_strpos($documentXml, 'サイトの改善をすれば', $start) ?: mb_strlen($documentXml);
        $sectionXml = mb_substr($documentXml, $start, $end - $start);

        $this->assertStringNotContainsString('他社比較', $sectionXml);
        $this->assertStringNotContainsString('3〜5社', $sectionXml);
    }

    public function test_improvement_section_shows_a_fallback_note_when_there_is_no_competitor(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('比較サイトが無いため、領域ごとの比較はご用意できません。', $documentXml);
    }

    // ------------------------------------------------------------------
    // サイトの改善をすれば課題が解決するとは限りません(最終ページ、
    // 2026-08-08新文言)。
    // ------------------------------------------------------------------

    public function test_final_section_uses_the_new_heading_and_copy(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('サイトの改善をすれば課題が解決するとは限りません', $documentXml);
        $this->assertStringContainsString(
            '弊社にてさらに幅を広げ、3〜5社の競合他社のサイトと比較した結果をもとにどこに御社課題があるかをディスカッションしませんか。',
            $documentXml,
        );
        $this->assertStringContainsString('ご相談・お問い合わせ', $documentXml);
        $this->assertStringContainsString('担当営業までご連絡ください。', $documentXml);
    }

    public function test_final_section_never_uses_the_old_heading_or_three_block_structure(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringNotContainsString('ここから先は、サイトの外の話です', $documentXml);
        $this->assertStringNotContainsString('書かれていない項目には2つの意味があります', $documentXml);
        $this->assertStringNotContainsString('私たちは採用ブランドの設計からご一緒します', $documentXml);
    }
}
