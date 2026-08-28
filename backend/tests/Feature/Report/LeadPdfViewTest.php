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

    /**
     * 依頼O-2/P-3(2026-08-25): 競合サイトのURLはあるが分析が成立しなかった
     * ($competitorReadable=false)場合、表紙は比較サイトのURLを案内し続ける
     * のに本文(3・5ページ)には比較サイトの列が一切無いという不一致が
     * あった。URLは残したまま、理由の注記を添える(案B、依頼者確定)。
     */
    public function test_cover_page_shows_the_url_and_a_notice_when_the_competitor_is_not_readable(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'brandWheelCompetitor' => $this->wheel([
                'status' => 'insufficient_input',
                'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
            ]),
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 0,
        ]));

        $this->assertStringContainsString('比較サイト: https://competitor.example.com', $html);
        $this->assertStringContainsString(config('brand_wheel.cover_competitor_unreadable_notice'), $html);
    }

    public function test_cover_page_omits_the_notice_when_the_competitor_is_readable(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringNotContainsString(config('brand_wheel.cover_competitor_unreadable_notice'), $html);
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

    /**
     * 依頼Q-1: crawl_site=falseの診断では、従来どおり「サイト全体や他の
     * 関連ページを自動的に巡回して分析するものではありません」という
     * 文言のまま(実際に巡回していないので正しい)。
     */
    public function test_intro_page_shows_the_crawl_disabled_scope_notice_by_default(): void
    {
        $html = $this->render($this->viewModel(['crawlSiteEnabled' => false]));

        $this->assertStringContainsString((string) config('brand_wheel.crawl_disabled_scope_notice'), $html);
        $this->assertStringNotContainsString((string) config('brand_wheel.crawl_enabled_scope_notice'), $html);
    }

    /**
     * 依頼Q-1最重要: crawl_site=trueの診断(レポート35、実際に50ページを
     * 巡回)では、「巡回していない」という事実と異なる文言を出さず、
     * 巡回ありの文言に切り替わること。ページ数上限等の設定値は書かない。
     */
    public function test_intro_page_shows_the_crawl_enabled_scope_notice_when_crawl_site_is_enabled(): void
    {
        $html = $this->render($this->viewModel(['crawlSiteEnabled' => true]));

        $this->assertStringContainsString((string) config('brand_wheel.crawl_enabled_scope_notice'), $html);
        $this->assertStringNotContainsString((string) config('brand_wheel.crawl_disabled_scope_notice'), $html);
        $this->assertStringNotContainsString('50ページ', $html);
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
     * 見つかった。左列にmin-heightのdivを入れて行の高さを固定したことを、
     * レンダリング結果にdivが存在することで確認する(実際のY座標の一致自体
     * はPHPUnitでは検証できないため、実PDF実測は別途tinker+PyMuPDFで実施し、
     * 7ページ構成・余白を確認済み)。
     * 2026-08-24: 68mm→66mmへ縮小(依頼者指摘 ―― サマリーが短い実データで
     * 空白が大きすぎた)。右列(レーダー)側はvertical-align:middleで中央寄せ
     * するためmin-heightを持たない ―― 左列のdivだけが行の高さを決める。
     */
    public function test_self_and_competitor_analysis_pages_reserve_the_same_min_height_for_the_score_card_column(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertSame(2, substr_count($html, '<div style="min-height: 66mm;">'));
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
            '<div style="min-height: 66mm;">',
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

    /**
     * 2026-08-18: 取得できなかった競合サイトについて「該当する記述が見つから
     * なかった」という空のfallbackページを出すのをやめ、ページ自体を出さない
     * ように変更(依頼者指定 ―― ゴディバの403調査から派生した誤記載の修正)。
     * $competitorReadable は competitorWebsiteUrl の有無ではなく、実際に
     * status==='success'かつaxesが空でないかで判定する(431行)。以前は557行
     * ・600行がcompetitorWebsiteUrlの有無だけで分岐していたため、URLはある
     * が読み取れなかった場合に、この空ページと「比較サイト 0 / 0項目」が
     * 出力されてしまっていた。
     */
    public function test_competitor_analysis_page_is_omitted_when_the_competitor_url_exists_but_is_not_readable(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'brandWheelCompetitor' => $this->wheel([
                'status' => 'insufficient_input',
                'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
            ]),
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 0,
        ]));

        $this->assertStringNotContainsString('競合サイトの分析結果', $html);
        $this->assertStringNotContainsString('サイトから十分な文章を読み取れなかったため', $html);
    }

    public function test_report_has_seven_pages_when_the_competitor_is_readable(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertSame(7, substr_count($html, 'class="page'));
    }

    public function test_report_has_six_pages_when_there_is_no_competitor_website(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertSame(6, substr_count($html, 'class="page'));
    }

    public function test_report_has_six_pages_when_the_competitor_url_exists_but_is_not_readable(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'brandWheelCompetitor' => $this->wheel([
                'status' => 'insufficient_input',
                'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
            ]),
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 0,
        ]));

        $this->assertSame(6, substr_count($html, 'class="page'));
    }

    public function test_comparison_page_does_not_show_a_zero_over_zero_competitor_line_when_the_competitor_is_not_readable(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'brandWheelCompetitor' => $this->wheel([
                'status' => 'insufficient_input',
                'status_message' => 'サイトから十分な文章を読み取れなかったため、この項目の分析は行っていません。',
            ]),
            'competitorTotalMatched' => 0,
            'competitorTotalMax' => 0,
        ]));

        $this->assertStringNotContainsString('比較サイト 0 / 0項目', $html);
        $this->assertStringNotContainsString('<th>比較</th>', $html);
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
    // 6. ○と判定した根拠(依頼R、2026-08-26新設)。
    // ------------------------------------------------------------------

    public function test_evidence_page_is_omitted_when_self_evidence_by_axis_is_empty(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringNotContainsString('○と判定した根拠', $html);
    }

    /**
     * 依頼R: matchedが6件(複数軸)のとき、6件すべてが引用付きで、対比表と
     * 同じ軸順で表示されること。導入文はconfig由来。
     */
    public function test_evidence_page_shows_axis_grouped_quotes_in_config_order(): void
    {
        $selfEvidenceByAxis = [
            ['axis_name' => '活動的魅力', 'items' => [
                ['sub_name' => 'パーパス', 'evidence' => 'パーパスの原文抜粋です。'],
                ['sub_name' => '展開事業・商品', 'evidence' => '事業内容の原文抜粋です。'],
            ]],
            ['axis_name' => '資産的魅力', 'items' => [
                ['sub_name' => '知名度・評判', 'evidence' => '知名度の原文抜粋です。'],
            ]],
            ['axis_name' => '経営スタイル', 'items' => [
                ['sub_name' => 'リーダーシップ', 'evidence' => 'リーダーシップの原文抜粋です。'],
            ]],
            ['axis_name' => '就業環境', 'items' => [
                ['sub_name' => '同僚・先輩像', 'evidence' => '同僚・先輩像の原文抜粋です。'],
            ]],
            ['axis_name' => '金銭的便益', 'items' => [
                ['sub_name' => '給与水準', 'evidence' => '給与水準の原文抜粋です。'],
            ]],
        ];

        $html = $this->render($this->viewModel(['selfEvidenceByAxis' => $selfEvidenceByAxis]));

        $this->assertStringContainsString('○と判定した根拠', $html);
        $this->assertStringContainsString(config('brand_wheel.evidence_page_intro'), $html);

        $start = mb_strpos($html, '○と判定した根拠');
        $end = mb_strpos($html, '<h2>改善提案</h2>');
        $pageHtml = mb_substr($html, $start, $end - $start);

        // 軸の順序どおりに出現すること(活動的魅力→資産的魅力→経営スタイル→
        // 就業環境→金銭的便益)。
        $posWillActivity = mb_strpos($pageHtml, '活動的魅力');
        $posAsset = mb_strpos($pageHtml, '資産的魅力');
        $posPersonality = mb_strpos($pageHtml, '経営スタイル');
        $posRelationship = mb_strpos($pageHtml, '就業環境');
        $posFinancial = mb_strpos($pageHtml, '金銭的便益');
        $this->assertTrue($posWillActivity < $posAsset);
        $this->assertTrue($posAsset < $posPersonality);
        $this->assertTrue($posPersonality < $posRelationship);
        $this->assertTrue($posRelationship < $posFinancial);

        foreach (['パーパスの原文抜粋です。', '事業内容の原文抜粋です。', '知名度の原文抜粋です。', 'リーダーシップの原文抜粋です。', '同僚・先輩像の原文抜粋です。', '給与水準の原文抜粋です。'] as $quote) {
            $this->assertStringContainsString($quote, $html);
        }
    }

    /**
     * 依頼R: 引用はかぎ括弧で囲み、原文のまま(要約・改変なし)表示すること。
     */
    public function test_evidence_page_shows_the_quote_in_quotation_marks_verbatim(): void
    {
        $html = $this->render($this->viewModel([
            'selfEvidenceByAxis' => [
                ['axis_name' => '活動的魅力', 'items' => [
                    ['sub_name' => 'パーパス', 'evidence' => '弊社は地域社会への貢献を第一に考えています。'],
                ]],
            ],
        ]));

        $this->assertStringContainsString('「弊社は地域社会への貢献を第一に考えています。」', $html);
    }

    /**
     * 依頼R最重要: 引用に<script>や&が含まれていてもHTMLエスケープされ、
     * 生のタグとして解釈されないこと。
     */
    public function test_evidence_page_escapes_html_in_the_quote(): void
    {
        $html = $this->render($this->viewModel([
            'selfEvidenceByAxis' => [
                ['axis_name' => '活動的魅力', 'items' => [
                    ['sub_name' => 'パーパス', 'evidence' => '<script>alert(1)</script>採用 & 育成'],
                ]],
            ],
        ]));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('採用 &amp; 育成', $html);
    }

    // ------------------------------------------------------------------
    // 依頼AA(2026-08-27): 日本語でない引用への日本語訳併記。
    // ------------------------------------------------------------------

    /**
     * 訳が付いた引用は、原文の直下にラベル付きで表示され、
     * 冒頭の説明文が「(日本語訳を併記しています)」付きに差し替わること。
     */
    public function test_evidence_page_shows_the_translation_below_the_quote_and_switches_the_intro_text(): void
    {
        $html = $this->render($this->viewModel([
            'selfEvidenceByAxis' => [
                ['axis_name' => '活動的魅力', 'items' => [
                    ['sub_name' => 'パーパス', 'evidence' => 'We contribute to a better society.', 'evidence_translation' => 'より良い社会に貢献します。'],
                ]],
            ],
            'hasQuoteTranslations' => true,
        ]));

        $this->assertStringContainsString('「We contribute to a better society.」', $html);
        $this->assertStringContainsString((string) config('brand_wheel.quote_translation_label'), $html);
        $this->assertStringContainsString('より良い社会に貢献します。', $html);
        $this->assertStringContainsString((string) config('brand_wheel.evidence_page_intro_with_translation'), $html);
        $this->assertStringNotContainsString((string) config('brand_wheel.evidence_page_intro'), $html);
    }

    /**
     * 訳が1件も無いレポートでは、現行の説明文のままであること
     * (「併記しています」と書かない)。
     */
    public function test_evidence_page_keeps_the_original_intro_text_when_there_are_no_translations(): void
    {
        $html = $this->render($this->viewModel([
            'selfEvidenceByAxis' => [
                ['axis_name' => '活動的魅力', 'items' => [
                    ['sub_name' => 'パーパス', 'evidence' => '弊社は地域社会への貢献を第一に考えています。'],
                ]],
            ],
            'hasQuoteTranslations' => false,
        ]));

        $this->assertStringContainsString((string) config('brand_wheel.evidence_page_intro'), $html);
        $this->assertStringNotContainsString('日本語訳を併記しています', $html);
    }

    /**
     * 訳が無い項目(evidence_translationがnull)では、ラベル・訳が一切
     * 出ないこと(空のラベルだけが残る状態を作らない)。
     */
    public function test_evidence_page_omits_the_translation_label_when_there_is_no_translation(): void
    {
        $html = $this->render($this->viewModel([
            'selfEvidenceByAxis' => [
                ['axis_name' => '活動的魅力', 'items' => [
                    ['sub_name' => 'パーパス', 'evidence' => '弊社は地域社会への貢献を第一に考えています。', 'evidence_translation' => null],
                ]],
            ],
        ]));

        $this->assertStringNotContainsString((string) config('brand_wheel.quote_translation_label'), $html);
    }

    /**
     * 訳のテキストにも<script>や&が含まれる場合、HTMLエスケープされること。
     */
    public function test_evidence_page_escapes_html_in_the_translation(): void
    {
        $html = $this->render($this->viewModel([
            'selfEvidenceByAxis' => [
                ['axis_name' => '活動的魅力', 'items' => [
                    ['sub_name' => 'パーパス', 'evidence' => 'Our purpose', 'evidence_translation' => '<script>alert(1)</script>採用 & 育成'],
                ]],
            ],
            'hasQuoteTranslations' => true,
        ]));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('採用 &amp; 育成', $html);
    }

    /**
     * 改善提案ページの競合引用カードにも同じ体裁(ラベル付きの訳)が
     * 適用されること(依頼AA-1: 洗い出した全箇所に一貫して適用する)。
     */
    public function test_improvement_page_shows_the_translation_below_the_competitor_evidence(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'improvementFocus' => [
                'selected_group' => 'company_distance',
                'groups' => [
                    ['group' => 'company_appeal', 'label' => '会社の魅力', 'self_count' => 1, 'competitor_count' => 1, 'max_count' => 4],
                    ['group' => 'company_distance', 'label' => '会社との距離', 'self_count' => 0, 'competitor_count' => 1, 'max_count' => 4],
                    ['group' => 'job_appeal', 'label' => '仕事の魅力', 'self_count' => 0, 'competitor_count' => 0, 'max_count' => 0],
                ],
                'items' => [
                    ['type' => 'catch_up', 'axis_name' => '就業環境', 'sub_name' => '同僚・先輩像', 'definition' => 'テスト定義', 'recommendation' => 'テスト提案文', 'competitor_evidence' => 'Meet our diverse team.', 'competitor_evidence_translation' => '多様なチームをご紹介します。'],
                ],
                'lead_text' => 'テスト用の一文。',
            ],
            'hasQuoteTranslations' => true,
        ]));

        $this->assertStringContainsString('「Meet our diverse team.」', $html);
        $this->assertStringContainsString((string) config('brand_wheel.quote_translation_label'), $html);
        $this->assertStringContainsString('多様なチームをご紹介します。', $html);
    }

    /**
     * 依頼R: 「○と判定した根拠」ページが追加された分、既存ページの数は
     * 変わらず合計だけ+1されること(既存ページのレイアウトは変更していない)。
     */
    public function test_report_has_eight_pages_when_the_evidence_page_is_present(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'selfEvidenceByAxis' => [
                ['axis_name' => '活動的魅力', 'items' => [
                    ['sub_name' => 'パーパス', 'evidence' => 'パーパスの原文抜粋です。'],
                ]],
            ],
        ]));

        $this->assertSame(8, substr_count($html, 'class="page'));
    }

    // ------------------------------------------------------------------
    // 7. 改善提案。
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

    /**
     * 依頼X-1〜X-4(2026-08-26、レポート42): 自社が全領域で競合を上回り、
     * 候補項目(競合にあり自社に無い項目)が1件も無いとき、「0件挙げます」も
     * 「該当する項目はありませんでした」も出さず、状況を正しく説明する
     * lead_textを出す。ページ自体も消えない。
     *
     * 依頼AH-1(2026-08-28): items=[]になるのは①②とも0件(=自社の24項目
     * すべてが○)のときのみ(クラスdocblockの数学的根拠を参照)。旧フィクスチャ
     * (will_activityのみ4/4、他23項目は未言及=self/competitorとも未充足)は
     * 「自社は特定の1軸のみ強い」を意図していたが、他23項目が②(競合にも
     * 自社にも無い項目)の候補になってしまい、この試験の意図(自社が全領域で
     * 優位)を正しく表せていなかった。自社を24項目すべて○にし、真に
     * items=[]になる入力に修正する。
     */
    public function test_improvement_page_shows_the_no_candidate_message_and_does_not_disappear_when_self_leads_every_group(): void
    {
        $selfAxes = collect(config('brand_wheel.axes'))->map(fn (array $axis, string $axisKey) => [
            'key' => $axisKey,
            'group' => $axis['group'],
            'name' => $axis['name_ja'],
            'matched_count' => count($axis['sub_elements']),
            'max_count' => count($axis['sub_elements']),
            'matched_sub_elements' => collect($axis['sub_elements'])->map(fn (string $name, string $key) => ['key' => $key, 'name' => $name])->values()->all(),
            'label_only_sub_elements' => [],
        ])->values()->all();
        $competitorAxes = [
            // 競合がmatchした2件は、いずれも自社もmatchしている
            // (=候補(competitor_matched && !self_matched)が0件になる)。
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 2, 'max_count' => 4, 'matched_sub_elements' => [
                ['key' => 'purpose', 'name' => 'パーパス'], ['key' => 'business_expansion', 'name' => '展開事業・商品'],
            ], 'label_only_sub_elements' => []],
        ];

        $comparisonComposer = app(BrandWheelSubElementComparisonComposer::class);
        $subElementComparison = $comparisonComposer->compose($selfAxes, $competitorAxes);
        $groupTotals = $comparisonComposer->groupTotals($subElementComparison);
        $improvementFocus = app(BrandWheelImprovementFocusComposer::class)->compose($subElementComparison, []);

        $this->assertSame([], $improvementFocus['items']);

        $html = $this->render($this->comparisonViewModel([
            'brandWheelSelf' => $this->wheel(['axes' => $selfAxes]),
            'brandWheelCompetitor' => $this->wheel(['axes' => $competitorAxes]),
            'subElementComparison' => $subElementComparison,
            'groupTotals' => $groupTotals,
            'improvementFocus' => $improvementFocus,
            'improvementOnePoint' => $improvementFocus['lead_text'],
            'improvementReason' => null,
        ]));

        $this->assertStringContainsString((string) config('brand_wheel.improvement_focus_templates.no_candidate_self_ahead'), $html);
        $this->assertStringNotContainsString('0件挙げます', $html);
        $this->assertStringNotContainsString('該当する項目はありませんでした', $html);
        // ページ自体は消えない(棒グラフは残る)。
        $this->assertStringContainsString('改善提案', $html);
        $this->assertStringContainsString('会社の魅力', $html);
    }

    public function test_improvement_page_shows_the_selected_group_and_competitor_evidence_for_its_items(): void
    {
        $html = $this->render($this->comparisonViewModel());

        // fixtureはcompany_distance(会社との距離)の差が最大になるよう
        // 組んである(自社relationship=0件、競合relationship=2件)。
        $this->assertStringContainsString('会社との距離', $html);
        $this->assertStringContainsString('入社3年目の先輩が、日々どんな判断をしているかを紹介しています。', $html);
        $this->assertStringContainsString('部署をまたいだ相談が日常的に起きる、フラットな環境です。', $html);
        $this->assertStringContainsString('（現在、サイトからは読み取れませんでした）', $html);
    }

    /**
     * 依頼AI-4(2026-08-28): ②(breakout)を含む場合、新しいリード文
     * (「何を挙げているか」が分かる文言)がレンダリングされること。
     */
    public function test_improvement_page_shows_the_ai4_lead_text_when_items_include_a_breakout_card(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'improvementFocus' => [
                'selected_group' => 'company_distance',
                'groups' => [
                    ['group' => 'company_appeal', 'label' => '会社の魅力', 'self_count' => 1, 'competitor_count' => 1, 'max_count' => 4],
                    ['group' => 'company_distance', 'label' => '会社との距離', 'self_count' => 0, 'competitor_count' => 1, 'max_count' => 4],
                    ['group' => 'job_appeal', 'label' => '仕事の魅力', 'self_count' => 0, 'competitor_count' => 0, 'max_count' => 0],
                ],
                'items' => [
                    ['type' => 'catch_up', 'axis_name' => '就業環境', 'sub_name' => '精神的自由度', 'definition' => 'テスト定義', 'recommendation' => 'テスト提案文1', 'competitor_evidence' => '柔軟な働き方を推進しています。'],
                    ['type' => 'breakout', 'axis_name' => '活動的魅力', 'sub_name' => '社会貢献活動', 'definition' => 'テスト定義', 'recommendation' => 'テスト提案文2', 'competitor_evidence' => null],
                ],
                'lead_text' => sprintf((string) config('brand_wheel.improvement_focus_templates.items_include_breakout'), 2),
            ],
        ]));

        $this->assertStringContainsString('御社のサイトから読み取れなかった項目のうち、優先度の高いものを2件挙げます。', $html);
        $this->assertStringNotContainsString('内訳は各カードでご確認いただけます', $html);
    }

    /**
     * 2026-08-25(修正: 所見→提案): カードの本文は判定用の定義文
     * (sub_element_definitions)ではなく、行動を促す提案文
     * (config('brand_wheel.axes.*.sub_element_recommendations'))を表示する。
     * 競合ありのケースでも、提案文と競合の引用の両方が出る。
     */
    public function test_improvement_page_cards_show_the_recommendation_text_alongside_competitor_evidence(): void
    {
        $html = $this->render($this->comparisonViewModel());

        // fixture(colleagues/atmosphere)に対応する行動文(config原文)。
        $this->assertStringContainsString('実際に働いている人を、名前と経歴つきで紹介してください。', $html);
        $this->assertStringContainsString('普段のオフィスの様子や、チームの空気が伝わる描写を載せてください。', $html);
        // 競合の引用は従来どおり出る(行動文とは排他ではない)。
        $this->assertStringContainsString('入社3年目の先輩が、日々どんな判断をしているかを紹介しています。', $html);
        // 判定用の定義文(旧「同僚や先輩がどのような人物かについての具体的な記述。」)は
        // カードにはもう出ない。
        $this->assertStringNotContainsString('同僚や先輩がどのような人物かについての具体的な記述。', $html);
    }

    /**
     * 修正2の自社単独カード(competitor無し)でも、同様に定義文ではなく
     * 提案文を表示する。
     */
    public function test_improvement_page_self_only_cards_show_the_recommendation_text(): void
    {
        $html = $this->render($this->viewModel());

        $start = mb_strpos($html, '改善提案');
        $this->assertNotFalse($start);
        $pageHtml = mb_substr($html, $start);

        // viewModel()のfixtureはwill_activity(パーパス)のみ○、残り23項目が－。
        // company_distanceグループ(personality+relationship、8件とも－で最多)の
        // 上位3件(リーダーシップ/組織構造/会社の性格)の行動文が出る
        // (BrandWheelImprovementFocusComposer::composeSelfOnly()で実測確認済み)。
        $this->assertStringContainsString('経営者がどんな考えで会社を率いているかを、本人の言葉で載せてください。', $pageHtml);
        $this->assertStringNotContainsString('部門・チームの編成、階層、意思決定の通り方についての記述。', $pageHtml);
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

    /**
     * 依頼Q-2(2026-08-25): improvementFocusSelfOnly['items_source']が
     * 'ai'のとき、カードはitemsの内容(改善提案AI由来)をそのまま表示し、
     * 規則側の主張である「最も少なかったのは〜」の一文は出さないこと
     * (ReportViewModelBuilder::buildAiSelfOnlyFocusItems()が組み立てる形と
     * 同じ構造をここでは直接fixtureとして与える)。
     */
    public function test_improvement_page_shows_ai_sourced_self_only_cards_without_the_rule_sentence(): void
    {
        $ruleFocus = $this->viewModel()->improvementFocusSelfOnly;
        $aiFocus = $ruleFocus;
        $aiFocus['items_source'] = 'ai';
        $aiFocus['items'] = [
            ['axis_name' => '経営スタイル', 'sub_name' => '重視する価値', 'definition' => '組織として大切にしている価値観や行動指針についての記述。', 'recommendation' => 'AIが選んだ提案文です。', 'self_reason' => 'none'],
        ];

        $html = $this->render($this->viewModel(['improvementFocusSelfOnly' => $aiFocus]));

        $start = mb_strpos($html, '改善提案');
        $this->assertNotFalse($start);
        $pageHtml = mb_substr($html, $start);

        $this->assertStringContainsString('AIが選んだ提案文です。', $pageHtml);
        $this->assertStringNotContainsString('サイトの記述から読み取れた項目が最も少なかったのは', $pageHtml);
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
    // 8. サイトの改善をすれば課題が解決するとは限りません(最終ページ、
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
        $this->assertStringContainsString('href="https://leggenda-co.web-tools.biz/inquiry"', $html);
        $this->assertStringContainsString("お問い合わせの際は、本レポートの発行日（{$viewModel->generatedAtLabel}）と貴社名をお知らせください。", $html);
        // URLはボタン風のラベル付きリンク(href属性)としてのみ存在し、生の
        // 文字列としては表示しない ―― href自体の存在は966行目で確認済みのため、
        // ここではタグを除いた可視テキスト側にURLが露出していないことを確認する
        // (2026-08-24修正: 以前はraw $htmlに対して「含む」と「含まない」を
        // 同じ文字列で両方要求しており、両立不可能な自己矛盾アサーションだった)。
        $this->assertStringNotContainsString('leggenda-co.web-tools.biz', strip_tags($html));
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

    /**
     * 修正5(2026-08-25): 自社の合計matched件数が閾値未満のときの但し書き
     * (config('brand_wheel.self_low_content_notice'))。自社ページにのみ出て、
     * 競合ページには出ない。
     */
    public function test_self_analysis_page_shows_the_low_content_notice_when_present(): void
    {
        $notice = 'このページから読み取れた本文が少なかったため、確認できた項目数が少なくなっています。採用サイトのトップページなど、文章量の多いページをご指定いただくと、より詳しい診断が可能です。';

        $html = $this->render($this->comparisonViewModel(['selfLowContentNotice' => $notice]));

        $this->assertStringContainsString($notice, $html);
    }

    public function test_self_analysis_page_omits_the_low_content_notice_by_default(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringNotContainsString('文章量の多いページをご指定いただくと', $html);
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

    /**
     * 修正3(2026-08-25): 自社/競合いずれかの合計matched件数が閾値未満の
     * ときは、ReportViewModelBuilderがgroupTotals/comparisonOverviewを
     * 空配列にする(ReportViewModelBuilderTest側で検証済み)。ここでは、
     * その空配列がBlade側で「優劣判定・バッジを一切出さない」という
     * 表示結果に正しくつながることを確認する(競合自体は存在する
     * ケースであることに注意 ―― showCompetitorColumn=trueでも
     * groupTotalsが空ならバッジは出ない)。
     */
    public function test_comparison_page_omits_overview_summary_and_verdict_badge_when_group_totals_and_overview_are_empty_despite_a_competitor_existing(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'groupTotals' => [],
            'comparisonOverview' => [],
        ]));

        $this->assertStringNotContainsString('比較結果サマリー', $html);
        $this->assertStringNotContainsString('（自社優位）', $html);
        $this->assertStringNotContainsString('（競合優位）', $html);
        $this->assertStringNotContainsString('（同程度）', $html);
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
     * 廃止し、理由/中長期の差別化ポイントのブロックへ分解した(依頼者指定 ――
     * 結論だけでなく、なぜ・何を・いつまでにが追える構成にする)。
     * 2026-08-19: 「中長期的には：」の1行を、「中長期の差別化ポイント」という
     * 独立した見出し付きボックス(.diffbox)へ格上げした(依頼者指定 ――
     * 「差を埋める提案」と「差別化提案」を役割として明確に分けるため)。
     * 依頼Q-2(2026-08-25): 「具体的に追加すべき情報」の箇条書きは廃止した
     * (カードと内容が重複し、1ページに複数の推奨が並んで見えるため)。
     * improvementRecommendedContentsに値があっても出さないことを確認する。
     */
    public function test_improvement_page_shows_reason_and_differentiation_point_when_available(): void
    {
        $html = $this->render($this->comparisonViewModel());

        $this->assertStringContainsString('理由：', $html);
        $this->assertStringContainsString('就業環境は競合が2件読み取れているのに対し自社は0件で、候補者が働くイメージを持ちにくい状態です。', $html);
        $this->assertStringContainsString('中長期の差別化ポイント', $html);
        $this->assertStringNotContainsString('中長期的には：', $html);
        $this->assertStringContainsString('部署横断プロジェクトの事例をシリーズ化することも検討できます。', $html);
        // 依頼Q-2: comparisonViewModel()のfixtureはimprovementRecommendedContentsに
        // 値を持つが、「具体的に追加すべき情報」ブロック自体はもう出ない。
        $this->assertStringNotContainsString('具体的に追加すべき情報', $html);
        $this->assertStringNotContainsString('入社数年目の社員の1日の過ごし方', $html);
    }

    public function test_improvement_page_omits_each_ai_block_independently_when_unavailable(): void
    {
        $html = $this->render($this->comparisonViewModel([
            'improvementReason' => null,
            'improvementMidTermAction' => null,
        ]));

        $this->assertStringNotContainsString('理由：', $html);
        $this->assertStringNotContainsString('中長期の差別化ポイント', $html);
        // improvementFallbackNoteを明示的に渡していない(既定null)ため、
        // 代替文言も出ない ―― ReportViewModelBuilderが実際に計算する値を
        // このテストが代替しているわけではないことの確認。
        $this->assertStringNotContainsString((string) config('brand_wheel.improvement_focus_templates.no_reason_and_mid_term_fallback'), $html);
    }

    /**
     * 依頼AF-3(2026-08-27、依頼者承認済み): 「理由」「中長期の差別化
     * ポイント」が両方とも無い(AIの生成に失敗した場合等)とき、ページの
     * 下半分が白紙のままにならないよう代替文言を表示する。
     */
    public function test_improvement_page_shows_the_fallback_note_when_reason_and_mid_term_action_are_both_unavailable(): void
    {
        $fallbackText = (string) config('brand_wheel.improvement_focus_templates.no_reason_and_mid_term_fallback');

        $html = $this->render($this->comparisonViewModel([
            'improvementReason' => null,
            'improvementMidTermAction' => null,
            'improvementFallbackNote' => $fallbackText,
        ]));

        $this->assertStringNotContainsString('理由：', $html);
        $this->assertStringNotContainsString('中長期の差別化ポイント', $html);
        $this->assertStringContainsString($fallbackText, $html);
    }

    /**
     * mid_term_actionが実際にある場合は、代替文言と同時に出ない
     * (相互排他)。ReportViewModelBuilder側で既に排他的に計算しているが、
     * Blade側の@elseifが正しく機能していることも確認する。
     */
    public function test_improvement_page_never_shows_both_mid_term_action_and_the_fallback_note(): void
    {
        $fallbackText = (string) config('brand_wheel.improvement_focus_templates.no_reason_and_mid_term_fallback');

        $html = $this->render($this->comparisonViewModel([
            'improvementFallbackNote' => $fallbackText,
        ]));

        $this->assertStringContainsString('中長期の差別化ポイント', $html);
        $this->assertStringNotContainsString($fallbackText, $html);
    }
}
