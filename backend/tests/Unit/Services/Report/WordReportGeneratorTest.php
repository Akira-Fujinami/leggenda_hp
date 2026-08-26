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
            'positive_impression' => null,
            'negative_impression' => null,
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
            'groupTotals' => [],
            'comparisonOverview' => [],
            'improvementFocus' => null,
            'improvementOnePoint' => '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。',
            'improvementRecommendation' => null,
            'improvementReason' => null,
            'improvementRecommendedContents' => [],
            'improvementMidTermAction' => null,
            'selfLowContentNotice' => null,
            'crawlSiteEnabled' => false,
            'selfEvidenceByAxis' => [],
        ];
        $defaults['improvementFocusSelfOnly'] = app(BrandWheelImprovementFocusComposer::class)->composeSelfOnly($defaults['subElementComparison']);

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
        $groupTotals = $comparisonComposer->groupTotals($subElementComparison);
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
            'groupTotals' => $groupTotals,
            'comparisonOverview' => app(\App\Services\BrandWheel\BrandWheelComparisonSummaryComposer::class)
                ->comparisonOverview(1, 4, 3, 8, $groupTotals),
            'improvementFocus' => $improvementFocus,
            'improvementFocusSelfOnly' => null,
            'improvementOnePoint' => '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。',
            'improvementRecommendation' => 'まずは会社との距離に関する情報を拡充することを推奨します。競合との差別化余地が大きい一方、社員インタビュー等が必要となる可能性があります。',
            'improvementReason' => '就業環境は競合が2件読み取れているのに対し自社は0件で、候補者が働くイメージを持ちにくい状態です。',
            'improvementRecommendedContents' => ['入社数年目の社員の1日の過ごし方', '部署間の関わり方が分かるエピソード'],
            'improvementMidTermAction' => '中長期的には、部署横断プロジェクトの事例をシリーズ化することも検討できます。',
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

    /**
     * addLink()の実際のURL(href)はword/document.xmlには埋め込まれず、
     * word/_rels/document.xml.relsのリレーションシップとして格納される
     * (document.xml側にはリンクテキストのみが現れる)。CTAページのボタンは
     * リンクテキストをURL文字列ではなくラベル(「競合比較について相談する」)に
     * したため(依頼者指定)、実際のhrefはこちらで検証する。
     */
    private function generateRelsXml(ReportViewModel $viewModel): string
    {
        $docx = app(WordReportGenerator::class)->generate($viewModel);
        $tempPath = tempnam(sys_get_temp_dir(), 'word-report-test-').'.docx';
        file_put_contents($tempPath, $docx);

        $zip = new ZipArchive;
        $zip->open($tempPath);
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
        $zip->close();
        unlink($tempPath);

        $this->assertNotFalse($relsXml);

        return $relsXml;
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
        $this->assertStringContainsString('活動的魅力が最も内容として充足しています', $documentXml);
        // 2026-08-18: 読み手向けUIにはAI利用の開示テキストを一切出さない(依頼者指定)。
        $this->assertStringNotContainsString('AIを使用', $documentXml);
        $this->assertStringNotContainsString('AI解析', $documentXml);
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

    /**
     * 依頼Q-1: PDF版と同じ出し分け。crawl_site=falseでは従来どおりの文言。
     */
    public function test_intro_section_shows_the_crawl_disabled_scope_notice_by_default(): void
    {
        $documentXml = $this->generate($this->viewModel(['crawlSiteEnabled' => false]));

        $this->assertStringContainsString((string) config('brand_wheel.crawl_disabled_scope_notice'), $documentXml);
        $this->assertStringNotContainsString((string) config('brand_wheel.crawl_enabled_scope_notice'), $documentXml);
    }

    /**
     * 依頼Q-1最重要: crawl_site=trueでは「巡回していない」という事実と
     * 異なる文言を出さず、巡回ありの文言に切り替わること。PDFだけでなく
     * Word版にも同じ対応を入れること(依頼者指定)。
     */
    public function test_intro_section_shows_the_crawl_enabled_scope_notice_when_crawl_site_is_enabled(): void
    {
        $documentXml = $this->generate($this->viewModel(['crawlSiteEnabled' => true]));

        $this->assertStringContainsString((string) config('brand_wheel.crawl_enabled_scope_notice'), $documentXml);
        $this->assertStringNotContainsString((string) config('brand_wheel.crawl_disabled_scope_notice'), $documentXml);
        $this->assertStringNotContainsString('50ページ', $documentXml);
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
        $this->assertStringContainsString('競合サイト　確認できた情報：3 / 8項目', $documentXml);
    }

    /**
     * 2026-08-18: 取得できなかった競合サイトについて「該当する記述が見つから
     * なかった」という空のセクションを出すのをやめ、セクション自体を出さない
     * ように変更(依頼者指定 ―― ゴディバの403調査から派生した誤記載の修正、
     * PDF版lead-pdf.blade.phpと同内容)。以前はcompetitorWebsiteUrlの有無
     * だけで分岐していたため、URLはあるが読み取れなかった場合に、この空
     * セクションが出力されてしまっていた。
     */
    public function test_competitor_section_is_omitted_when_the_competitor_url_exists_but_is_not_readable(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel([
            'brandWheelCompetitor' => $this->wheel([
                'status' => 'insufficient_input',
                'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
            ]),
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 0,
        ]));

        $this->assertStringNotContainsString('競合サイトの分析結果', $documentXml);
        $this->assertStringNotContainsString('サイトから十分な文章を読み取れなかったため', $documentXml);
    }

    /**
     * 依頼O-2/P-3(2026-08-25): PDF版と同じ注記をWord版の表紙にも入れる
     * (依頼者指定 ―― PDFだけ直してWordが残る、という状態にしない)。
     */
    public function test_cover_section_shows_the_url_and_a_notice_when_the_competitor_is_not_readable(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel([
            'brandWheelCompetitor' => $this->wheel([
                'status' => 'insufficient_input',
                'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
            ]),
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 0,
        ]));

        $this->assertStringContainsString('比較サイト: https://competitor.example.com', $documentXml);
        $this->assertStringContainsString(config('brand_wheel.cover_competitor_unreadable_notice'), $documentXml);
    }

    public function test_cover_section_omits_the_notice_when_the_competitor_is_readable(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringNotContainsString(config('brand_wheel.cover_competitor_unreadable_notice'), $documentXml);
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

    public function test_comparison_section_does_not_show_a_zero_over_zero_competitor_line_when_the_competitor_is_not_readable(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel([
            'brandWheelCompetitor' => $this->wheel([
                'status' => 'insufficient_input',
                'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
            ]),
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 0,
        ]));

        $this->assertStringNotContainsString('比較サイト 0 / 0項目', $documentXml);
    }

    // ------------------------------------------------------------------
    // ○と判定した根拠(依頼R、2026-08-26新設)。
    // ------------------------------------------------------------------

    public function test_evidence_section_is_omitted_when_self_evidence_by_axis_is_empty(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringNotContainsString('○と判定した根拠', $documentXml);
    }

    /**
     * 依頼R: matchedが複数軸にまたがるとき、軸ごとにまとめて対比表と
     * 同じ順序で表示されること。導入文はconfig由来(PDF版と同内容)。
     */
    public function test_evidence_section_shows_axis_grouped_quotes_in_config_order(): void
    {
        $selfEvidenceByAxis = [
            ['axis_name' => '活動的魅力', 'items' => [
                ['sub_name' => 'パーパス', 'evidence' => 'パーパスの原文抜粋です。'],
            ]],
            ['axis_name' => '経営スタイル', 'items' => [
                ['sub_name' => 'リーダーシップ', 'evidence' => 'リーダーシップの原文抜粋です。'],
            ]],
        ];

        $documentXml = $this->generate($this->viewModel(['selfEvidenceByAxis' => $selfEvidenceByAxis]));

        $this->assertStringContainsString('○と判定した根拠', $documentXml);
        $this->assertStringContainsString(config('brand_wheel.evidence_page_intro'), $documentXml);
        $this->assertStringContainsString('「パーパスの原文抜粋です。」', $documentXml);
        $this->assertStringContainsString('「リーダーシップの原文抜粋です。」', $documentXml);

        $posWillActivity = mb_strpos($documentXml, '活動的魅力');
        $posPersonality = mb_strpos($documentXml, '経営スタイル');
        $this->assertTrue($posWillActivity < $posPersonality);
    }

    /**
     * 依頼R(2026-08-26で判明した既存バグの修正込み): 引用に&が含まれていても
     * XMLとして正しくエスケープされること。PhpWordは既定(Settings::
     * $outputEscapingEnabled=false)ではaddText()の内容をエスケープせず、
     * 生の&を含むテキストがあるとdocument.xml自体が不正なXMLになり
     * Wordで開けなくなる不具合があった(このメソッド内の全addText()呼び出しに
     * 及ぶ潜在バグ、依頼Rの実装中に発覚)。DOMDocument::loadXML()で
     * document.xml自体が整形式(well-formed)であることまで確認する
     * (文字列に「&amp;」が含まれているかどうかの表面的な確認だけでは、
     * 実際に不正なXMLになっていないことまでは保証できないため)。
     */
    public function test_evidence_section_escapes_special_characters_in_the_quote(): void
    {
        $documentXml = $this->generate($this->viewModel([
            'selfEvidenceByAxis' => [
                ['axis_name' => '活動的魅力', 'items' => [
                    ['sub_name' => 'パーパス', 'evidence' => '採用 & 育成'],
                ]],
            ],
        ]));

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($documentXml), 'document.xmlが整形式のXMLとして読み込めること');

        $this->assertStringNotContainsString('採用 & 育成', $documentXml);
        $this->assertStringContainsString('採用 &amp; 育成', $documentXml);
    }

    // ------------------------------------------------------------------
    // 改善提案。
    // ------------------------------------------------------------------

    /**
     * 2026-08-19: 旧文言はPDF版の改善提案ページで末尾1文だけが次ページへ
     * あふれる不具合の原因だったため短縮した(依頼者承認、PDF版と同内容)。
     */
    public function test_improvement_section_keeps_the_shortened_closing_note_verbatim(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString('サイト上の情報追加だけでなく、実態として存在する魅力の整理も重要です。', $documentXml);
        $this->assertStringNotContainsString('なお、これらを『サイトに書き足す』ことで解決するとは限りません', $documentXml);
    }

    public function test_improvement_section_shows_the_selected_group_and_competitor_evidence(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString('会社との距離', $documentXml);
        $this->assertStringContainsString('入社3年目の先輩が、日々どんな判断をしているかを紹介しています。', $documentXml);
        $this->assertStringContainsString('部署をまたいだ相談が日常的に起きる、フラットな環境です。', $documentXml);
        $this->assertStringContainsString('（現在、サイトからは読み取れませんでした）', $documentXml);
    }

    /**
     * 依頼X-1〜X-4(2026-08-26、レポート42): PDF版と同内容
     * (LeadPdfViewTest::test_improvement_page_shows_the_no_candidate_message_and_does_not_disappear_when_self_leads_every_group参照)。
     */
    public function test_improvement_section_shows_the_no_candidate_message_and_does_not_disappear_when_self_leads_every_group(): void
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 4, 'max_count' => 4, 'matched_sub_elements' => [
                ['key' => 'purpose', 'name' => 'パーパス'], ['key' => 'business_expansion', 'name' => '展開事業・商品'],
                ['key' => 'project_initiative', 'name' => 'PJ・新たな取組'], ['key' => 'social_contribution', 'name' => '社会貢献活動'],
            ], 'label_only_sub_elements' => []],
        ];
        $competitorAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [
                ['key' => 'purpose', 'name' => 'パーパス'], ['key' => 'business_expansion', 'name' => '展開事業・商品'],
            ], 'label_only_sub_elements' => []],
        ];

        $comparisonComposer = app(BrandWheelSubElementComparisonComposer::class);
        $subElementComparison = $comparisonComposer->compose($selfAxes, $competitorAxes);
        $groupTotals = $comparisonComposer->groupTotals($subElementComparison);
        $improvementFocus = app(BrandWheelImprovementFocusComposer::class)->compose($subElementComparison, []);

        $this->assertSame([], $improvementFocus['items']);

        $documentXml = $this->generate($this->comparisonViewModel([
            'brandWheelSelf' => $this->wheel(['axes' => $selfAxes]),
            'brandWheelCompetitor' => $this->wheel(['axes' => $competitorAxes]),
            'subElementComparison' => $subElementComparison,
            'groupTotals' => $groupTotals,
            'improvementFocus' => $improvementFocus,
            'improvementOnePoint' => $improvementFocus['lead_text'],
            'improvementReason' => null,
        ]));

        $this->assertStringContainsString((string) config('brand_wheel.improvement_focus_templates.no_candidate_self_ahead'), $documentXml);
        $this->assertStringNotContainsString('0件挙げます', $documentXml);
        $this->assertStringNotContainsString('該当する項目はありませんでした', $documentXml);
        $this->assertStringContainsString('改善提案', $documentXml);
        $this->assertStringContainsString('会社の魅力', $documentXml);
    }

    /**
     * 2026-08-25(修正: 所見→提案): カードの本文は判定用の定義文
     * (sub_element_definitions)ではなく、行動を促す提案文
     * (config('brand_wheel.axes.*.sub_element_recommendations'))を表示する。
     * 競合ありのケースでも、提案文と競合の引用の両方が出る。
     */
    public function test_improvement_section_shows_the_recommendation_text_alongside_competitor_evidence(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString('実際に働いている人を、名前と経歴つきで紹介してください。', $documentXml);
        $this->assertStringContainsString('普段のオフィスの様子や、チームの空気が伝わる描写を載せてください。', $documentXml);
        $this->assertStringContainsString('入社3年目の先輩が、日々どんな判断をしているかを紹介しています。', $documentXml);
        $this->assertStringNotContainsString('同僚や先輩がどのような人物かについての具体的な記述。', $documentXml);
    }

    public function test_improvement_section_self_only_cards_show_the_recommendation_text(): void
    {
        $documentXml = $this->generate($this->viewModel());

        // viewModel()のfixtureはwill_activity(パーパス)のみ○、残り23項目が－。
        // company_distanceグループ(personality+relationship、8件とも－で最多)の
        // 上位3件(リーダーシップ/組織構造/会社の性格)の行動文が出る。
        $this->assertStringContainsString('経営者がどんな考えで会社を率いているかを、本人の言葉で載せてください。', $documentXml);
        $this->assertStringNotContainsString('部門・チームの編成、階層、意思決定の通り方についての記述。', $documentXml);
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
        $end = mb_strpos($documentXml, 'さらに3〜5社の競合採用サイトと比較し', $start) ?: mb_strlen($documentXml);
        $sectionXml = mb_substr($documentXml, $start, $end - $start);

        $this->assertStringNotContainsString('他社比較', $sectionXml);
        $this->assertStringNotContainsString('3〜5社', $sectionXml);
    }

    /**
     * 2026-08-10: 「比較サイトが無いため、領域ごとの比較はご用意できません。」
     * の1行だけでページの大半が空白になり、営業資料として成立しないという
     * 指摘(ユーザー)を受け、競合が無い場合も自社の「－」「△」項目で構成した
     * カードを出す形に置き換えた(PDF版と同内容)。
     */
    public function test_improvement_section_shows_self_only_cards_when_there_is_no_competitor(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringNotContainsString('比較サイトが無いため、領域ごとの比較はご用意できません。', $documentXml);
        $this->assertStringContainsString('サイトの記述から読み取れた項目が最も少なかったのは', $documentXml);
        $this->assertStringNotContainsString('比較サイトの記述：', $documentXml);
    }

    /**
     * 依頼Q-2(2026-08-25): improvementFocusSelfOnly['items_source']が
     * 'ai'のとき、カードはitems(改善提案AI由来)をそのまま表示し、規則側の
     * 主張である「最も少なかったのは〜」の一文は出さないこと(PDF版と同内容)。
     */
    public function test_improvement_section_shows_ai_sourced_self_only_cards_without_the_rule_sentence(): void
    {
        $ruleFocus = $this->viewModel()->improvementFocusSelfOnly;
        $aiFocus = $ruleFocus;
        $aiFocus['items_source'] = 'ai';
        $aiFocus['items'] = [
            ['axis_name' => '経営スタイル', 'sub_name' => '重視する価値', 'definition' => '組織として大切にしている価値観や行動指針についての記述。', 'recommendation' => 'AIが選んだ提案文です。', 'self_reason' => 'none'],
        ];

        $documentXml = $this->generate($this->viewModel(['improvementFocusSelfOnly' => $aiFocus]));

        $this->assertStringContainsString('AIが選んだ提案文です。', $documentXml);
        $this->assertStringNotContainsString('サイトの記述から読み取れた項目が最も少なかったのは', $documentXml);
    }

    /**
     * 自社24項目すべてが○の場合、composeSelfOnly()はnullを返し、このセクション
     * 自体を丸ごと省略する(見出しだけの空セクションを作らない、2026-08-10)。
     */
    public function test_improvement_section_is_omitted_when_self_has_no_competitor_and_all_items_matched(): void
    {
        $allMatchedAxes = collect((array) config('brand_wheel.axes'))
            ->map(fn (array $axis, string $key) => [
                'key' => $key,
                'group' => $axis['group'],
                'name' => $axis['name_ja'],
                'matched_count' => count($axis['sub_elements']),
                'max_count' => count($axis['sub_elements']),
                'matched_sub_elements' => collect($axis['sub_elements'])->map(fn ($name, $subKey) => ['key' => $subKey, 'name' => $name])->values()->all(),
                'label_only_sub_elements' => [],
            ])
            ->values()
            ->all();
        $subElementComparison = app(BrandWheelSubElementComparisonComposer::class)->compose($allMatchedAxes, []);

        $documentXml = $this->generate($this->viewModel([
            'brandWheelSelf' => $this->wheel(['axes' => $allMatchedAxes]),
            'subElementComparison' => $subElementComparison,
            'improvementFocusSelfOnly' => app(BrandWheelImprovementFocusComposer::class)->composeSelfOnly($subElementComparison),
        ]));

        $this->assertStringNotContainsString('改善提案', $documentXml);
    }

    // ------------------------------------------------------------------
    // サイトの改善をすれば課題が解決するとは限りません(最終ページ、
    // 2026-08-08新文言)。
    // ------------------------------------------------------------------

    /**
     * 2026-08-17: 長い説明文を削除し、営業CTAに集中させた(依頼者指定)。
     * URLは生の文字列としてではなくボタン風のラベル付きリンクで表示する。
     */
    public function test_final_section_uses_the_new_ctacopy(): void
    {
        $viewModel = $this->viewModel();
        $documentXml = $this->generate($viewModel);

        $this->assertStringContainsString('さらに3〜5社の競合採用サイトと比較し', $documentXml);
        $this->assertStringContainsString('御社が優先して改善すべき課題を整理しませんか', $documentXml);
        $this->assertStringContainsString('詳細な比較結果をもとに、採用課題についてディスカッションします。', $documentXml);
        $this->assertStringContainsString('競合比較について相談する', $documentXml);
        $this->assertStringContainsString("お問い合わせの際は、本レポートの発行日（{$viewModel->generatedAtLabel}）と貴社名をお知らせください。", $documentXml);
        $this->assertStringNotContainsString('leggenda-co.web-tools.biz', $documentXml);
        $this->assertStringNotContainsString('お電話', $documentXml);
        // URL文字列自体はdocument.xmlには現れず、hrefとして.relsに格納される
        // (依頼者指定: URLをそのまま長く表示しない)。
        $this->assertStringNotContainsString('https://leggenda-co.web-tools.biz/inquiry', $documentXml);
        $this->assertStringContainsString('https://leggenda-co.web-tools.biz/inquiry', $this->generateRelsXml($viewModel));
    }

    public function test_final_section_never_uses_old_copy_variants(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringNotContainsString('ここから先は、サイトの外の話です', $documentXml);
        $this->assertStringNotContainsString('書かれていない項目には2つの意味があります', $documentXml);
        $this->assertStringNotContainsString('私たちは採用ブランドの設計からご一緒します', $documentXml);
        $this->assertStringNotContainsString('サイトの改善をすれば課題が解決するとは限りません', $documentXml);
    }

    // ------------------------------------------------------------------
    // 2026-08-17改修分。
    // ------------------------------------------------------------------

    public function test_self_analysis_section_shows_positive_and_negative_impression(): void
    {
        $documentXml = $this->generate($this->viewModel([
            'brandWheelSelf' => $this->wheel([
                'axes' => [
                    ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
                ],
                'positive_impression' => '事業内容への取り組み姿勢が伝わり、良い印象を与える可能性があります。',
                'negative_impression' => '働く環境の具体像がイメージしづらい可能性があります。',
            ]),
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose([
                ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
            ], []),
        ]));

        $this->assertStringContainsString('ポジティブな印象', $documentXml);
        $this->assertStringContainsString('ネガティブな印象', $documentXml);
        $this->assertStringNotContainsString('候補者に与える印象', $documentXml);
        $this->assertStringNotContainsString('AI解析による候補者に与える印象', $documentXml);
        $this->assertStringContainsString('事業内容への取り組み姿勢が伝わり、良い印象を与える可能性があります。', $documentXml);
        $this->assertStringContainsString('働く環境の具体像がイメージしづらい可能性があります。', $documentXml);
    }

    public function test_comparison_section_shows_overview_summary_when_a_competitor_exists(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString('比較結果サマリー', $documentXml);
        $this->assertStringContainsString('自社は1 / 4項目、競合は3 / 8項目の情報が確認できました。', $documentXml);
    }

    /**
     * 2026-08-18: 旧「改善のご提案」単一パラグラフから、理由/中長期の差別化
     * ポイントのブロック構成へ変更(依頼者指定、PDF版と同内容)。
     * 2026-08-19: 「中長期的には：」の1行を、「中長期の差別化ポイント」と
     * いう独立した見出し付きブロックへ格上げ(依頼者指定、PDF版の.diffboxと
     * 同内容)。
     * 依頼Q-2(2026-08-25): 「具体的に追加すべき情報」の箇条書きは廃止した
     * (カードと内容が重複するため、PDF版と同内容)。
     */
    public function test_improvement_section_shows_reason_and_differentiation_point_when_available(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString('理由：', $documentXml);
        $this->assertStringContainsString('就業環境は競合が2件読み取れているのに対し自社は0件で、候補者が働くイメージを持ちにくい状態です。', $documentXml);
        $this->assertStringContainsString('中長期の差別化ポイント', $documentXml);
        $this->assertStringNotContainsString('中長期的には：', $documentXml);
        $this->assertStringContainsString('部署横断プロジェクトの事例をシリーズ化することも検討できます。', $documentXml);
        // 依頼Q-2: comparisonViewModel()のfixtureはimprovementRecommendedContentsに
        // 値を持つが、「具体的に追加すべき情報」ブロック自体はもう出ない。
        $this->assertStringNotContainsString('具体的に追加すべき情報', $documentXml);
        $this->assertStringNotContainsString('入社数年目の社員の1日の過ごし方', $documentXml);
    }

    /**
     * 依頼S(2026-08-26): improvementMidTermActionがnull(パーサ側で
     * 文字列"null"がnullに変換された結果を含む)のとき、「中長期の
     * 差別化ポイント」ブロックごと描画されないこと(PDF版と同内容 ――
     * LeadPdfViewTest::test_improvement_page_omits_each_ai_block_independently_
     * when_unavailable()参照)。
     */
    public function test_improvement_section_omits_the_mid_term_action_block_when_it_is_null(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel([
            'improvementReason' => null,
            'improvementMidTermAction' => null,
        ]));

        $this->assertStringNotContainsString('理由：', $documentXml);
        $this->assertStringNotContainsString('中長期の差別化ポイント', $documentXml);
        $this->assertStringNotContainsString('null', $documentXml);
    }

    /**
     * 修正3(2026-08-25): groupTotals/comparisonOverviewが空配列のとき
     * (ReportViewModelBuilderが自社/競合いずれかの閾値未満で空にする)、
     * Word版も比較結果サマリー自体を出さない(PDF版と同じ挙動)。
     */
    public function test_comparison_section_omits_overview_summary_when_group_totals_and_overview_are_empty_despite_a_competitor_existing(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel([
            'groupTotals' => [],
            'comparisonOverview' => [],
        ]));

        $this->assertStringNotContainsString('比較結果サマリー', $documentXml);
    }

    /**
     * 修正5(2026-08-25): 自社の合計matched件数が閾値未満のときの但し書き
     * (config('brand_wheel.self_low_content_notice'))がWord版にも出る。
     */
    public function test_self_analysis_section_shows_the_low_content_notice_when_present(): void
    {
        $notice = 'このページから読み取れた本文が少なかったため、確認できた項目数が少なくなっています。採用サイトのトップページなど、文章量の多いページをご指定いただくと、より詳しい診断が可能です。';

        $documentXml = $this->generate($this->comparisonViewModel(['selfLowContentNotice' => $notice]));

        $this->assertStringContainsString($notice, $documentXml);
    }

    public function test_self_analysis_section_omits_the_low_content_notice_by_default(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringNotContainsString('文章量の多いページをご指定いただくと', $documentXml);
    }
}
