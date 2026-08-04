<?php

namespace Tests\Unit\Services\Report;

use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use App\Services\Report\WordReportGenerator;
use App\Support\Lead\LeadMetricCatalog;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;
use Tests\TestCase;
use ZipArchive;

/**
 * 2026-08-04: docs/lead-report-layout/README.mdを唯一の定義元に、PDF版
 * (lead-pdf.blade.php)の9ページ構成へ全面書き直し。このテストファイルも
 * それに合わせて全面書き直し(旧「他社ページ比較とのまとめ」関連のテストは
 * 削除、24項目の対比・触れられていなかった項目・改善提案の新設セクション分を
 * 追加)。
 */
class WordReportGeneratorTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function perspectives(): array
    {
        return [
            [
                'key' => LeadMetricCatalog::PERSPECTIVE_COMPLETENESS,
                'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_COMPLETENESS],
                'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[LeadMetricCatalog::PERSPECTIVE_COMPLETENESS],
                'note' => LeadMetricCatalog::COMPLETENESS_LEGAL_ITEMS_NOTE,
                'status' => 'not_detected',
                'summary' => '採用ページを検出できませんでした。トップページに採用に関する案内が見つからなかったため、この観点は今回「計測対象外」です。',
                'items' => [],
                'one_liner' => '採用ページを検出できませんでした。トップページに採用に関する案内が見つからなかったため、この観点は今回「計測対象外」です。',
            ],
            [
                'key' => LeadMetricCatalog::PERSPECTIVE_CLARITY,
                'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_CLARITY],
                'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[LeadMetricCatalog::PERSPECTIVE_CLARITY],
                'note' => null,
                'status' => 'good',
                'items' => [
                    ['label' => 'ページタイトルの設定', 'status' => 'good', 'detail' => null],
                ],
                'one_liner' => '確認した1項目に大きな問題は見つかりませんでした。',
            ],
        ];
    }

    private function viewModel(array $overrides = []): ReportViewModel
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']]],
        ];

        $defaults = [
            'companyDisplayName' => '株式会社サンプル様',
            'generatedAtLabel' => '2026年7月27日',
            'selfWebsiteUrl' => 'https://example.com',
            'competitorWebsiteUrl' => null,
            'selfScore' => ['display_score' => 76, 'configured_max_score' => 100, 'coverage_rate' => 92.5, 'confidence_rate' => 88.0],
            'competitorScore' => null,
            'overallSummaryText' => '株式会社サンプル様の自社サイトは、総合スコア76点(100点満点)という結果になりました。',
            'comparisonSentence' => null,
            'perspectives' => $this->perspectives(),
            'topRecommendations' => [
                new ReportRecommendationRow('画像を圧縮してください', '表示速度の改善につながります。', '緊急', '高', '小'),
            ],
            'isPartial' => false,
            'brandWheelSelf' => [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://example.com/careers',
                'axes' => $selfAxes,
                'key_message' => '技術で社会基盤を支える、という主題が置かれています。',
                'impression' => '情緒的便益の記述が薄いのがもったいないところです。',
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
            'brandWheelCompetitor' => null,
            'brandWheelComparison' => [
                'self_points' => ['活動的魅力が最も内容として充足しています。'],
                'one_point' => ['key' => 'well_covered', 'text' => '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。'],
            ],
            'brandWheelRadarPng' => null,
            'selfBrandWheelEvidenceItems' => [
                ['axis_key' => 'will_activity', 'axis_name' => '活動的魅力', 'group' => 'company_appeal', 'sub_element_name' => 'パーパス', 'evidence' => '技術で社会の「あたり前」を支える。それが私たちの存在意義です。'],
            ],
            'selfTotalMatched' => 2,
            'selfTotalMax' => 4,
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 0,
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose($selfAxes, []),
            'gapAnalysis' => ['a' => [], 'b' => [], 'c' => []],
            'improvementFocus' => null,
        ];

        $values = array_merge($defaults, $overrides);

        return new ReportViewModel(...$values);
    }

    /**
     * 自社・競合ともにブランド・ホイールが揃っている状態のfixture
     * (24項目の対比・触れられていなかった項目・改善提案セクション用)。
     * LeadPdfViewTest::comparisonViewModel()と同じ組み立て方。
     */
    private function comparisonViewModel(array $overrides = []): ReportViewModel
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']]],
        ];
        $competitorAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']]],
            ['key' => 'relationship', 'group' => 'company_distance', 'name' => '就業環境', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [
                ['key' => 'colleagues', 'name' => '同僚・先輩像'], ['key' => 'atmosphere', 'name' => '職場の雰囲気'],
            ]],
        ];

        $comparisonComposer = app(BrandWheelSubElementComparisonComposer::class);
        $subElementComparison = $comparisonComposer->compose($selfAxes, $competitorAxes);
        $gapAnalysis = $comparisonComposer->splitByGap($subElementComparison);
        $improvementFocus = app(BrandWheelImprovementFocusComposer::class)->compose($subElementComparison, [
            'relationship' => [
                'colleagues' => '入社3年目の先輩が、日々どんな判断をしているかを紹介しています。',
                'atmosphere' => '部署をまたいだ相談が日常的に起きる、フラットな環境です。',
            ],
        ]);

        return $this->viewModel(array_merge([
            'competitorWebsiteUrl' => 'https://competitor.example.com',
            'brandWheelSelf' => [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://example.com/careers',
                'axes' => $selfAxes,
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
            'brandWheelCompetitor' => [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://competitor.example.com/careers',
                'axes' => $competitorAxes,
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
            'selfTotalMatched' => 1,
            'selfTotalMax' => 4,
            'competitorTotalMatched' => 3,
            'competitorTotalMax' => 8,
            'subElementComparison' => $subElementComparison,
            'gapAnalysis' => $gapAnalysis,
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
        $this->assertStringContainsString('総合結果', $documentXml);
        $this->assertStringContainsString('計測対象外', $documentXml);
        $this->assertStringContainsString('書くべきことが書けているか', $documentXml);
        $this->assertStringNotContainsString('メッセージの分かりやすさ', $documentXml);
        $this->assertStringNotContainsString('job_type', $documentXml);
        $this->assertStringNotContainsString('error_code', $documentXml);
    }

    public function test_never_leaks_individual_metric_labels_in_the_perspectives_section(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringNotContainsString('ページタイトルの設定', $documentXml);
    }

    public function test_states_the_not_counted_as_zero_caveat_with_coverage_and_confidence(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('0点として扱わず', $documentXml);
        $this->assertStringContainsString('92.5', $documentXml);
        $this->assertStringContainsString('88.0', $documentXml);
    }

    public function test_shows_the_reference_score_badge_when_coverage_is_below_70_percent(): void
    {
        $documentXml = $this->generate($this->viewModel([
            'selfScore' => ['display_score' => 40, 'configured_max_score' => 100, 'coverage_rate' => 69.9, 'confidence_rate' => 60.0],
        ]));

        $this->assertStringContainsString('参考スコア', $documentXml);
    }

    public function test_includes_the_self_analysis_results_section_with_counts_and_summary(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('自社ページの分析結果', $documentXml);
        $this->assertStringContainsString('活動的魅力', $documentXml);
        $this->assertStringContainsString('2 / 4件', $documentXml);
        $this->assertStringContainsString('パーパス', $documentXml);
        $this->assertStringContainsString('技術で社会基盤を支える', $documentXml);
        $this->assertStringContainsString('情緒的便益の記述が薄い', $documentXml);
        $this->assertStringContainsString('活動的魅力が最も内容として充足しています', $documentXml);
        $this->assertStringContainsString('AIを使用', $documentXml);
    }

    /**
     * 2026-08-04: 0件の軸セルは「該当する記述は見つかりませんでした」と
     * 明示する(README 117行、PDF版の.none2と表記を揃える)。旧実装が
     * '―'のままだった食い違いの回帰テスト。
     */
    public function test_zero_matched_axis_shows_the_no_evidence_message_not_a_dash(): void
    {
        $documentXml = $this->generate($this->viewModel([
            'brandWheelSelf' => [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://example.com/careers',
                'axes' => [
                    ['key' => 'asset', 'group' => 'company_appeal', 'name' => '資産的魅力', 'matched_count' => 0, 'max_count' => 4, 'matched_sub_elements' => []],
                ],
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
        ]));

        // '―'はドキュメント全体では他の見出し・強調(――)で正当に使われるため、
        // グローバルな不在チェックはしない。0件セルの文言そのものを確認すれば足りる。
        $this->assertStringContainsString('該当する記述は見つかりませんでした', $documentXml);
    }

    public function test_does_not_render_the_brand_wheel_table_when_status_is_not_success(): void
    {
        $documentXml = $this->generate($this->viewModel([
            'brandWheelSelf' => [
                'status' => 'recruit_page_unreadable',
                'status_message' => '採用ページの内容を取得できなかったため、この項目の分析は行っていません。',
                'analyzed_url' => 'https://example.com/careers',
                'axes' => [],
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'unreadable', 'home_page' => 'read'],
            ],
            'brandWheelComparison' => ['self_points' => [], 'one_point' => null],
            'selfTotalMatched' => 0,
            'selfTotalMax' => 0,
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose([], []),
            'selfBrandWheelEvidenceItems' => [],
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
            'brandWheelSelf' => [
                'status' => 'insufficient_input',
                'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
                'analyzed_url' => null,
                'axes' => [],
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'absent', 'home_page' => 'read'],
            ],
            'brandWheelComparison' => ['self_points' => [], 'one_point' => null],
            'selfTotalMatched' => 0,
            'selfTotalMax' => 0,
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose([], []),
            'selfBrandWheelEvidenceItems' => [],
        ]));

        $this->assertStringContainsString('採用ブランドの捉え方', $documentXml);
        $this->assertStringContainsString('読み取れなかった項目は、その魅力が『無い』という意味ではありません。', $documentXml);
    }

    public function test_includes_the_evidence_section_with_verbatim_text_when_there_are_matched_items(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('サイトから読み取れた記述', $documentXml);
        $this->assertStringContainsString('活動的魅力', $documentXml);
        $this->assertStringContainsString('パーパス', $documentXml);
        $this->assertStringContainsString('技術で社会の「あたり前」を支える。それが私たちの存在意義です。', $documentXml);
    }

    public function test_omits_the_evidence_section_entirely_when_there_are_no_evidence_items(): void
    {
        $documentXml = $this->generate($this->viewModel(['selfBrandWheelEvidenceItems' => []]));

        $this->assertStringNotContainsString('サイトから読み取れた記述', $documentXml);
    }

    // ------------------------------------------------------------------
    // 24項目の対比(2026-08-04新設)。
    // ------------------------------------------------------------------

    public function test_comparison_section_uses_filled_and_dash_marks_not_circle_cross(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $start = mb_strpos($documentXml, '24項目の対比');
        $this->assertNotFalse($start);
        $end = mb_strpos($documentXml, 'サイトで触れられていなかった項目', $start) ?: mb_strlen($documentXml);
        $sectionXml = mb_substr($documentXml, $start, $end - $start);

        $this->assertStringContainsString('●', $sectionXml);
        $this->assertStringContainsString('－', $sectionXml);
        $this->assertStringNotContainsString('○', $sectionXml);
    }

    public function test_comparison_section_keeps_the_required_caveat_sentence_verbatim(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString(
            '－は『その魅力が無い』という意味ではなく、そのサイトでは触れられていなかった、という意味です。',
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

    // ------------------------------------------------------------------
    // サイトで触れられていなかった項目(2026-08-04新設)。
    // ------------------------------------------------------------------

    public function test_gap_section_keeps_the_required_lead_sentence_verbatim(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString(
            '書かれていないことが弱みという意味ではありません。候補者が2つのサイトを見比べたとき、'
            .'その情報を比較サイト側でしか得られない、という事実を示しています。',
            $documentXml,
        );
    }

    public function test_gap_section_always_shows_section_b_even_when_there_are_zero_self_only_items(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString('御社のサイトにあり、比較サイトでは触れられていなかった項目', $documentXml);
        $this->assertStringContainsString('該当する項目はありませんでした', $documentXml);
    }

    public function test_gap_section_shows_a_fallback_message_when_there_is_no_competitor(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('比較サイトが指定されていないため', $documentXml);
    }

    // ------------------------------------------------------------------
    // 改善提案(2026-08-04新設、ブランド・ホイール起点)。
    // ------------------------------------------------------------------

    public function test_improvement_section_keeps_both_required_sentences_verbatim(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringContainsString(
            'なお、これらを『サイトに書き足す』ことで解決するとは限りません。実態はあるのに伝えられていないのか、'
            .'まだ言葉になっていないのか ―― その切り分けについては最終ページをご覧ください。',
            $documentXml,
        );
        $this->assertStringContainsString(
            'いずれもサイトの作りに関するもので、上の『何を書くか』とは別の話です。',
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

    public function test_improvement_section_never_offers_comparing_three_to_five_competitor_sites(): void
    {
        $documentXml = $this->generate($this->comparisonViewModel());

        $this->assertStringNotContainsString('他社比較', $documentXml);
        $this->assertStringNotContainsString('3〜5社', $documentXml);
    }

    public function test_improvement_section_shows_a_fallback_note_when_there_is_no_competitor(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('比較サイトが無いため、領域ごとの比較はご用意できません。', $documentXml);
    }

    // ------------------------------------------------------------------
    // ここから先は、サイトの外の話です(2026-08-04新設)。
    // ------------------------------------------------------------------

    public function test_final_section_uses_the_new_heading_and_never_offers_the_old_site_comparison_cta(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('ここから先は、サイトの外の話です', $documentXml);
        $this->assertStringNotContainsString('他社比較', $documentXml);
    }

    public function test_final_section_includes_the_three_block_structure(): void
    {
        $documentXml = $this->generate($this->viewModel());

        $this->assertStringContainsString('書かれていない項目には2つの意味があります', $documentXml);
        $this->assertStringContainsString('その切り分けはサイトからはできません', $documentXml);
        $this->assertStringContainsString('私たちは採用ブランドの設計からご一緒します', $documentXml);
    }
}
