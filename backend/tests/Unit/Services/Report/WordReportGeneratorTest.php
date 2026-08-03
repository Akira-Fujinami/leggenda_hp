<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\WordReportGenerator;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;
use App\Support\Lead\LeadMetricCatalog;
use Tests\TestCase;
use ZipArchive;

class WordReportGeneratorTest extends TestCase
{
    private function viewModel(): ReportViewModel
    {
        return new ReportViewModel(
            companyDisplayName: '株式会社サンプル様',
            generatedAtLabel: '2026年7月27日',
            selfWebsiteUrl: 'https://example.com',
            competitorWebsiteUrl: null,
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
            ],
            topRecommendations: [
                new ReportRecommendationRow('画像を圧縮してください', '表示速度の改善につながります。', '緊急', '高', '小'),
            ],
            isPartial: false,
            brandWheelSelf: [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://example.com/careers',
                'axes' => [
                    ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']]],
                ],
                'key_message' => '技術で社会基盤を支える、という主題が置かれています。',
                'impression' => '情緒的便益の記述が薄いのがもったいないところです。',
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
            brandWheelCompetitor: null,
            brandWheelComparison: ['self_points' => ['活動的魅力が最も内容として充足しています。'], 'competitor_points' => [], 'one_point' => ['key' => 'well_covered', 'text' => '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。']],
            brandWheelRadarPng: null,
            selfBrandWheelEvidenceItems: [
                ['axis_key' => 'will_activity', 'axis_name' => '活動的魅力', 'group' => 'company_appeal', 'sub_element_name' => 'パーパス', 'evidence' => '技術で社会の「あたり前」を支える。それが私たちの存在意義です。'],
            ],
        );
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

    public function test_it_generates_a_valid_docx_document_with_correct_japanese_text(): void
    {
        $docx = app(WordReportGenerator::class)->generate($this->viewModel());
        $documentXml = $this->extractDocumentXml($docx);

        $this->assertStringContainsString('株式会社サンプル様', $documentXml);
        $this->assertStringContainsString('総合結果', $documentXml);
        $this->assertStringContainsString('計測対象外', $documentXml);
        $this->assertStringContainsString('書くべきことが書けているか', $documentXml);
        // 見出しは質問形(heading)を使い、内部向けのlabel(「メッセージの
        // 分かりやすさ」)は見出しとして出さない ―― 画面と同じ定義元
        // (LeadMetricCatalog::PERSPECTIVE_HEADINGS)を使っていることの確認。
        $this->assertStringContainsString('伝えたいことが分かりやすく伝わっているか', $documentXml);
        $this->assertStringNotContainsString('メッセージの分かりやすさ', $documentXml);
        // 内部専用情報が絶対に含まれないこと。
        $this->assertStringNotContainsString('job_type', $documentXml);
        $this->assertStringNotContainsString('error_code', $documentXml);
    }

    /**
     * 2026-08-04: 個別指標名(items[*].label、社内の指標名)は4観点セクションに
     * 一切出さない(見出し・判定バッジ・理由1文のみ、PDF版と同じ扱い)。
     */
    public function test_never_leaks_individual_metric_labels_in_the_perspectives_section(): void
    {
        $docx = app(WordReportGenerator::class)->generate($this->viewModel());
        $documentXml = $this->extractDocumentXml($docx);

        $this->assertStringNotContainsString('ページタイトルの設定', $documentXml);
    }

    /**
     * 「取得できなかった項目は0点として扱わず、算出の対象から外している」旨と
     * カバー率・確信度は、個別指標名を畳んだ後も残す(誠実性の維持に必要な情報)。
     */
    public function test_states_the_not_counted_as_zero_caveat_with_coverage_and_confidence(): void
    {
        $docx = app(WordReportGenerator::class)->generate($this->viewModel());
        $documentXml = $this->extractDocumentXml($docx);

        $this->assertStringContainsString('0点として扱わず', $documentXml);
        $this->assertStringContainsString('92.5', $documentXml);
        $this->assertStringContainsString('88.0', $documentXml);
    }

    /**
     * 2026-08-03: カバー率70%未満を「参考スコア」と明示する誠実性保証。
     * 画面(LeadResults)から診断内容そのものを外したため、この保証が
     * 実際にリードへ届く経路はWord/PDFレポートのみになった。従来の
     * fixture(coverage_rate=92.5)はこの分岐を一度も通していなかった
     * ―― この分岐を実際に通すテストが存在しなかった、という抜けを埋める。
     */
    public function test_shows_the_reference_score_badge_when_coverage_is_below_70_percent(): void
    {
        $viewModel = $this->viewModel();
        $lowCoverage = new ReportViewModel(
            companyDisplayName: $viewModel->companyDisplayName,
            generatedAtLabel: $viewModel->generatedAtLabel,
            selfWebsiteUrl: $viewModel->selfWebsiteUrl,
            competitorWebsiteUrl: $viewModel->competitorWebsiteUrl,
            selfScore: ['display_score' => 40, 'configured_max_score' => 100, 'coverage_rate' => 69.9, 'confidence_rate' => 60.0],
            competitorScore: $viewModel->competitorScore,
            overallSummaryText: $viewModel->overallSummaryText,
            comparisonSentence: $viewModel->comparisonSentence,
            perspectives: $viewModel->perspectives,
            topRecommendations: $viewModel->topRecommendations,
            isPartial: $viewModel->isPartial,
            brandWheelSelf: $viewModel->brandWheelSelf,
            brandWheelCompetitor: $viewModel->brandWheelCompetitor,
            brandWheelComparison: $viewModel->brandWheelComparison,
            brandWheelRadarPng: $viewModel->brandWheelRadarPng,
            selfBrandWheelEvidenceItems: $viewModel->selfBrandWheelEvidenceItems,
        );

        $documentXml = $this->extractDocumentXml(app(WordReportGenerator::class)->generate($lowCoverage));

        $this->assertStringContainsString('参考スコア', $documentXml);
    }

    /**
     * 2026-08-03: 6軸(ブランド・ホイール)。項目名・件数・読み取れた下位要素・
     * キーメッセージ・印象・比較まとめが出ること、満点(max_count)を
     * 固定値(4)で書いていないことを確認する。
     */
    public function test_includes_the_brand_wheel_section_with_counts_and_summary(): void
    {
        $docx = app(WordReportGenerator::class)->generate($this->viewModel());
        $documentXml = $this->extractDocumentXml($docx);

        $this->assertStringContainsString('採用ブランドの6軸', $documentXml);
        $this->assertStringContainsString('活動的魅力', $documentXml);
        $this->assertStringContainsString('2 / 4件', $documentXml);
        $this->assertStringContainsString('パーパス', $documentXml);
        $this->assertStringContainsString('技術で社会基盤を支える', $documentXml);
        $this->assertStringContainsString('情緒的便益の記述が薄い', $documentXml);
        $this->assertStringContainsString('活動的魅力が最も内容として充足しています', $documentXml);
        $this->assertStringContainsString('バランス良く読み取れる状態です', $documentXml);
    }

    /**
     * 2026-08-04: PDF版の「サイトから読み取れた記述」ページと同内容。
     * evidenceは要約・整形をせずそのまま出す。
     */
    public function test_includes_the_evidence_section_with_verbatim_text_when_there_are_matched_items(): void
    {
        $docx = app(WordReportGenerator::class)->generate($this->viewModel());
        $documentXml = $this->extractDocumentXml($docx);

        $this->assertStringContainsString('サイトから読み取れた記述', $documentXml);
        $this->assertStringContainsString('活動的魅力', $documentXml);
        $this->assertStringContainsString('パーパス', $documentXml);
        $this->assertStringContainsString('技術で社会の「あたり前」を支える。それが私たちの存在意義です。', $documentXml);
    }

    /**
     * 該当0件のときはセクション自体を出さない(見出しと空の表だけが残る
     * 状態を作らない ―― 画面側で同じ失敗を一度している、PDF版と同じ方針)。
     */
    public function test_omits_the_evidence_section_entirely_when_there_are_no_evidence_items(): void
    {
        $viewModel = $this->viewModel();
        $noEvidence = new ReportViewModel(
            companyDisplayName: $viewModel->companyDisplayName,
            generatedAtLabel: $viewModel->generatedAtLabel,
            selfWebsiteUrl: $viewModel->selfWebsiteUrl,
            competitorWebsiteUrl: $viewModel->competitorWebsiteUrl,
            selfScore: $viewModel->selfScore,
            competitorScore: $viewModel->competitorScore,
            overallSummaryText: $viewModel->overallSummaryText,
            comparisonSentence: $viewModel->comparisonSentence,
            perspectives: $viewModel->perspectives,
            topRecommendations: $viewModel->topRecommendations,
            isPartial: $viewModel->isPartial,
            brandWheelSelf: $viewModel->brandWheelSelf,
            brandWheelCompetitor: $viewModel->brandWheelCompetitor,
            brandWheelComparison: $viewModel->brandWheelComparison,
            brandWheelRadarPng: $viewModel->brandWheelRadarPng,
            selfBrandWheelEvidenceItems: [],
        );

        $documentXml = $this->extractDocumentXml(app(WordReportGenerator::class)->generate($noEvidence));

        $this->assertStringNotContainsString('サイトから読み取れた記述', $documentXml);
    }

    /**
     * 2026-08-03: status!=='success'のとき表を出さず、status_messageのみを
     * 出す(6項目すべて0件の表を出すことを禁止 ―― 「魅力のない会社」の
     * 意味になるため)。
     */
    public function test_does_not_render_the_brand_wheel_table_when_status_is_not_success(): void
    {
        $viewModel = $this->viewModel();
        $unreadable = new ReportViewModel(
            companyDisplayName: $viewModel->companyDisplayName,
            generatedAtLabel: $viewModel->generatedAtLabel,
            selfWebsiteUrl: $viewModel->selfWebsiteUrl,
            competitorWebsiteUrl: $viewModel->competitorWebsiteUrl,
            selfScore: $viewModel->selfScore,
            competitorScore: $viewModel->competitorScore,
            overallSummaryText: $viewModel->overallSummaryText,
            comparisonSentence: $viewModel->comparisonSentence,
            perspectives: $viewModel->perspectives,
            topRecommendations: $viewModel->topRecommendations,
            isPartial: $viewModel->isPartial,
            brandWheelSelf: [
                'status' => 'recruit_page_unreadable',
                'status_message' => '採用ページの内容を取得できなかったため、この項目の分析は行っていません。',
                'analyzed_url' => 'https://example.com/careers',
                'axes' => [],
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'unreadable', 'home_page' => 'read'],
            ],
            brandWheelCompetitor: null,
            brandWheelComparison: ['self_points' => [], 'competitor_points' => [], 'one_point' => null],
            brandWheelRadarPng: null,
            selfBrandWheelEvidenceItems: [],
        );

        $documentXml = $this->extractDocumentXml(app(WordReportGenerator::class)->generate($unreadable));

        $this->assertStringContainsString('採用ページの内容を取得できなかったため', $documentXml);
        $this->assertStringNotContainsString('パーパス', $documentXml);
        // 2026-08-04: 前置きページ(addBrandWheelFrameworkIntroSection)の3領域
        // 説明に「活動的魅力」等のグループ内訳が固定文言として含まれるため、
        // このアサーションは実際の解析結果テーブルにだけ出る列見出しで判定する。
        $this->assertStringNotContainsString('読み取れた内容', $documentXml);
    }

    /**
     * 2026-08-04: PDF版の2ページ目と同内容の前置き。ブランド・ホイールが
     * 「読み取れなかった=無い」ではないことを説明する一文は、この診断で
     * 最も誤解を招きやすい箇所のため、原文のまま出ることを確認する。
     */
    public function test_includes_the_brand_wheel_framework_intro_section_with_the_required_caveat_verbatim(): void
    {
        $docx = app(WordReportGenerator::class)->generate($this->viewModel());
        $documentXml = $this->extractDocumentXml($docx);

        $this->assertStringContainsString('採用ブランドの捉え方', $documentXml);
        $this->assertStringContainsString(
            '読み取れなかった項目は、その魅力が「無い」という意味ではありません。'
            .'サイトにそう書かれていない、というだけです。',
            $documentXml,
        );
    }

    /**
     * 2026-08-04: グループ名から色名(青/緑/赤)を外している ―― 配色を
     * レジェンダに合わせた結果、緑が青緑に変わり色名と実際の色が食い違う
     * ため(docs/lead-report-layout/README.md参照、PDF版と統一)。
     */
    public function test_intro_section_group_labels_do_not_include_color_names(): void
    {
        $docx = app(WordReportGenerator::class)->generate($this->viewModel());
        $documentXml = $this->extractDocumentXml($docx);

        $this->assertStringContainsString('会社の魅力', $documentXml);
        $this->assertStringContainsString('会社との距離', $documentXml);
        $this->assertStringContainsString('仕事の魅力', $documentXml);
        $this->assertStringNotContainsString('青／会社の魅力', $documentXml);
        $this->assertStringNotContainsString('緑／会社との距離', $documentXml);
        $this->assertStringNotContainsString('赤／仕事の魅力', $documentXml);
    }

    /**
     * 前置きは分析結果に依存しない固定コンテンツのため、ブランド・ホイールが
     * success以外(読み取り不可等)でも変わらず出ることを確認する。
     */
    public function test_includes_the_brand_wheel_framework_intro_section_even_when_brand_wheel_is_not_success(): void
    {
        $viewModel = $this->viewModel();
        $unreadable = new ReportViewModel(
            companyDisplayName: $viewModel->companyDisplayName,
            generatedAtLabel: $viewModel->generatedAtLabel,
            selfWebsiteUrl: $viewModel->selfWebsiteUrl,
            competitorWebsiteUrl: $viewModel->competitorWebsiteUrl,
            selfScore: $viewModel->selfScore,
            competitorScore: $viewModel->competitorScore,
            overallSummaryText: $viewModel->overallSummaryText,
            comparisonSentence: $viewModel->comparisonSentence,
            perspectives: $viewModel->perspectives,
            topRecommendations: $viewModel->topRecommendations,
            isPartial: $viewModel->isPartial,
            brandWheelSelf: [
                'status' => 'insufficient_input',
                'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
                'analyzed_url' => null,
                'axes' => [],
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'absent', 'home_page' => 'read'],
            ],
            brandWheelCompetitor: null,
            brandWheelComparison: ['self_points' => [], 'competitor_points' => [], 'one_point' => null],
            brandWheelRadarPng: null,
            selfBrandWheelEvidenceItems: [],
        );

        $documentXml = $this->extractDocumentXml(app(WordReportGenerator::class)->generate($unreadable));

        $this->assertStringContainsString('採用ブランドの捉え方', $documentXml);
        $this->assertStringContainsString('読み取れなかった項目は、その魅力が「無い」という意味ではありません。', $documentXml);
    }
}
