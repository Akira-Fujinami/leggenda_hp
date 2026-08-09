<?php

namespace Tests\Feature\Report;

use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use App\Support\Report\ReportViewModel;
use Tests\TestCase;

/**
 * PdfReportGeneratorTestはdompdfが有効なPDFバイト列を返すことしか検証して
 * おらず、レンダリングされた文面そのものは一度も検証していない
 * (Word版はdocxのXMLをZipから取り出して文字列検証しているが、PDF版には
 * 同等のテストが存在しなかった)。2026-08-03、画面(LeadResults)から診断
 * 内容そのものを外したことで、以下の誠実性表示はPDFがリードへ届く唯一の
 * 経路になった:
 * - 取得できなかった項目を「魅力が無い」という意味に読ませない
 * - 競合サイトの本文をレポートに広く露出させない(改善提案ページの3項目
 *   分のevidenceのみが唯一の例外)
 * - 個別の下位要素の判定はすべてBrandWheelSubElementComparisonComposer
 *   (プログラム側)が行う。AIに3段階を判定させない
 *
 * PDFバイナリのテキスト抽出用ライブラリはこのリポジトリに無いため、dompdfへ
 * 変換する前のBladeテンプレート自体をHTML文字列としてレンダリングして検証
 * する(PdfReportGenerator::generate()が使うのと同じビュー
 * `reports.lead-pdf`、同じ$viewModel)。dompdfの文字コード変換・フォント
 * 埋め込みより手前の、文面の分岐ロジックそのものを検証する意図であり、
 * PdfReportGeneratorTestのPDFバイト列検証を置き換えるものではない。
 *
 * 2026-08-08: レポートを9ページ構成から7ページ構成へ再編したことに伴い、
 * このテストファイルも全面書き直し。旧「サイトから読み取れた記述」
 * (evidence一覧)・「採用担当の視点で見た診断結果」(4観点)・「サイトで
 * 触れられていなかった項目」(A/B/Cギャップ分析)の3ページは削除され、
 * 「自社サイトの分析結果」ページから競合要素を分離した「競合サイトの分析
 * 結果」ページが新設された。「24項目の対比」は●／－の2値表示から
 * ○△－の3値表示(○△－の対比表)へ変更、最終ページは新しいCTA文言に
 * 全面差し替えられている。
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
     * brandWheelSelf/brandWheelCompetitor(BrandWheelLeadResponseComposer::compose()
     * の戻り値)の共通フィクスチャ。impression_items(2026-08-08新設)を含め、
     * 個々のテストは必要なキーだけを上書きする。
     *
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

    /**
     * 自社単独(競合サイト無し)のfixture。「自社サイトの分析結果」ページ用。
     */
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
     * (「競合サイトの分析結果」「○△－の対比表」「改善提案」ページ用)。
     * BrandWheelSubElementComparisonComposer/BrandWheelImprovementFocusComposerを
     * 実際に呼び出して組み立てる ―― 手書きの24項目リストとの食い違いを
     * 防ぐため(ReportViewModelBuilderが実際に行うのと同じ組み立て方)。
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

    // ------------------------------------------------------------------
    // 1. 表紙。
    // ------------------------------------------------------------------

    public function test_cover_page_shows_company_name_and_urls(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString('株式会社サンプル様', $html);
        $this->assertStringContainsString('対象サイト: https://example.com', $html);
        $this->assertStringContainsString('比較サイト: https://competitor.example.com', $html);
    }

    public function test_cover_page_omits_the_competitor_line_when_there_is_no_competitor(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringNotContainsString('比較サイト:', $html);
    }

    public function test_cover_page_shows_the_partial_caveat_when_the_analysis_is_partial(): void
    {
        $html = $this->render($this->viewModel(['isPartial' => true]));

        $this->assertStringContainsString('一部のデータは取得できませんでしたが、取得できた範囲での診断結果です。', $html);
    }

    // ------------------------------------------------------------------
    // 2. 採用ブランドの捉え方(前置き)。
    // ------------------------------------------------------------------

    /**
     * 分析結果に依存しない固定ページのため、status(success以外を含む)に
     * よらず常に出る。誤解を招きやすい一文(読み取れなかった=魅力が無い、
     * ではない)は一字一句削らずに含めること(引用符は『』を使う ――
     * ユーザー指定の「絶対に消してはいけない文言」原文どおり)。
     */
    public function test_intro_page_includes_the_required_caveat_verbatim_regardless_of_status(): void
    {
        $html = $this->render($this->viewModel([
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

        $this->assertStringContainsString('採用ブランドの捉え方', $html);
        $this->assertStringContainsString(
            '読み取れなかった項目は、その魅力が『無い』という意味ではありません。'
            .'サイトにそう書かれていない、というだけです。',
            $html,
        );
        // 固定説明図(base64埋め込み)。生成時刻に依存するデータは無いため、
        // テストの固定資産(PNG)のbase64表現がそのまま出ていることだけを
        // 確認すれば足りる。
        $expectedBase64 = base64_encode((string) file_get_contents(resource_path('images/brand-wheel-framework.png')));
        $this->assertStringContainsString('data:image/png;base64,'.$expectedBase64, $html);
    }

    public function test_intro_page_group_labels_do_not_include_color_names(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('会社の魅力', $html);
        $this->assertStringContainsString('会社との距離', $html);
        $this->assertStringContainsString('仕事の魅力', $html);
        $this->assertStringNotContainsString('青／会社の魅力', $html);
        $this->assertStringNotContainsString('赤／仕事の魅力', $html);
    }

    // ------------------------------------------------------------------
    // 3. 自社サイトの分析結果。
    // ------------------------------------------------------------------

    public function test_self_analysis_page_includes_counts_axis_cards_and_summary(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('自社サイトの分析結果', $html);
        $this->assertStringContainsString('活動的魅力', $html);
        $this->assertStringContainsString('2<small> / 4件</small>', $html);
        $this->assertStringContainsString('パーパス', $html);
        $this->assertStringContainsString('技術で社会基盤を支える', $html);
        $this->assertStringContainsString('活動的魅力が最も内容として充足しています', $html);
        // AI生成コンテンツ(key_message/impression)である旨の開示。
        $this->assertStringContainsString('AIを使用', $html);
    }

    /**
     * 2026-08-08: impression(候補者に与える印象)がstringからlist<string>へ
     * 変更されたことに伴い、箇条書きで表示する。地の文(pタグの連続文)としては
     * 出さない。2026-08-09: 実データ検証で紺帯(darkband)が実際のページに
     * 収まらずページをまたぐ不具合が見つかったため、縦積み(<ul>)から
     * 2列(<table class="impressiontbl">)へ変更した(ユーザー承認の
     * 「上限付き」案)。あわせてBrandWheelLeadResponseComposerが
     * impression_itemsを最大3件・各45文字に切り詰めるようになった。
     */
    public function test_self_analysis_page_shows_impression_items_as_a_bulleted_list(): void
    {
        $html = $this->render($this->viewModel([
            'brandWheelSelf' => $this->wheel([
                'axes' => [
                    ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
                ],
                'impression' => '事実の記載が中心、情緒的な訴求は薄い',
                'impression_items' => ['事実の記載が中心', '情緒的な訴求は薄い'],
            ]),
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose([
                ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
            ], []),
        ]));

        $this->assertStringContainsString('<table class="impressiontbl">', $html);
        $this->assertStringContainsString('・事実の記載が中心', $html);
        $this->assertStringContainsString('・情緒的な訴求は薄い', $html);
        $this->assertStringContainsString('AI解析による候補者に与える印象', $html);
    }

    public function test_self_analysis_page_omits_the_dark_band_when_there_is_no_key_message_or_impression(): void
    {
        $html = $this->render($this->viewModel([
            'brandWheelSelf' => $this->wheel([
                'axes' => [
                    ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
                ],
            ]),
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose([
                ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']], 'label_only_sub_elements' => []],
            ], []),
        ]));

        $this->assertStringNotContainsString('AI解析による候補者に与える印象', $html);
        $this->assertStringNotContainsString('収集した情報から想定されるキーメッセージ', $html);
    }

    /**
     * status!=='success'のとき図も表も出さず、status_messageのみを出す。
     * 6項目すべて0件の表(「魅力のない会社」に見えてしまう)は禁止。
     * 3・4・5・6ページ目すべてが同じ理由でstatus_messageに切り替わる。
     */
    public function test_pages_fall_back_to_the_status_message_and_render_no_grids_when_status_is_not_success(): void
    {
        $html = $this->render($this->viewModel([
            'brandWheelSelf' => $this->wheel([
                'status' => 'no_matched_content',
                'status_message' => 'サイトの記述からは、6つの項目に該当する内容を読み取れませんでした。',
            ]),
            'brandWheelComparison' => ['self_points' => [], 'competitor_points' => [], 'one_point' => null],
            'selfTotalMatched' => 0,
            'selfTotalMax' => 0,
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose([], []),
        ]));

        $this->assertStringContainsString('サイトの記述からは、6つの項目に該当する内容を読み取れませんでした', $html);
        $this->assertStringNotContainsString('パーパス', $html);
        // 「採用ブランドの捉え方」前置きページは軸名(活動的魅力等)を固定の
        // 説明文として常に含むため、軸名そのものの有無ではなく、実際の
        // 軸カード(件数の丸)が出ていないことで判定する。
        $this->assertStringNotContainsString('class="axcnt"', $html);
        // ○△－の対比表・改善提案のいずれも実際のグリッド/カードを描画しない。
        $this->assertStringNotContainsString('class="vstbl"', $html);
        $this->assertStringNotContainsString('class="rcard"', $html);
    }

    public function test_self_analysis_page_falls_back_to_the_axis_table_only_when_the_radar_png_is_missing(): void
    {
        $html = $this->render($this->viewModel(['brandWheelRadarPngSelf' => null]));

        $this->assertStringNotContainsString('width: 66mm; height: 48mm;', $html);
        $this->assertStringContainsString('2<small> / 4件</small>', $html);
    }

    public function test_self_analysis_page_embeds_the_radar_png_as_a_base64_image_when_available(): void
    {
        $png = "\x89PNG\r\n\x1a\n".'dummy-bytes-for-self';

        $html = $this->render($this->viewModel(['brandWheelRadarPngSelf' => $png]));

        $this->assertStringContainsString('<img src="data:image/png;base64,'.base64_encode($png).'"', $html);
    }

    public function test_axis_card_table_uses_explicit_mm_widths_not_percentages(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('.axcell { width: 44.16mm;', $html);
        $this->assertStringNotContainsString('.axcell { width: 16.66%;', $html);
    }

    public function test_axis_card_dots_are_generated_from_max_count_not_a_hardcoded_four(): void
    {
        $html = $this->render($this->viewModel([
            'brandWheelSelf' => $this->wheel([
                'axes' => [
                    ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 3, 'max_count' => 5, 'matched_sub_elements' => [
                        ['key' => 'purpose', 'name' => 'パーパス'], ['key' => 'a', 'name' => 'A'], ['key' => 'b', 'name' => 'B'],
                    ], 'label_only_sub_elements' => []],
                ],
            ]),
        ]));

        $this->assertStringContainsString('3<small> / 5件</small>', $html);
        $this->assertSame(5, substr_count($html, '<span class="dot'));
        $this->assertSame(3, substr_count($html, 'class="dot on"'));
    }

    public function test_axis_card_still_shows_empty_dots_and_no_evidence_message_when_matched_count_is_zero(): void
    {
        $html = $this->render($this->viewModel([
            'brandWheelSelf' => $this->wheel([
                'axes' => [
                    ['key' => 'relationship', 'group' => 'company_distance', 'name' => '就業環境', 'matched_count' => 0, 'max_count' => 4, 'matched_sub_elements' => [], 'label_only_sub_elements' => []],
                ],
            ]),
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
            'brandWheelSelf' => $this->wheel([
                'axes' => [
                    ['key' => 'financial_benefit', 'group' => 'job_appeal', 'name' => '金銭的便益', 'matched_count' => 4, 'max_count' => 4, 'matched_sub_elements' => [
                        ['key' => 'salary_level', 'name' => '給与水準'], ['key' => 'benefits', 'name' => '福利厚生'],
                        ['key' => 'growth_opportunity', 'name' => '成長機会'], ['key' => 'employment_stability', 'name' => '雇用の安定性'],
                    ], 'label_only_sub_elements' => []],
                ],
            ]),
        ]));

        $this->assertStringContainsString('height: 38mm', $html);
        $this->assertStringContainsString('4<small> / 4件</small>', $html);
        $this->assertSame(4, substr_count($html, 'class="dot on"'));
    }

    // ------------------------------------------------------------------
    // 4. 競合サイトの分析結果(2026-08-08新設)。
    // ------------------------------------------------------------------

    public function test_competitor_analysis_page_is_omitted_when_there_is_no_competitor_website(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringNotContainsString('競合サイトの分析結果', $html);
    }

    /**
     * 3ページ目(自社)・4ページ目(競合)は完全に同じパーシャルを主体だけ
     * 変えて2回includeする(ユーザー指定)。見出し・件数・サマリー・
     * 軸カードのいずれも自社ページと同じ形式で出ることを確認する。
     */
    public function test_competitor_analysis_page_uses_the_same_layout_as_the_self_page_when_a_competitor_exists(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString('競合サイトの分析結果', $html);
        $this->assertStringContainsString('就業環境', $html);
        $this->assertStringContainsString('同僚・先輩像', $html);
        $this->assertStringContainsString('職場の雰囲気', $html);
        $this->assertStringContainsString('就業環境が最も内容として充足しています', $html);
        $this->assertStringContainsString('比較サイト 3 / 8項目', $html);
    }

    public function test_competitor_analysis_page_falls_back_to_the_status_message_when_competitor_is_not_readable(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'brandWheelCompetitor' => $this->wheel([
                'status' => 'recruit_page_unreadable',
                'status_message' => '採用ページの内容を取得できなかったため、この項目の分析は行っていません。',
            ]),
        ]));

        $this->assertStringContainsString('競合サイトの分析結果', $html);
        $this->assertStringContainsString('採用ページの内容を取得できなかったため', $html);
    }

    // ------------------------------------------------------------------
    // 5. ○△－の対比表(2026-08-08、●／－の2値から3値へ変更)。
    // ------------------------------------------------------------------

    public function test_comparison_page_uses_circle_triangle_and_dash_marks(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $start = mb_strpos($html, '○△－の対比表');
        $this->assertNotFalse($start);
        $end = mb_strpos($html, '改善提案', $start) ?: mb_strlen($html);
        $pageHtml = mb_substr($html, $start, $end - $start);

        $this->assertStringContainsString('<span class="mkon">○</span>', $pageHtml);
        $this->assertStringContainsString('<span class="mkoff">－</span>', $pageHtml);
        // 旧仕様の●は使わない。
        $this->assertStringNotContainsString('●', $pageHtml);
        $this->assertStringNotContainsString('×', $pageHtml);
    }

    /**
     * 見出し・リンクラベルのみ(label_only)の項目は△(mktri)で表示される
     * ―― ○(本文照合済み)・－(該当なし)のどちらとも視覚的に紛れない
     * 中間色にする(ユーザー指定)。判定はBrandWheelSubElementComparisonComposer
     * (プログラム側)のみが行い、AIには一切3段階を判定させない。
     */
    public function test_comparison_page_shows_a_triangle_mark_for_label_only_items(): void
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4,
                'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']],
                'label_only_sub_elements' => [['key' => 'business_expansion', 'name' => '事業展開']]],
        ];
        $competitorAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 0, 'max_count' => 4,
                'matched_sub_elements' => [],
                'label_only_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']]],
        ];
        $subElementComparison = app(BrandWheelSubElementComparisonComposer::class)->compose($selfAxes, $competitorAxes);

        $html = $this->render($this->comparisonViewModel([
            'brandWheelSelf' => $this->wheel(['axes' => $selfAxes]),
            'brandWheelCompetitor' => $this->wheel(['axes' => $competitorAxes]),
            'subElementComparison' => $subElementComparison,
            'selfTotalMatched' => 1,
            'selfTotalMax' => 4,
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 4,
            'selfTotalLabelOnly' => 1,
            'competitorTotalLabelOnly' => 1,
        ]));

        $start = mb_strpos($html, '○△－の対比表');
        $end = mb_strpos($html, '改善提案', $start) ?: mb_strlen($html);
        $pageHtml = mb_substr($html, $start, $end - $start);

        $this->assertStringContainsString('<span class="mktri">△</span>', $pageHtml);
    }

    public function test_comparison_page_shows_the_label_only_reference_counts_separately_from_the_total(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'selfTotalLabelOnly' => 2,
            'competitorTotalLabelOnly' => 3,
        ]));

        $this->assertStringContainsString('(参考)　<span class="mktri">△</span> 自社 2件', $html);
        $this->assertStringContainsString('<span class="mktri">△</span> 比較 3件', $html);
    }

    /**
     * 引用符は『』を使う(ユーザー指定の「絶対に消してはいけない文言」原文どおり)。
     */
    public function test_comparison_page_keeps_the_required_caveat_sentence_verbatim(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString(
            '該当する記述が見つからなかった項目(『魅力が無い』という意味ではありません)',
            $html,
        );
    }

    public function test_comparison_page_drops_the_competitor_column_when_there_is_no_competitor_website(): void
    {
        $html = $this->render($this->viewModel());

        $start = mb_strpos($html, '○△－の対比表');
        $this->assertNotFalse($start);
        $end = mb_strpos($html, '改善提案', $start) ?: mb_strlen($html);
        $pageHtml = mb_substr($html, $start, $end - $start);

        $this->assertStringNotContainsString('<th>比較</th>', $pageHtml);
    }

    public function test_comparison_page_shows_the_competitor_column_when_a_competitor_website_exists(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $start = mb_strpos($html, '○△－の対比表');
        $end = mb_strpos($html, '改善提案', $start) ?: mb_strlen($html);
        $pageHtml = mb_substr($html, $start, $end - $start);

        $this->assertStringContainsString('<th>比較</th>', $pageHtml);
    }

    /**
     * 合計は自社/競合ページの分析結果ページと同じ$viewModel->selfTotalMatched
     * 等(単一のソース)を使う ―― ページごとに個別集計しない
     * (docs/lead-report-layout/README.md)。
     */
    public function test_comparison_page_total_matches_the_same_source_as_the_self_and_competitor_results_pages(): void
    {
        $viewModel = $this->comparisonViewModel();
        $html = $this->render($viewModel);

        $this->assertStringContainsString("自社サイト {$viewModel->selfTotalMatched} / {$viewModel->selfTotalMax}項目", $html);
        $this->assertStringContainsString("比較サイト {$viewModel->competitorTotalMatched} / {$viewModel->competitorTotalMax}項目", $html);
    }

    public function test_comparison_page_embeds_the_self_times_competitor_overlay_radar_png_when_available(): void
    {
        $png = "\x89PNG\r\n\x1a\n".'dummy-bytes-for-comparison';

        $html = $this->render($this->comparisonViewModel(['brandWheelRadarPngComparison' => $png]));

        $this->assertStringContainsString('<img src="data:image/png;base64,'.base64_encode($png).'"', $html);
    }

    public function test_comparison_page_omits_the_overlay_radar_when_competitor_is_not_readable(): void
    {
        $png = "\x89PNG\r\n\x1a\n".'dummy-bytes-should-not-appear';

        $html = $this->render($this->viewModel(['brandWheelRadarPngComparison' => $png]));

        $this->assertStringNotContainsString(base64_encode($png), $html);
    }

    // ------------------------------------------------------------------
    // 6. 改善提案。
    // ------------------------------------------------------------------

    public function test_improvement_page_keeps_the_required_sentence_verbatim(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString(
            'なお、これらを『サイトに書き足す』ことで解決するとは限りません。実態はあるのに伝えられていないのか、'
            .'まだ言葉になっていないのか ―― その切り分けについては最終ページをご覧ください。',
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

    public function test_improvement_page_shows_a_fallback_note_when_there_is_no_competitor(): void
    {
        $html = $this->render($this->viewModel());

        $start = mb_strpos($html, '改善提案');
        $this->assertNotFalse($start);
        $pageHtml = mb_substr($html, $start);

        $this->assertStringContainsString('比較サイトが無いため、領域ごとの比較はご用意できません。', $pageHtml);
        $this->assertStringNotContainsString('class="rcard"', $pageHtml);
    }

    /**
     * 4観点(測定結果)ページを削除したのに技術的提案だけ残すのは整合が
     * 取れないため、下部の技術的提案ブロック(「あわせて、サイトの作りに
     * ついて」)を削除した(2026-08-08、ユーザー判断)。回帰確認。
     */
    public function test_improvement_page_no_longer_includes_the_deleted_technical_recommendations_block(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringNotContainsString('あわせて、サイトの作りについて', $html);
    }

    // ------------------------------------------------------------------
    // 7. サイトの改善をすれば課題が解決するとは限りません(最終ページ、
    //    2026-08-08新文言)。
    // ------------------------------------------------------------------

    /**
     * 2026-08-10: 連絡先確定(ユーザー指示)。仮文言「担当営業までご連絡
     * ください。」を実際の連絡先(公式問い合わせページURL+発行日/貴社名を
     * 伝える一文)に差し替えた。電話番号・外部フォームツール本体のURLは
     * 掲載しない(README「既知の限界」参照)。発行日は表紙と同じ
     * $viewModel->generatedAtLabelを参照し、二重管理しない。
     */
    public function test_final_page_uses_the_new_heading_and_copy(): void
    {
        $viewModel = $this->viewModel();
        $html = $this->render($viewModel);

        $this->assertStringContainsString('サイトの改善をすれば', $html);
        $this->assertStringContainsString('課題が解決するとは限りません', $html);
        $this->assertStringContainsString(
            'サイトの改善が最も効果的な打ち手となるのか、応募から内定までの間での候補者とのタッチポイント全体の設計を改めて行うことで大きな効果を得られるのかを見直す必要があります。',
            $html,
        );
        $this->assertStringContainsString('ご相談・お問い合わせ', $html);
        $this->assertStringContainsString('https://www.leggenda.co.jp/contact/', $html);
        $this->assertStringContainsString("お問い合わせの際は、本レポートの発行日（{$viewModel->generatedAtLabel}）と貴社名をお知らせください。", $html);
        $this->assertStringNotContainsString('leggenda-co.web-tools.biz', $html);
        $this->assertStringNotContainsString('お電話', $html);
    }

    public function test_final_page_never_uses_the_old_heading_or_three_block_structure(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringNotContainsString('ここから先は、サイトの外の話です', $html);
        $this->assertStringNotContainsString('書かれていない項目には2つの意味があります', $html);
        $this->assertStringNotContainsString('その切り分けはサイトからはできません', $html);
        $this->assertStringNotContainsString('私たちは採用ブランドの設計からご一緒します', $html);
    }
}
