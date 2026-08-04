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
 * 2026-08-04: docs/lead-report-layout/report-layout-draft.htmlを移植元に
 * 全8ページへ全面書き直し。このテストファイルもそれに合わせて全面書き直し。
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
            'selfBrandWheelEvidenceItems' => [
                ['axis_key' => 'will_activity', 'axis_name' => '活動的魅力', 'group' => 'company_appeal', 'sub_element_name' => 'パーパス', 'evidence' => '技術で社会の「あたり前」を支える。それが私たちの存在意義です。'],
            ],
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
     * status!=='success'のとき図も表も出さず、status_messageのみを出す。
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
        // お断り文言(メールと同趣旨)。
        $this->assertStringContainsString('グループインタビュー', $html);
        $this->assertStringContainsString('AIを使用', $html);
    }

    /**
     * 2026-08-04: 「採用ブランドの捉え方」前置きページ。分析結果に依存しない
     * 固定ページのため、status(success以外を含む)によらず常に出る。
     * 誤解を招きやすい一文(読み取れなかった=魅力が無い、ではない)は
     * 一字一句削らずに含めること。
     */
    public function test_includes_the_brand_wheel_framework_intro_page_with_the_required_caveat_verbatim(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('採用ブランドの捉え方', $html);
        $this->assertStringContainsString(
            '読み取れなかった項目は、その魅力が「無い」という意味ではありません。'
            .'サイトにそう書かれていない、というだけです。',
            $html,
        );
        // 固定説明図(base64埋め込み)。BrandWheelHexagonRendererを通していない
        // ―― 生成時刻に依存するデータは無いため、テストの固定資産(PNG)の
        // base64表現がそのまま出ていることだけを確認すれば足りる。
        $expectedBase64 = base64_encode((string) file_get_contents(resource_path('images/brand-wheel-framework.png')));
        $this->assertStringContainsString('data:image/png;base64,'.$expectedBase64, $html);
    }

    /**
     * 前置きページは分析結果に依存しない固定ページのため、
     * ブランド・ホイールがstatus!=='success'の場合でも出る。
     */
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
            'brandWheelComparison' => ['self_points' => [], 'competitor_points' => [], 'one_point' => null],
            'selfBrandWheelEvidenceItems' => [],
        ]));

        $this->assertStringContainsString('採用ブランドの捉え方', $html);
        $this->assertStringContainsString('読み取れなかった項目は、その魅力が「無い」という意味ではありません。', $html);
    }

    /**
     * 2026-08-04: PNGが生成できなかった場合(brandWheelRadarPng===null)、
     * 図を省略し、軸ごとの件数の表だけで成立させる(画像の失敗がレポート
     * 生成全体を止めてはいけない、既存メールと同じ方針)。
     */
    public function test_falls_back_to_the_axis_table_only_when_the_radar_png_is_missing(): void
    {
        $html = $this->render($this->viewModel(['brandWheelRadarPng' => null]));

        // レーダー画像に固有のスタイル(width: 76mm; height: 55mm;)が無いこと
        // (前置きページ・ロゴのimgタグと混同しないよう、レーダー画像固有の
        // スタイル文字列で判定する)。
        $this->assertStringNotContainsString('width: 76mm; height: 55mm;', $html);
        // 図が無くても表(件数)は変わらず出る。
        $this->assertStringContainsString('2<small> / 4件</small>', $html);
    }

    public function test_embeds_the_radar_png_as_a_base64_image_when_available(): void
    {
        $png = "\x89PNG\r\n\x1a\n".'dummy-bytes-for-test';

        $html = $this->render($this->viewModel(['brandWheelRadarPng' => $png]));

        $this->assertStringContainsString('<img src="data:image/png;base64,'.base64_encode($png).'"', $html);
    }

    /**
     * 2026-08-04: 自社ページの分析結果ページの6軸カード表(.axcell)が
     * 幅16.66%のみで右端の列がページ外へはみ出す不具合を、実PDF確認で発見。
     * .pcell/.reccellと同じくmm固定に修正した ―― CSSがパーセンテージ指定に
     * 戻された場合に検知できるよう、明示幅の存在を確認する。
     */
    public function test_axis_card_table_uses_explicit_mm_widths_not_percentages(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('.axcell { width: 44.16mm;', $html);
        $this->assertStringNotContainsString('.axcell { width: 16.66%;', $html);
    }

    /**
     * 2026-08-04: 他社ページ比較とのまとめページの左右カード(.pane)が
     * 幅48%のみで右側のカードがページ外へはみ出す不具合を、実PDF確認で発見。
     * (このページは以前「.pcellの修正で崩れが直っている」と誤って
     * コメントされていたが、実際には.paneが未修正のまま残っていた。)
     * mm固定に修正したことを、CSSが戻された場合に検知できるよう確認する。
     */
    public function test_comparison_pane_table_uses_explicit_mm_widths_not_percentages(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('.pane { border: 1px solid #1D2088; padding: 4mm; width: 129.5mm; }', $html);
        $this->assertStringNotContainsString('width: 48%', $html);
    }

    public function test_never_leaks_evidence_text_into_the_competitor_only_data(): void
    {
        // brandWheelCompetitorにはevidenceを持たせていない(競合の本文が
        // レポートに出ない設計)ため、競合側からevidence文字列が漏れないことを
        // 確認する。自社側のselfBrandWheelEvidenceItemsは意図的にPDFへ出す
        // ものなので、このテストの対象外(下のevidenceページ専用テストで別途検証)。
        $html = $this->render($this->viewModel([
            'competitorWebsiteUrl' => 'https://competitor.example.com',
            'brandWheelCompetitor' => [
                'status' => 'success',
                'status_message' => null,
                'analyzed_url' => 'https://competitor.example.com/careers',
                'axes' => [
                    ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_count' => 1, 'max_count' => 4, 'matched_sub_elements' => [['key' => 'purpose', 'name' => 'パーパス']]],
                ],
                'key_message' => null,
                'impression' => null,
                'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            ],
        ]));

        $this->assertStringNotContainsString('"evidence"', $html);
    }

    // ------------------------------------------------------------------
    // 「サイトから読み取れた記述」ページ(evidence一覧、2026-08-04新設)。
    // ------------------------------------------------------------------

    /**
     * 該当0件のときはページ自体を出さない(見出しと空の表だけが残る状態を
     * 作らない ―― 画面側で同じ失敗を一度している)。
     */
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

    /**
     * evidenceは要約・整形・省略記号での短縮を一切せず、そのまま出す
     * (原文との部分文字列照合を通ったものだけが残っている、というのが
     * このページの価値のため)。長い文でも省略記号(…)を挟まないことを含めて
     * 確認する。
     */
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

    /**
     * リード向け文書のため、evidence(サイトの原文からの抜粋)にも
     * forbidden_phrasesが含まれないことを確認する。evidenceはAIの自由記述
     * ではなくサイト原文からの抜粋だが、万一の混入がないことを最後の防波堤
     * として確認しておく。
     */
    public function test_evidence_page_does_not_contain_forbidden_phrases(): void
    {
        $html = $this->render($this->viewModel());

        // evidence一覧ページ本体のみを対象にする(他のページの正当な用法
        // ―― 例えば「4観点」ページの badge.warn文言等 ―― と混同しないため)。
        $start = mb_strpos($html, 'サイトから読み取れた記述');
        $this->assertNotFalse($start);
        $end = mb_strpos($html, '他社ページ比較とのまとめ', $start) ?: mb_strlen($html);
        $evidencePageHtml = mb_substr($html, $start, $end - $start);

        foreach ((array) config('brand_wheel.forbidden_phrases') as $phrase) {
            $this->assertStringNotContainsString($phrase, $evidencePageHtml);
        }
    }

    // ------------------------------------------------------------------
    // 軸カードの四角(dots)。max_countから動的に生成すること(2026-08-04)。
    // ------------------------------------------------------------------

    /**
     * 四角の数はmax_countから生成する(4を固定値で書かない)。max_count=5の
     * フィクスチャで、四角が5個・分母が5になることを確認する。
     */
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

        // このfixtureは軸を1件しか渡していないため、ページ全体でdotタグを
        // 数えれば、そのまま活動的魅力の軸カード1枚分の数になる。
        $this->assertSame(5, substr_count($html, '<span class="dot'));
        $this->assertSame(3, substr_count($html, 'class="dot on"'));
    }

    /**
     * matched_count===0でも四角(dots)自体は省略しない ―― 「壊れて出ていない」
     * のか「調べた結果0件」なのかを区別するための表示のため。
     */
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
        // 「読み取れた内容はありません」は使わない(内容が無い会社、と読める)。
        $this->assertStringNotContainsString('読み取れた内容はありません', $html);
        $this->assertStringContainsString('該当する記述は見つかりませんでした', $html);
    }

    /**
     * 4項目すべて該当したケースで、38mmの軸セル高さ指定が維持されている
     * こと。実際のはみ出し(下の帯への視覚的なめり込み)は文字列アサーション
     * では検出できないため、実PDFを生成して目視確認済み(4/4件の軸を含む
     * フィクスチャでも1ページに収まり、下の帯と重ならないことを確認した)。
     */
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
    // 「他社ページ比較とのまとめ」ページ。自社側が0件のとき片側だけ描画
    // される不具合(2026-08-04、実PDF確認で発見)。
    // ------------------------------------------------------------------

    /**
     * 自社側のself_pointsが0件・競合側はmatchedありの場合、【自社ページ】
     * の枠も常に出し、「該当する所見はありませんでした」と明示する
     * (片側だけの表示は「比較」になっていないため)。
     */
    public function test_shows_the_self_pane_with_a_fallback_message_when_self_points_are_empty(): void
    {
        $html = $this->render($this->viewModel([
            'competitorWebsiteUrl' => 'https://competitor.example.com',
            'brandWheelComparison' => [
                'self_points' => [],
                'competitor_points' => ['全体的に情報が充足しています。'],
                'one_point' => null,
            ],
        ]));

        $this->assertStringContainsString('【自社ページ】', $html);
        $this->assertStringContainsString('【他社ページ】', $html);
        $this->assertStringContainsString('該当する所見はありませんでした', $html);
        $this->assertStringContainsString('全体的に情報が充足しています。', $html);
    }

    /**
     * 競合サイト自体が存在しない(自社単独レポート)場合は、
     * 【他社ページ】の枠自体を出さない ―― 「該当なし」の枠を出すと、
     * 比較を試みて何も無かったかのように誤読されるため。
     */
    public function test_does_not_show_the_competitor_pane_when_there_is_no_competitor_website(): void
    {
        $html = $this->render($this->viewModel([
            'competitorWebsiteUrl' => null,
            'brandWheelComparison' => [
                'self_points' => ['活動的魅力が最も内容として充足しています。'],
                'competitor_points' => [],
                'one_point' => null,
            ],
        ]));

        $this->assertStringContainsString('【自社ページ】', $html);
        $this->assertStringNotContainsString('【他社ページ】', $html);
    }

    /**
     * 競合サイトが存在し、かつ競合側のcompetitor_pointsも0件の場合
     * (競合も「該当する所見なし」)は、両方の枠を出し、両方とも
     * フォールバック文言を表示する。self_points/competitor_pointsが
     * 両方とも空だとhasComparisonContent自体がfalseになり単一行の
     * status_message表示に切り替わってしまうため、one_pointだけは
     * 存在させてこの分岐(2枠表示)に入るようにする。
     */
    public function test_shows_both_panes_with_fallback_messages_when_both_sides_have_no_points(): void
    {
        $html = $this->render($this->viewModel([
            'competitorWebsiteUrl' => 'https://competitor.example.com',
            'brandWheelComparison' => [
                'self_points' => [],
                'competitor_points' => [],
                'one_point' => ['key' => 'insufficient_content', 'text' => '6つの項目のうち複数で、サイトの記述から内容を読み取ることができませんでした。'],
            ],
        ]));

        $this->assertStringContainsString('【自社ページ】', $html);
        $this->assertStringContainsString('【他社ページ】', $html);
        $this->assertSame(2, substr_count($html, '該当する所見はありませんでした'));
    }
}
