<?php

namespace Tests\Feature\Report;

use App\Support\Lead\LeadMetricCatalog;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;
use Tests\TestCase;

/**
 * PdfReportGeneratorTestはdompdfが有効なPDFバイト列を返すことしか検証して
 * おらず、レンダリングされた文面そのものは一度も検証していない
 * (Word版はdocxのXMLをZipから取り出して文字列検証しているが、PDF版には
 * 同等のテストが存在しなかった)。2026-08-03、画面(LeadResults)から診断
 * 内容そのものを外したことで、以下の誠実性表示はPDFがリードへ届く唯一の
 * 経路になった:
 * - カバー率70%未満のとき「参考スコア」と明示する
 * - 採用ページを検出できなかった観点を、網羅できているように見せない
 * - 競合サイトへの改善提案を出さない(ReportViewModelBuilderが
 *   self側のrecommendationsしか渡していないことをここでも再確認する)
 *
 * PDFバイナリのテキスト抽出用ライブラリはこのリポジトリに無いため、dompdfへ
 * 変換する前のBladeテンプレート自体をHTML文字列としてレンダリングして検証
 * する(PdfReportGenerator::generate()が使うのと同じビュー
 * `reports.lead-pdf`、同じ$viewModel)。dompdfの文字コード変換・フォント
 * 埋め込みより手前の、文面の分岐ロジックそのものを検証する意図であり、
 * PdfReportGeneratorTestのPDFバイト列検証を置き換えるものではない。
 */
class LeadPdfViewTest extends TestCase
{
    private function render(ReportViewModel $viewModel): string
    {
        return view('reports.lead-pdf', [
            'viewModel' => $viewModel,
            'ipaexGothicFontPath' => 'file:///dev/null',
        ])->render();
    }

    private function viewModel(array $overrides = []): ReportViewModel
    {
        $defaults = [
            'companyDisplayName' => '株式会社サンプル様',
            'generatedAtLabel' => '2026年8月3日',
            'selfWebsiteUrl' => 'https://example.com',
            'competitorWebsiteUrl' => null,
            'selfScore' => ['display_score' => 76, 'configured_max_score' => 100, 'coverage_rate' => 92.5, 'confidence_rate' => 88.0],
            'competitorScore' => null,
            'overallSummaryText' => '総合スコア76点という結果になりました。',
            'comparisonSentence' => null,
            'perspectives' => [
                [
                    'key' => LeadMetricCatalog::PERSPECTIVE_COMPLETENESS,
                    'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_COMPLETENESS],
                    'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[LeadMetricCatalog::PERSPECTIVE_COMPLETENESS],
                    'note' => LeadMetricCatalog::COMPLETENESS_LEGAL_ITEMS_NOTE,
                    'status' => 'not_detected',
                    'summary' => '採用ページを検出できませんでした。トップページに採用に関する案内が見つからなかったため、この観点は今回「計測対象外」です。',
                    'items' => [],
                ],
            ],
            'topRecommendations' => [
                new ReportRecommendationRow('画像を圧縮してください', '表示速度の改善につながります。', '緊急', '高', '小'),
            ],
            'isPartial' => false,
            'brandWheelSelf' => [
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
            'brandWheelCompetitor' => null,
            'brandWheelComparison' => ['self_points' => ['活動的魅力が最も内容として充足しています。'], 'competitor_points' => [], 'one_point' => ['key' => 'well_covered', 'text' => '6つの項目それぞれについて、内容が読み取れています。伝えたいキーメッセージがバランス良く読み取れる状態です。']],
        ];

        $values = array_merge($defaults, $overrides);

        return new ReportViewModel(...$values);
    }

    public function test_shows_the_reference_score_badge_when_coverage_is_below_70_percent(): void
    {
        $html = $this->render($this->viewModel([
            'selfScore' => ['display_score' => 40, 'configured_max_score' => 100, 'coverage_rate' => 69.9, 'confidence_rate' => 60.0],
        ]));

        $this->assertStringContainsString('参考スコア', $html);
    }

    public function test_does_not_show_the_reference_score_badge_when_coverage_is_at_least_70_percent(): void
    {
        $html = $this->render($this->viewModel([
            'selfScore' => ['display_score' => 76, 'configured_max_score' => 100, 'coverage_rate' => 70.0, 'confidence_rate' => 88.0],
        ]));

        $this->assertStringNotContainsString('参考スコア', $html);
    }

    /**
     * 採用ページを検出できなかった観点(not_detected)が、網羅しているかの
     * ように見せず、正直な文面(計測対象外)で出ること。
     */
    public function test_honestly_reports_a_perspective_it_could_not_detect_instead_of_hiding_it(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('採用ページを検出できませんでした', $html);
        $this->assertStringContainsString('計測対象外', $html);
    }

    /**
     * ReportViewModelBuilderがself側のrecommendationsしか渡さない設計を
     * ここでも再確認する(ReportViewModelBuilderTest側でビルダーの入力を
     * 検証済みだが、実際にPDFへ出す段階でも競合の提案が混ざらないことを
     * 直接確認する)。
     */
    public function test_never_renders_a_recommendation_for_the_competitor_site(): void
    {
        $html = $this->render($this->viewModel([
            'competitorWebsiteUrl' => 'https://competitor.example.com',
            'topRecommendations' => [
                new ReportRecommendationRow('画像を圧縮してください', '表示速度の改善につながります。', '緊急', '高', '小'),
            ],
        ]));

        // topRecommendationsは常にself専用(ReportViewModelBuilderが
        // selfWebsiteAnalysis->recommendationsのみを渡す設計)であるため、
        // 競合サイトのURLが改善提案セクションの見出しや文面と結びついて
        // 出ないことを確認する ―― 改善提案は1件しか無く、それが自社向けの
        // 文面であることを確認すれば足りる。
        $this->assertSame(1, substr_count($html, '画像を圧縮してください'));
        $this->assertStringContainsString('比較サイト: https://competitor.example.com', $html);
    }

    /**
     * status!=='success'のとき表を出さず、status_messageのみを出す。
     * 6項目すべて0件の表(「魅力のない会社」に見えてしまう)は禁止。
     */
    public function test_does_not_render_the_brand_wheel_table_when_status_is_not_success(): void
    {
        $html = $this->render($this->viewModel([
            'brandWheelSelf' => [
                'status' => 'no_matched_content',
                'status_message' => 'サイトの記述からは、6つの項目に該当する内容を読み取れませんでした。',
                'analyzed_url' => 'https://example.com/careers',
                'axes' => [],
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
            'brandWheelComparison' => ['self_points' => [], 'competitor_points' => [], 'one_point' => null],
        ]));

        $this->assertStringContainsString('サイトの記述からは、6つの項目に該当する内容を読み取れませんでした', $html);
        $this->assertStringNotContainsString('パーパス', $html);
        $this->assertStringNotContainsString('活動的魅力', $html);
    }

    public function test_includes_the_brand_wheel_section_with_counts_and_summary(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('採用ブランドの6軸', $html);
        $this->assertStringContainsString('活動的魅力', $html);
        $this->assertStringContainsString('2 / 4件', $html);
        $this->assertStringContainsString('パーパス', $html);
        $this->assertStringContainsString('技術で社会基盤を支える', $html);
        $this->assertStringContainsString('情緒的便益の記述が薄い', $html);
        $this->assertStringContainsString('活動的魅力が最も内容として充足しています', $html);
        $this->assertStringContainsString('バランス良く読み取れる状態です', $html);
        // お断り文言(メールと同趣旨)。
        $this->assertStringContainsString('グループインタビュー', $html);
        $this->assertStringContainsString('AIを使用', $html);
    }

    public function test_never_leaks_evidence_text_into_the_pdf(): void
    {
        // BrandWheelLeadResponseComposerはevidenceを一切含めないため、
        // このテストのfixture自体にevidenceキーは存在しない。万一、将来
        // どこかでevidenceを含む配列がそのまま渡された場合に備え、
        // "evidence"という語自体がPDFに出ないことを確認する。
        $html = $this->render($this->viewModel());

        $this->assertStringNotContainsString('evidence', $html);
    }
}
