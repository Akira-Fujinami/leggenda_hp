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
 * - 採用ページを検出できなかった観点を、網羅できているように見せない
 * - 取得できなかった項目を0点として扱わない旨・カバー率・確信度を明示する
 * - 競合サイトへの改善提案を出さない(ReportViewModelBuilderが
 *   self側のrecommendationsしか渡していないことをここでも再確認する)
 * - 個別指標名(items[*].label)を4観点ページに出さない(2026-08-04)
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
                // ReportViewModelBuilderが付与する値(summaryをそのまま採用)。
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
            [
                'key' => LeadMetricCatalog::PERSPECTIVE_FINDABILITY,
                'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_FINDABILITY],
                'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[LeadMetricCatalog::PERSPECTIVE_FINDABILITY],
                'note' => null,
                'status' => 'needs_improvement',
                'items' => [
                    ['label' => '応募・問い合わせフォームの設置', 'status' => 'needs_improvement', 'detail' => null],
                ],
                'one_liner' => '確認した1項目のうち1項目で改善の余地がありました。',
            ],
            [
                'key' => LeadMetricCatalog::PERSPECTIVE_USABILITY,
                'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_USABILITY],
                'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[LeadMetricCatalog::PERSPECTIVE_USABILITY],
                'note' => LeadMetricCatalog::USABILITY_SECTION_NOTE,
                'status' => 'needs_review',
                'items' => [
                    ['label' => 'スマートフォンでの表示対応', 'status' => 'needs_review', 'detail' => null],
                ],
                'one_liner' => '確認した1項目のうち1項目で確認をおすすめします。',
            ],
        ];
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
            'perspectives' => $this->perspectives(),
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
            'brandWheelRadarPng' => null,
        ];

        $values = array_merge($defaults, $overrides);

        return new ReportViewModel(...$values);
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
     * 2026-08-04: 個別指標名(items[*].label)は4観点ページに一切出さない
     * (見出し・判定バッジ・理由1文のみ)。
     */
    public function test_never_leaks_individual_metric_labels_on_the_four_perspectives_page(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringNotContainsString('スマートフォンでの表示対応', $html);
        $this->assertStringNotContainsString('応募・問い合わせフォームの設置', $html);
        $this->assertStringNotContainsString('ページタイトルの設定', $html);
    }

    /**
     * 2026-08-04: 「取得できなかった項目は0点として扱わず、算出の対象から
     * 外している」旨とカバー率・確信度は、個別指標名を畳んだ後も残す
     * (誠実性の維持に必要な情報のため)。
     */
    public function test_states_the_not_counted_as_zero_caveat_with_coverage_and_confidence(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('0点として扱わず', $html);
        $this->assertStringContainsString('92.5', $html);
        $this->assertStringContainsString('88.0', $html);
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

        $this->assertStringContainsString('自社ページの分析結果', $html);
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

    /**
     * 2026-08-04: PNGが生成できなかった場合(brandWheelRadarPng===null)、
     * 図を省略し、軸ごとの件数の表だけで成立させる(画像の失敗がレポート
     * 生成全体を止めてはいけない、既存メールと同じ方針)。
     */
    public function test_falls_back_to_the_axis_table_only_when_the_radar_png_is_missing(): void
    {
        $html = $this->render($this->viewModel(['brandWheelRadarPng' => null]));

        $this->assertStringNotContainsString('<img', $html);
        // 図が無くても表(件数)は変わらず出る。
        $this->assertStringContainsString('2 / 4件', $html);
    }

    public function test_embeds_the_radar_png_as_a_base64_image_when_available(): void
    {
        $png = "\x89PNG\r\n\x1a\n".'dummy-bytes-for-test';

        $html = $this->render($this->viewModel(['brandWheelRadarPng' => $png]));

        $this->assertStringContainsString('<img src="data:image/png;base64,'.base64_encode($png).'"', $html);
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
