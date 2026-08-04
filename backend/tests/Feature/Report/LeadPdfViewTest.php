<?php

namespace Tests\Feature\Report;

use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
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
 * 2026-08-04: docs/lead-report-layout/report-layout-draft.htmlを移植元に
 * 全9ページへ全面書き直し(旧「他社ページ比較とのまとめ」ページは削除)。
 * このテストファイルもそれに合わせて全面書き直し。
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
            // ブランド・ホイールの固定説明図(PdfReportGeneratorが実際に
            // 埋め込むものと同じファイル)。1x1の透明PNGではなく実ファイルを
            // 使う ―― base64文字列自体が壊れていないことも間接的に確認できる。
            'brandWheelFrameworkImageBase64' => base64_encode((string) file_get_contents(resource_path('images/brand-wheel-framework.png'))),
            'leggendaLogoImageBase64' => base64_encode((string) file_get_contents(resource_path('images/leggenda-logo.png'))),
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
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']]],
        ];

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
     * (6・7・8ページ目のテスト用)。BrandWheelSubElementComparisonComposer/
     * BrandWheelImprovementFocusComposerを実際に呼び出して組み立てる ――
     * 手書きの24項目リストとの食い違いを防ぐため(ReportViewModelBuilderが
     * 実際に行うのと同じ組み立て方)。
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

    // ------------------------------------------------------------------
    // 既存(4観点・改善提案の誠実性表示等)。
    // ------------------------------------------------------------------

    public function test_honestly_reports_a_perspective_it_could_not_detect_instead_of_hiding_it(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('採用ページを検出できませんでした', $html);
        $this->assertStringContainsString('計測対象外', $html);
    }

    public function test_never_leaks_individual_metric_labels_on_the_four_perspectives_page(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringNotContainsString('スマートフォンでの表示対応', $html);
        $this->assertStringNotContainsString('応募・問い合わせフォームの設置', $html);
        $this->assertStringNotContainsString('ページタイトルの設定', $html);
    }

    public function test_states_the_not_counted_as_zero_caveat_with_coverage_and_confidence(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('0点として扱わず', $html);
        $this->assertStringContainsString('92.5', $html);
        $this->assertStringContainsString('88.0', $html);
    }

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
     * status!=='success'のとき図も表も出さず、status_messageのみを出す。
     * 6項目すべて0件の表(「魅力のない会社」に見えてしまう)は禁止。
     * 3・6・7・8ページ目すべてが同じ理由でstatus_messageに切り替わる。
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
            'brandWheelComparison' => ['self_points' => [], 'one_point' => null],
            'selfTotalMatched' => 0,
            'selfTotalMax' => 0,
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose([], []),
            // 実際のReportViewModelBuilderは、axesが空ならevidenceも自動的に
            // 空になる(buildSelfBrandWheelEvidenceItems()が$selfWheelAxesを
            // 反復するだけのため)。このfixtureでも同じ状態を再現する。
            'selfBrandWheelEvidenceItems' => [],
        ]));

        $this->assertStringContainsString('サイトの記述からは、6つの項目に該当する内容を読み取れませんでした', $html);
        $this->assertStringNotContainsString('パーパス', $html);
        // 「採用ブランドの捉え方」前置きページは軸名(活動的魅力等)を固定の
        // 説明文として常に含むため、軸名そのものの有無ではなく、実際の
        // 軸カード(件数の丸)が出ていないことで判定する。
        $this->assertStringNotContainsString('class="axcnt"', $html);
        // evidence一覧ページ自体も出ない。
        $this->assertStringNotContainsString('サイトから読み取れた記述', $html);
        // 24項目の対比・触れられていなかった項目・改善提案のいずれも
        // 実際のグリッド/カードを描画しない。
        $this->assertStringNotContainsString('class="vstbl"', $html);
        $this->assertStringNotContainsString('class="gcard"', $html);
        $this->assertStringNotContainsString('class="rcard"', $html);
    }

    public function test_includes_the_brand_wheel_section_with_counts_and_summary(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('自社ページの分析結果', $html);
        $this->assertStringContainsString('活動的魅力', $html);
        $this->assertStringContainsString('2<small> / 4件</small>', $html);
        $this->assertStringContainsString('パーパス', $html);
        $this->assertStringContainsString('技術で社会基盤を支える', $html);
        $this->assertStringContainsString('情緒的便益の記述が薄い', $html);
        $this->assertStringContainsString('活動的魅力が最も内容として充足しています', $html);
        $this->assertStringContainsString('バランス良く読み取れる状態です', $html);
        // AI生成コンテンツ(key_message/impression)である旨の開示。
        $this->assertStringContainsString('AIを使用', $html);
    }

    /**
     * 2026-08-04: 「採用ブランドの捉え方」前置きページ。分析結果に依存しない
     * 固定ページのため、status(success以外を含む)によらず常に出る。
     * 誤解を招きやすい一文(読み取れなかった=魅力が無い、ではない)は
     * 一字一句削らずに含めること(引用符は『』を使う ―― ユーザー指定の
     * 「絶対に消してはいけない文言」原文どおり)。
     */
    public function test_includes_the_brand_wheel_framework_intro_page_with_the_required_caveat_verbatim(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('採用ブランドの捉え方', $html);
        $this->assertStringContainsString(
            '読み取れなかった項目は、その魅力が『無い』という意味ではありません。'
            .'サイトにそう書かれていない、というだけです。',
            $html,
        );
        // 固定説明図(base64埋め込み)。BrandWheelHexagonRendererを通していない
        // ―― 生成時刻に依存するデータは無いため、テストの固定資産(PNG)の
        // base64表現がそのまま出ていることだけを確認すれば足りる。
        $expectedBase64 = base64_encode((string) file_get_contents(resource_path('images/brand-wheel-framework.png')));
        $this->assertStringContainsString('data:image/png;base64,'.$expectedBase64, $html);
    }

    public function test_includes_the_brand_wheel_framework_intro_page_even_when_brand_wheel_is_not_success(): void
    {
        $html = $this->render($this->viewModel([
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

        $this->assertStringContainsString('採用ブランドの捉え方', $html);
        $this->assertStringContainsString('読み取れなかった項目は、その魅力が『無い』という意味ではありません。', $html);
    }

    public function test_falls_back_to_the_axis_table_only_when_the_radar_png_is_missing(): void
    {
        $html = $this->render($this->viewModel(['brandWheelRadarPng' => null]));

        $this->assertStringNotContainsString('width: 66mm; height: 48mm;', $html);
        $this->assertStringContainsString('2<small> / 4件</small>', $html);
    }

    public function test_embeds_the_radar_png_as_a_base64_image_when_available(): void
    {
        $png = "\x89PNG\r\n\x1a\n".'dummy-bytes-for-test';

        $html = $this->render($this->viewModel(['brandWheelRadarPng' => $png]));

        $this->assertStringContainsString('<img src="data:image/png;base64,'.base64_encode($png).'"', $html);
    }

    public function test_axis_card_table_uses_explicit_mm_widths_not_percentages(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('.axcell { width: 44.16mm;', $html);
        $this->assertStringNotContainsString('.axcell { width: 16.66%;', $html);
    }

    // ------------------------------------------------------------------
    // 「サイトから読み取れた記述」ページ(evidence一覧)。
    // ------------------------------------------------------------------

    public function test_omits_the_evidence_page_entirely_when_there_are_no_evidence_items(): void
    {
        $html = $this->render($this->viewModel(['selfBrandWheelEvidenceItems' => []]));

        $this->assertStringNotContainsString('サイトから読み取れた記述', $html);
        $this->assertStringNotContainsString('class="evtbl"', $html);
    }

    public function test_includes_the_evidence_page_when_there_are_matched_items(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('サイトから読み取れた記述', $html);
        $this->assertStringContainsString('class="evtbl"', $html);
        $this->assertStringContainsString('活動的魅力', $html);
        $this->assertStringContainsString('パーパス', $html);
    }

    public function test_evidence_text_is_rendered_verbatim_without_truncation(): void
    {
        $longEvidence = '資格取得支援制度により、受験費用を全額補助します。この制度は入社1年目から利用可能で、年間の利用上限額は設けていません。';

        $html = $this->render($this->viewModel([
            'selfBrandWheelEvidenceItems' => [
                ['axis_key' => 'financial_benefit', 'axis_name' => '金銭的便益', 'group' => 'job_appeal', 'sub_element_name' => '成長機会', 'evidence' => $longEvidence],
            ],
        ]));

        $this->assertStringContainsString($longEvidence, $html);
        $this->assertStringNotContainsString('…', $html);
        $this->assertStringNotContainsString('...', $html);
    }

    public function test_evidence_page_does_not_contain_forbidden_phrases(): void
    {
        $html = $this->render($this->viewModel());

        // evidence一覧ページ本体のみを対象にする(次ページの見出しまでを
        // 切り出す。2026-08-04: 次ページが「採用担当の視点で見た診断結果」に
        // 変わったため境界マーカーを更新)。
        $start = mb_strpos($html, 'サイトから読み取れた記述');
        $this->assertNotFalse($start);
        $end = mb_strpos($html, '採用担当の視点で見た診断結果', $start) ?: mb_strlen($html);
        $evidencePageHtml = mb_substr($html, $start, $end - $start);

        foreach ((array) config('brand_wheel.forbidden_phrases') as $phrase) {
            $this->assertStringNotContainsString($phrase, $evidencePageHtml);
        }
    }

    // ------------------------------------------------------------------
    // 軸カードの四角(dots)。max_countから動的に生成すること。
    // ------------------------------------------------------------------

    public function test_axis_card_dots_are_generated_from_max_count_not_a_hardcoded_four(): void
    {
        $html = $this->render($this->viewModel([
            'brandWheelSelf' => [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://example.com/careers',
                'axes' => [
                    ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 3, 'max_count' => 5, 'matched_sub_elements' => [
                        ['key' => 'purpose', 'name' => 'パーパス'], ['key' => 'a', 'name' => 'A'], ['key' => 'b', 'name' => 'B'],
                    ]],
                ],
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
        ]));

        $this->assertStringContainsString('3<small> / 5件</small>', $html);
        $this->assertSame(5, substr_count($html, '<span class="dot'));
        $this->assertSame(3, substr_count($html, 'class="dot on"'));
    }

    public function test_axis_card_still_shows_empty_dots_when_matched_count_is_zero(): void
    {
        $html = $this->render($this->viewModel([
            'brandWheelSelf' => [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://example.com/careers',
                'axes' => [
                    ['key' => 'relationship', 'group' => 'company_distance', 'name' => '就業環境', 'matched_count' => 0, 'max_count' => 4, 'matched_sub_elements' => []],
                ],
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
        ]));

        $this->assertStringContainsString('0<small> / 4件</small>', $html);
        $this->assertSame(4, substr_count($html, '<span class="dot'));
        $this->assertSame(0, substr_count($html, 'class="dot on"'));
        $this->assertStringNotContainsString('読み取れた内容はありません', $html);
        $this->assertStringContainsString('該当する記述は見つかりませんでした', $html);
    }

    public function test_axis_body_height_is_38mm_even_when_all_four_sub_elements_matched(): void
    {
        $html = $this->render($this->viewModel([
            'brandWheelSelf' => [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://example.com/careers',
                'axes' => [
                    ['key' => 'financial_benefit', 'group' => 'job_appeal', 'name' => '金銭的便益', 'matched_count' => 4, 'max_count' => 4, 'matched_sub_elements' => [
                        ['key' => 'salary_level', 'name' => '給与水準'], ['key' => 'benefits', 'name' => '福利厚生'],
                        ['key' => 'growth_opportunity', 'name' => '成長機会'], ['key' => 'employment_stability', 'name' => '雇用の安定性'],
                    ]],
                ],
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
        ]));

        $this->assertStringContainsString('height: 38mm', $html);
        $this->assertStringContainsString('4<small> / 4件</small>', $html);
        $this->assertSame(4, substr_count($html, 'class="dot on"'));
    }

    // ------------------------------------------------------------------
    // 6. 24項目の対比(2026-08-04新設)。
    // ------------------------------------------------------------------

    public function test_comparison_page_uses_filled_and_dash_marks_not_circle_cross(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $start = mb_strpos($html, '24項目の対比');
        $this->assertNotFalse($start);
        $end = mb_strpos($html, 'サイトで触れられていなかった項目', $start) ?: mb_strlen($html);
        $pageHtml = mb_substr($html, $start, $end - $start);

        $this->assertStringContainsString('●', $pageHtml);
        $this->assertStringContainsString('－', $pageHtml);
        // ○×は正解・不正解の記号であり使わない(docs/lead-report-layout/README.md)。
        $this->assertStringNotContainsString('○', $pageHtml);
        $this->assertStringNotContainsString('×', $pageHtml);
    }

    /**
     * 引用符は『』を使う(ユーザー指定の「絶対に消してはいけない文言」原文どおり)。
     */
    public function test_comparison_page_keeps_the_required_caveat_sentence_verbatim(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString(
            '－は『その魅力が無い』という意味ではなく、そのサイトでは触れられていなかった、という意味です。',
            $html,
        );
    }

    public function test_comparison_page_drops_the_competitor_column_when_there_is_no_competitor_website(): void
    {
        $html = $this->render($this->viewModel());

        $start = mb_strpos($html, '24項目の対比');
        $this->assertNotFalse($start);
        $end = mb_strpos($html, 'サイトで触れられていなかった項目', $start) ?: mb_strlen($html);
        $pageHtml = mb_substr($html, $start, $end - $start);

        $this->assertStringNotContainsString('<th>比較</th>', $pageHtml);
    }

    public function test_comparison_page_shows_the_competitor_column_when_a_competitor_website_exists(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $start = mb_strpos($html, '24項目の対比');
        $end = mb_strpos($html, 'サイトで触れられていなかった項目', $start) ?: mb_strlen($html);
        $pageHtml = mb_substr($html, $start, $end - $start);

        $this->assertStringContainsString('<th>比較</th>', $pageHtml);
    }

    /**
     * 合計は自社ページの分析結果ページと同じ$viewModel->selfTotalMatched等
     * (単一のソース)を使う ―― ページごとに個別集計しない
     * (docs/lead-report-layout/README.md)。
     */
    public function test_comparison_page_total_matches_the_same_source_as_the_self_results_page(): void
    {
        $viewModel = $this->comparisonViewModel();
        $html = $this->render($viewModel);

        $this->assertStringContainsString("自社サイト {$viewModel->selfTotalMatched} / {$viewModel->selfTotalMax}項目", $html);
        $this->assertStringContainsString("比較サイト {$viewModel->competitorTotalMatched} / {$viewModel->competitorTotalMax}項目", $html);
    }

    // ------------------------------------------------------------------
    // 7. サイトで触れられていなかった項目(2026-08-04新設)。
    // ------------------------------------------------------------------

    public function test_gap_page_keeps_the_required_lead_sentence_verbatim(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString(
            '書かれていないことが弱みという意味ではありません。候補者が2つのサイトを見比べたとき、'
            .'その情報を比較サイト側でしか得られない、という事実を示しています。',
            $html,
        );
    }

    public function test_gap_page_lists_items_competitor_has_and_self_does_not_under_section_a(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $start = mb_strpos($html, 'サイトで触れられていなかった項目');
        $end = mb_strpos($html, '改善提案', $start) ?: mb_strlen($html);
        $pageHtml = mb_substr($html, $start, $end - $start);

        // fixtureでは自社=purposeのみ、競合=purpose+colleagues+atmosphere。
        // A(競合のみ)はcolleagues/atmosphereの2件。
        $this->assertStringContainsString('同僚・先輩像', $pageHtml);
        $this->assertStringContainsString('職場の雰囲気', $pageHtml);
    }

    /**
     * Bを省略しない ―― 自社のみ該当する項目が0件でも、枠と
     * 「該当する項目はありませんでした」を必ず出す(Aだけ並べると詰問状に
     * なる、docs/lead-report-layout/README.md)。
     */
    public function test_gap_page_always_shows_section_b_even_when_there_are_zero_self_only_items(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $start = mb_strpos($html, 'サイトで触れられていなかった項目');
        $end = mb_strpos($html, '改善提案', $start) ?: mb_strlen($html);
        $pageHtml = mb_substr($html, $start, $end - $start);

        $this->assertStringContainsString('御社のサイトにあり、比較サイトでは触れられていなかった項目', $pageHtml);
        $this->assertStringContainsString('該当する項目はありませんでした', $pageHtml);
    }

    public function test_gap_page_shows_a_fallback_message_when_there_is_no_competitor(): void
    {
        $html = $this->render($this->viewModel());

        $start = mb_strpos($html, 'サイトで触れられていなかった項目');
        $end = mb_strpos($html, '改善提案', $start) ?: mb_strlen($html);
        $pageHtml = mb_substr($html, $start, $end - $start);

        $this->assertStringContainsString('比較サイトが指定されていないため', $pageHtml);
        $this->assertStringNotContainsString('class="gcard"', $pageHtml);
    }

    // ------------------------------------------------------------------
    // 8. 改善提案(2026-08-04新設、ブランド・ホイール起点)。
    // ------------------------------------------------------------------

    public function test_improvement_page_keeps_both_required_sentences_verbatim(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString(
            'なお、これらを『サイトに書き足す』ことで解決するとは限りません。実態はあるのに伝えられていないのか、'
            .'まだ言葉になっていないのか ―― その切り分けについては最終ページをご覧ください。',
            $html,
        );
        $this->assertStringContainsString(
            'いずれもサイトの作りに関するもので、上の『何を書くか』とは別の話です。',
            $html,
        );
    }

    public function test_improvement_page_shows_the_selected_group_and_competitor_evidence_for_its_items(): void
    {
        $html = $this->render($this->comparisonViewModel());

        // fixtureはcompany_distance(会社との距離)の差が最大になるよう
        // 組んである(自社relationship=0件、競合relationship=2件)。
        $this->assertStringContainsString('会社との距離', $html);
        $this->assertStringContainsString('入社3年目の先輩が、日々どんな判断をしているかを紹介しています。', $html);
        $this->assertStringContainsString('部署をまたいだ相談が日常的に起きる、フラットな環境です。', $html);
        $this->assertStringContainsString('記述が見つかりませんでした', $html);
    }

    public function test_improvement_page_never_offers_comparing_three_to_five_competitor_sites(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringNotContainsString('他社比較', $html);
        $this->assertStringNotContainsString('3〜5社', $html);
    }

    public function test_improvement_page_shows_a_fallback_note_when_there_is_no_competitor(): void
    {
        $html = $this->render($this->viewModel());

        $start = mb_strpos($html, '改善提案');
        $this->assertNotFalse($start);
        $pageHtml = mb_substr($html, $start);

        $this->assertStringContainsString('比較サイトが無いため、領域ごとの比較はご用意できません。', $pageHtml);
        $this->assertStringNotContainsString('class="rcard"', $pageHtml);
    }

    public function test_improvement_page_includes_a_compact_technical_recommendations_note(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'topRecommendations' => [
                new ReportRecommendationRow('画像を圧縮してください', '表示速度の改善につながります。', '緊急', '高', '小'),
            ],
        ]));

        $this->assertStringContainsString('あわせて、サイトの作りについて', $html);
        $this->assertStringContainsString('画像を圧縮してください', $html);
    }

    // ------------------------------------------------------------------
    // 9. ここから先は、サイトの外の話です(2026-08-04新設)。
    // ------------------------------------------------------------------

    public function test_final_page_uses_the_new_heading_and_never_offers_the_old_site_comparison_cta(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('ここから先は、サイトの外の話です', $html);
        $this->assertStringNotContainsString('他社比較(3〜5社)', $html);
        $this->assertStringNotContainsString('他社比較（3〜5社）', $html);
    }

    public function test_final_page_includes_the_three_block_structure(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('書かれていない項目には', $html);
        $this->assertStringContainsString('その切り分けは', $html);
        $this->assertStringContainsString('サイトからはできません', $html);
        $this->assertStringContainsString('私たちは採用ブランドの', $html);
        $this->assertStringContainsString('設計からご一緒します', $html);
    }
}
