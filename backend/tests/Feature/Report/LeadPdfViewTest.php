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
            'positive_impression' => null,
            'negative_impression' => null,
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
                'positive_impression' => '事業内容への取り組み姿勢が伝わり、良い印象を与える可能性があります。',
                'negative_impression' => '働く環境の具体像がイメージしづらい可能性があります。',
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
        ];
        $defaults['improvementFocusSelfOnly'] = app(BrandWheelImprovementFocusComposer::class)->composeSelfOnly($defaults['subElementComparison']);

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
                'positive_impression' => '事業内容への取り組み姿勢が伝わり、良い印象を与える可能性があります。',
                'negative_impression' => null,
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
        // 2026-08-18: バックエンドはAIを使用しているが、読み手向けUIには
        // AI利用の開示テキストを一切出さない方針に変更(依頼者指定)。
        $this->assertStringNotContainsString('AIを使用', $html);
        $this->assertStringNotContainsString('AI解析', $html);
        $this->assertStringNotContainsString('AIが分析', $html);
        $this->assertStringNotContainsString('AIを用いて', $html);
        $this->assertStringNotContainsString('AIによる', $html);
    }

    /**
     * 2026-08-17: 「AI解析による候補者に与える印象」(短いフレーズの箇条書き)
     * から、ポジティブ/ネガティブの2文構成へ変更(依頼者指定)。見出しからも
     * 「AI解析による」を外す(AI利用を前面に出さない)。
     * 2026-08-18: 単一見出し「候補者に与える印象：」配下の箇条書きから、
     * 「ポジティブな印象」「ネガティブな印象」の2見出しへさらに分離(依頼者指定)。
     */
    public function test_self_analysis_page_shows_positive_and_negative_impression(): void
    {
        $html = $this->render($this->viewModel([
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

        $this->assertStringContainsString('ポジティブな印象：', $html);
        $this->assertStringContainsString('ネガティブな印象：', $html);
        $this->assertStringNotContainsString('候補者に与える印象：', $html);
        $this->assertStringNotContainsString('AI解析による候補者に与える印象', $html);
        $this->assertStringContainsString('事業内容への取り組み姿勢が伝わり、良い印象を与える可能性があります。', $html);
        $this->assertStringContainsString('働く環境の具体像がイメージしづらい可能性があります。', $html);
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

        $this->assertStringNotContainsString('ポジティブな印象：', $html);
        $this->assertStringNotContainsString('ネガティブな印象：', $html);
        $this->assertStringNotContainsString('サイト上の情報から想定されるキーメッセージ', $html);
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

    /**
     * 自社/競合とも実運用と同じ「6軸すべてが常に存在する」形(matched_count/
     * matched_sub_elementsだけが軸ごとに異なる)のwheelを組み立てる。
     * comparisonViewModel()の手組みfixture(他テスト用に一部の軸だけを
     * 持たせている)とは異なり、BrandWheelLeadResponseComposer::buildAxes()が
     * 実際に返す形(config('brand_wheel.axes')の6軸すべてが常に1件ずつ入る)を
     * 再現する ―― レイアウト整列テストは軸カードの個数が自社/競合で一致する
     * という前提に依存するため。
     */
    private function fullWheelAxes(array $matchedCountsByAxis): array
    {
        $axes = [];
        foreach (config('brand_wheel.axes') as $axisKey => $definition) {
            $matchedCount = $matchedCountsByAxis[$axisKey] ?? 0;
            $subKeys = array_slice(array_keys($definition['sub_elements']), 0, $matchedCount);
            $axes[] = [
                'key' => $axisKey,
                'group' => $definition['group'],
                'name' => $definition['name_ja'],
                'matched_count' => $matchedCount,
                'max_count' => count($definition['sub_elements']),
                'matched_sub_elements' => array_map(
                    fn (string $k) => ['key' => $k, 'name' => $definition['sub_elements'][$k]],
                    $subKeys,
                ),
                'label_only_sub_elements' => [],
            ];
        }

        return $axes;
    }

    /**
     * 2026-08-21追加: 自社/競合ページのレイアウト整列改修。件数ボックス＋
     * サマリー(左列)とレーダー(右列)の高さが内容量(サマリーの行数・
     * レーダー画像の有無)によって変わり、自社/競合ページを重ねて比較すると
     * 6カテゴリ帯以下が数mmずれる不具合が実PDF確認(PyMuPDFでの座標実測)で
     * 見つかった。両列に同じmin-heightのdivを入れて行の高さを固定した
     * ことを、レンダリング結果に両方のdivが存在することで確認する
     * (実際のY座標の一致自体はPHPUnitでは検証できないため、実PDF実測は
     * 別途tinker+PyMuPDFで実施し、7ページ構成・余白を確認済み)。
     */
    public function test_self_and_competitor_analysis_pages_reserve_the_same_min_height_for_the_score_card_and_radar_columns(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertSame(4, substr_count($html, '<div style="min-height: 68mm;">'));
    }

    /**
     * 自社/競合ページは同じpartial(lead-pdf-brand-wheel-page.blade.php)を
     * 2回includeするだけで、主体(自社/競合)以外のマークアップ構造・CSSは
     * 完全に同一であることを確認する(依頼者指定「同じテンプレートに別データを
     * 流し込んだように見える状態」の構造的な裏付け)。具体的な該当有無・
     * 件数・軸名等はデータに応じて正当に変わるため文字列完全一致では検証
     * できない ―― 代わりに、レイアウトを決める主要な構造要素(min-height
     * ラッパー・件数ボックス・軸カード6件・ドット合計24個・大分類帯3本)の
     * 出現回数が自社/競合セクションで完全に一致することを確認する。
     * サマリー行数(自社4行/競合1行)・軸ごとのmatched_count・URLはあえて
     * 大きく変えており、それでも構造は一致することを検証する。
     */
    public function test_self_and_competitor_analysis_pages_share_identical_structural_markup_counts(): void
    {
        $selfAxes = $this->fullWheelAxes([
            'will_activity' => 3, 'asset' => 0, 'personality' => 0,
            'relationship' => 0, 'emotional_benefit' => 0, 'financial_benefit' => 0,
        ]);
        $competitorAxes = $this->fullWheelAxes([
            'will_activity' => 3, 'asset' => 3, 'personality' => 3,
            'relationship' => 3, 'emotional_benefit' => 3, 'financial_benefit' => 3,
        ]);

        $html = $this->render($this->viewModel([
            'competitorWebsiteUrl' => 'https://competitor.example.com/careers/newgraduate/2027entry/veryverylongpath',
            'brandWheelSelf' => $this->wheel(['axes' => $selfAxes]),
            'brandWheelCompetitor' => $this->wheel([
                'analyzed_url' => 'https://competitor.example.com/',
                'axes' => $competitorAxes,
            ]),
            'subElementComparison' => app(BrandWheelSubElementComparisonComposer::class)->compose($selfAxes, $competitorAxes),
        ]));

        $start = mb_strpos($html, '<h2>自社サイトの分析結果</h2>');
        $midpoint = mb_strpos($html, '<h2>競合サイトの分析結果</h2>');
        $end = mb_strpos($html, '<h2>', $midpoint + 1);

        $selfSection = mb_substr($html, $start, $midpoint - $start);
        $competitorSection = mb_substr($html, $midpoint, $end - $midpoint);

        $countAll = fn (string $section, string $needle): int => substr_count($section, $needle);

        foreach ([
            '<div style="min-height: 68mm;">',
            '<div class="statbox">',
            'class="axcell"',
            'class="axhead"',
            'class="axbody"',
            '<span class="dot',
            'colspan="2"',
        ] as $needle) {
            $this->assertSame(
                $countAll($selfSection, $needle),
                $countAll($competitorSection, $needle),
                "「{$needle}」の出現回数が自社/競合ページで一致していません。",
            );
        }
    }

    /**
     * 2026-08-22: 大分類帯と6カテゴリカードを1つのtableへ統合したことに
     * 伴い、列幅の一元管理を<colgroup>の6本の<col>へ移した(旧.axcell{width}
     * から移動)。縦線を確実に一致させるため、大分類帯・カード行の両方が
     * この同じ<colgroup>を共有していることも確認する。
     */
    public function test_brand_wheel_table_uses_a_shared_colgroup_with_explicit_mm_widths(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertSame(6, substr_count($html, '<col style="width: 44.16mm;">'));
        $this->assertStringNotContainsString('.axcell { width: 16.66%;', $html);
        $this->assertStringNotContainsString('width: 16.66%', $html);
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

    /**
     * 2026-08-22: .axbodyの固定高さは38mm→30mmへ調整した(依頼者指定の
     * グリッド整列改修に伴い、カード内部を見出し/スコア/インジケータ/内容の
     * 4領域へ分割・タイト化したことで、4項目該当ケースでも30mmで安全に
     * 収まることを実PDF実測で確認したため)。この項目自体が固定値であること
     * (内容量で変わらないこと)の検証が目的のため、具体的な数値ではなく
     * 「明示的なheightが指定されていること」を確認する。
     */
    public function test_axis_body_has_a_fixed_height_even_when_all_four_sub_elements_matched(): void
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

        $this->assertMatchesRegularExpression('/\.axbody \{[^}]*height: \d+(\.\d+)?mm;/', $html);
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

    /**
     * 2026-08-19: 旧文言(「なお、これらを『サイトに書き足す』ことで解決する
     * とは限りません…」)は、改善提案ページ末尾でこの1文だけが次ページへ
     * あふれてしまう不具合(実PDF確認で発見)の原因だったため短縮した
     * (依頼者承認)。
     */
    public function test_improvement_page_keeps_the_shortened_closing_note_verbatim(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString('サイト上の情報追加だけでなく、実態として存在する魅力の整理も重要です。', $html);
        $this->assertStringNotContainsString('なお、これらを『サイトに書き足す』ことで解決するとは限りません', $html);
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

    /**
     * 2026-08-10: 「比較サイトが無いため、領域ごとの比較はご用意できません。」
     * の1行だけでページの大半が空白になり、営業資料として成立しないという
     * 指摘(ユーザー)を受け、競合が無い場合も自社の「－」「△」項目で構成した
     * カードを出す形に置き換えた。旧フォールバック文言は削除済み。
     */
    public function test_improvement_page_shows_self_only_cards_when_there_is_no_competitor(): void
    {
        $html = $this->render($this->viewModel());

        $start = mb_strpos($html, '改善提案');
        $this->assertNotFalse($start);
        $pageHtml = mb_substr($html, $start);

        $this->assertStringNotContainsString('比較サイトが無いため、領域ごとの比較はご用意できません。', $pageHtml);
        $this->assertStringContainsString('class="rcard"', $pageHtml);
        // 競合の実データ(比較サイトの記述)ブロックは出さない。
        $this->assertStringNotContainsString('比較サイトの記述', $pageHtml);
        // リード文は競合版と文言が異なる(比較サイト件数への言及を含まない)。
        $this->assertStringContainsString('サイトの記述から読み取れた項目が最も少なかったのは', $pageHtml);
        $this->assertStringNotContainsString('比較サイトとの差', $pageHtml);
    }

    public function test_improvement_page_self_only_cards_distinguish_none_from_label_only(): void
    {
        // will_activity(company_appeal)に「－」1件(social_contribution)+
        // 「△」1件(purpose)だけを残し、他5軸は全件○で埋める。company_appeal
        // グループの「－」は1件しかないため、3件選定のルール(－優先・
        // 3件に満たない場合のみ△で埋める)により、purpose(△)が候補に
        // 入ることを確認できる(「－」だけで3件埋まってしまうと△が
        // テストされないため、意図的に「－」を1件だけにしている)。
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [
                ['key' => 'business_expansion', 'name' => '展開事業・商品'], ['key' => 'project_initiative', 'name' => 'PJ・新たな取組'],
            ], 'label_only_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']]],
        ];
        foreach ((array) config('brand_wheel.axes') as $axisKey => $axis) {
            if ($axisKey === 'will_activity') {
                continue;
            }
            $selfAxes[] = [
                'key' => $axisKey,
                'group' => $axis['group'],
                'name' => $axis['name_ja'],
                'matched_count' => count($axis['sub_elements']),
                'max_count' => count($axis['sub_elements']),
                'matched_sub_elements' => collect($axis['sub_elements'])->map(fn ($name, $subKey) => ['key' => $subKey, 'name' => $name])->values()->all(),
                'label_only_sub_elements' => [],
            ];
        }
        $subElementComparison = app(BrandWheelSubElementComparisonComposer::class)->compose($selfAxes, []);
        $improvementFocusSelfOnly = app(BrandWheelImprovementFocusComposer::class)->composeSelfOnly($subElementComparison);

        $html = $this->render($this->viewModel([
            'brandWheelSelf' => $this->wheel(['axes' => $selfAxes]),
            'subElementComparison' => $subElementComparison,
            'improvementFocusSelfOnly' => $improvementFocusSelfOnly,
        ]));

        $start = mb_strpos($html, '改善提案');
        $this->assertNotFalse($start);
        $pageHtml = mb_substr($html, $start);

        // purpose(パーパス)は△(label_only)由来のため、－由来の「記述が
        // 見つかりませんでした」ではなく、見出し・ラベルのみと分かる文言。
        $this->assertStringContainsString('見出し・リンクラベルのみで、具体的な記述は見つかりませんでした', $pageHtml);
    }

    /**
     * 自社24項目すべてが○(実運用ではまず起きない)の場合、composeSelfOnly()は
     * nullを返し、このページ自体を丸ごと省略する(白紙ページを作らないための
     * 保険、2026-08-10)。
     */
    public function test_improvement_page_is_omitted_when_self_has_no_competitor_and_all_items_matched(): void
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

        $html = $this->render($this->viewModel([
            'brandWheelSelf' => $this->wheel(['axes' => $allMatchedAxes]),
            'subElementComparison' => $subElementComparison,
            'improvementFocusSelfOnly' => app(BrandWheelImprovementFocusComposer::class)->composeSelfOnly($subElementComparison),
        ]));

        $this->assertStringNotContainsString('<h2>改善提案</h2>', $html);
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
     * 2026-08-17: 長い説明文(「サイトの改善をすれば課題が解決するとは
     * 限りません」+本文2段落)を削除し、営業CTAに集中させた(依頼者指定)。
     * URLは生の文字列としてではなくボタン風のラベル付きリンクで表示する。
     * 発行日は表紙と同じ$viewModel->generatedAtLabelを参照し、二重管理しない。
     */
    public function test_final_page_uses_the_new_ctacopy(): void
    {
        $viewModel = $this->viewModel();
        $html = $this->render($viewModel);

        $this->assertStringContainsString('さらに3〜5社の競合採用サイトと比較し', $html);
        $this->assertStringContainsString('御社が優先して改善すべき課題を整理しませんか', $html);
        $this->assertStringContainsString('詳細な比較結果をもとに、採用課題についてディスカッションします。', $html);
        $this->assertStringContainsString('競合比較について相談する', $html);
        $this->assertStringContainsString('href="https://www.leggenda.co.jp/inquiry/"', $html);
        $this->assertStringContainsString("お問い合わせの際は、本レポートの発行日（{$viewModel->generatedAtLabel}）と貴社名をお知らせください。", $html);
        $this->assertStringNotContainsString('leggenda-co.web-tools.biz', $html);
        $this->assertStringNotContainsString('お電話', $html);
    }

    public function test_final_page_never_uses_old_copy_variants(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringNotContainsString('ここから先は、サイトの外の話です', $html);
        $this->assertStringNotContainsString('書かれていない項目には2つの意味があります', $html);
        $this->assertStringNotContainsString('その切り分けはサイトからはできません', $html);
        $this->assertStringNotContainsString('私たちは採用ブランドの設計からご一緒します', $html);
        $this->assertStringNotContainsString('サイトの改善をすれば課題が解決するとは限りません', $html);
        $this->assertStringNotContainsString('応募から内定までの間での候補者とのタッチポイント全体の設計', $html);
    }

    // ------------------------------------------------------------------
    // 2026-08-17改修分: 件数表記・軸説明・URL分析範囲・比較サマリー・
    // 改善提案AI(ワンポイント/詳細提言)。
    // ------------------------------------------------------------------

    public function test_self_analysis_page_shows_the_n_of_24_label_with_explanation(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('確認できた情報', $html);
        $this->assertStringContainsString('ブランドホイール24項目のうち、サイト上で情報を確認できた項目数', $html);
        $this->assertStringNotContainsString('6つの項目それぞれについて、該当する内容がサイトの記述から何件読み取れたかを集計しています', $html);
    }

    public function test_intro_page_shows_axis_level_definitions_and_url_scope_note(): void
    {
        $html = $this->render($this->viewModel());

        // config('brand_wheel.axes.will_activity.definition')をそのまま流用している。
        $this->assertStringContainsString('その会社が何を目指し、どんな事業・取組・社会貢献を通じて', $html);
        $this->assertStringContainsString('意志(Will)を体現しているか。', $html);
        $this->assertStringContainsString('本分析は、ご提供いただいた採用ページ・トップページの記述を対象としており、サイト全体や他の関連ページを自動的に巡回して分析するものではありません。', $html);
    }

    public function test_comparison_page_shows_overview_summary_and_group_verdict_badge_when_a_competitor_exists(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString('比較結果サマリー', $html);
        $this->assertStringContainsString('自社は1 / 4項目、競合は3 / 8項目の情報が確認できました。', $html);
        // fixtureはcompany_distance(会社との距離)で自社0件・競合2件(diff=-2)。
        $this->assertStringContainsString('（競合優位）', $html);
    }

    public function test_comparison_page_omits_overview_summary_when_there_is_no_competitor(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringNotContainsString('比較結果サマリー', $html);
    }

    public function test_improvement_page_one_point_uses_the_ai_generated_text_when_available(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'improvementOnePoint' => 'まずは既存情報だけで追加できる仕事内容・キャリア情報から充実させましょう。',
        ]));

        $this->assertStringContainsString('【ワンポイント】', $html);
        $this->assertStringContainsString('まずは既存情報だけで追加できる仕事内容・キャリア情報から充実させましょう。', $html);
    }

    /**
     * 2026-08-18: 旧「改善のご提案」単一パラグラフ(improvementRecommendation)を
     * 廃止し、理由/具体的に追加すべき情報/中長期の差別化ポイントの3ブロックへ
     * 分解した(依頼者指定 ―― 結論だけでなく、なぜ・何を・いつまでにが追える
     * 構成にする)。
     * 2026-08-19: 「中長期的には：」の1行を、「中長期の差別化ポイント」という
     * 独立した見出し付きボックス(.diffbox)へ格上げした(依頼者指定 ――
     * 「差を埋める提案」と「差別化提案」を役割として明確に分けるため)。
     */
    public function test_improvement_page_shows_reason_recommended_contents_and_differentiation_point_when_available(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString('理由：', $html);
        $this->assertStringContainsString('就業環境は競合が2件読み取れているのに対し自社は0件で、候補者が働くイメージを持ちにくい状態です。', $html);
        $this->assertStringContainsString('具体的に追加すべき情報', $html);
        $this->assertStringContainsString('入社数年目の社員の1日の過ごし方', $html);
        $this->assertStringContainsString('部署間の関わり方が分かるエピソード', $html);
        $this->assertStringContainsString('中長期の差別化ポイント', $html);
        $this->assertStringNotContainsString('中長期的には：', $html);
        $this->assertStringContainsString('部署横断プロジェクトの事例をシリーズ化することも検討できます。', $html);
    }

    public function test_improvement_page_omits_each_ai_block_independently_when_unavailable(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'improvementReason' => null,
            'improvementRecommendedContents' => [],
            'improvementMidTermAction' => null,
        ]));

        $this->assertStringNotContainsString('理由：', $html);
        $this->assertStringNotContainsString('具体的に追加すべき情報', $html);
        $this->assertStringNotContainsString('中長期の差別化ポイント', $html);
    }
}
