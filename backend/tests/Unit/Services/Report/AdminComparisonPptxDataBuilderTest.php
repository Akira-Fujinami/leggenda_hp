<?php

namespace Tests\Unit\Services\Report;

use App\Services\BrandWheel\BrandWheelMultiSiteComparisonComposer;
use App\Services\Report\AdminComparisonPptxDataBuilder;
use App\Support\Report\MultiSiteReportViewModel;
use Tests\TestCase;

/**
 * 依頼BM(2026-09-09): 「競合が伝えていて自社が伝えていない項目」一覧
 * (rows/quote、件数に依存し0件だと下2/3が白紙になる不具合が実データで
 * 確認された)から、6領域×各社のマトリクス(axes、分母4固定で必ず埋まる)
 * へ作り直した。config('brand_wheel.axes')の実際の6領域名(name_ja)・
 * sub_elements件数をそのまま使ってcomparisonTableを組み立てる
 * (領域名をテスト側で適当な文字列にすると、実装側のaxis_name一致による
 * 集計を素通りしてしまい意味のあるテストにならないため)。
 */
class AdminComparisonPptxDataBuilderTest extends TestCase
{
    /**
     * config('brand_wheel.axes')の6領域(name_ja)×4下位要素=24項目分の
     * comparisonTableを、$selfMatchedByAxis/$competitorMatchedByAxisで
     * 指定した件数だけmatched=trueにして組み立てる。
     *
     * @param  array<string, int>  $selfMatchedByAxis  領域名(name_ja) => 自社のmatched件数(0〜4)
     * @param  list<array<string, int>>  $competitorMatchedByAxisList  競合ごとの[領域名 => matched件数]
     */
    private function comparisonTable(array $selfMatchedByAxis, array $competitorMatchedByAxisList): array
    {
        $table = [];
        foreach ((array) config('brand_wheel.axes') as $axisConfig) {
            $nameJa = $axisConfig['name_ja'];
            $subKeys = array_keys($axisConfig['sub_elements']);
            $selfMatchedCount = $selfMatchedByAxis[$nameJa] ?? 0;

            foreach ($subKeys as $i => $subKey) {
                $table[] = [
                    'axis_name' => $nameJa,
                    'group' => $axisConfig['group'],
                    'sub_name' => $axisConfig['sub_elements'][$subKey],
                    'self_matched' => $i < $selfMatchedCount,
                    'competitor_matched' => array_map(
                        fn (array $byAxis) => $i < ($byAxis[$nameJa] ?? 0),
                        $competitorMatchedByAxisList,
                    ),
                ];
            }
        }

        return $table;
    }

    private function viewModel(array $overrides = []): MultiSiteReportViewModel
    {
        $defaults = [
            'selfCompanyDisplayName' => 'テスト株式会社',
            'generatedAtLabel' => '2026年9月8日',
            'selfWebsiteUrl' => 'https://example.com',
            'competitors' => [
                ['name' => '競合A社', 'url' => 'https://a.example.com'],
                ['name' => '競合B社', 'url' => 'https://b.example.com'],
            ],
            'competitorCount' => 2,
            'majorityThreshold' => 2,
            'selfReadable' => true,
            'selfTotalMatched' => 10,
            'selfTotalMax' => 24,
            'brandWheelRadarPngCombined' => null,
            'missingFromSelf' => [],
            'selfStrengths' => [],
            'comparisonTable' => $this->comparisonTable([], []),
            'selfEvidenceByAxis' => [],
            'hasQuoteTranslations' => false,
        ];

        return new MultiSiteReportViewModel(...array_merge($defaults, $overrides));
    }

    public function test_self_company_is_first_and_marked_is_self(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        $this->assertSame('テスト株式会社', $data['self_company_name']);
        $this->assertSame(['name' => 'テスト株式会社', 'matched' => 10, 'total' => 24, 'is_self' => true, 'material_sufficient' => true], $data['companies'][0]);
    }

    /**
     * 依頼CD-2: 自社のブランド・ホイール判定が空(selfTotalMax=0、
     * selfTotalMatched=0)のとき、旧実装は自社の'total'にselfTotalMax
     * (=0)をそのまま使っていたため、比較スライドの総合表示が「0/0」に
     * なる一方、下段のマトリクス(buildAxisMatrix())の分母は必ず
     * config由来の4×6=24になっており、同じスライド内で「総合0/0」
     * 「表側0/4が6行」という食い違いが生じていた(依頼者報告の不具合①②)。
     * 自社の'total'は、競合と同じ$totalItems(comparisonTableの件数、
     * 常に24)を使うべきで、selfTotalMaxの値に一切左右されないこと。
     */
    public function test_self_company_total_always_matches_the_competitor_total_even_when_self_data_is_empty(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel([
            'selfReadable' => false,
            'selfTotalMatched' => 0,
            'selfTotalMax' => 0,
        ]));

        $this->assertSame(24, $data['companies'][0]['total']);
        $this->assertSame(24, $data['companies'][1]['total']);
        $this->assertSame($data['companies'][0]['total'], $data['companies'][1]['total']);

        // マトリクス側の分母合計(4×6=24)と、総合表示の分母が必ず一致する
        // こと(構造そのものを検証する ―― CD-1の原因を直せば自然に
        // 解消する場合でも、食い違いが起きうる構造自体を潰すこと、
        // 依頼者指定)。
        $matrixDenominatorSum = array_sum(array_column($data['axes'], 'denominator'));
        $this->assertSame($matrixDenominatorSum, $data['companies'][0]['total']);
    }

    /**
     * 依頼CD-3: self_readable(MultiSiteReportViewModel::selfReadable、
     * status==='success' && axes!==[]で既に算出済みの唯一の情報源)を
     * そのまま通すこと ―― ここで新しい判定を作らない。
     */
    public function test_self_readable_is_passed_through_from_the_view_model(): void
    {
        $readable = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['selfReadable' => true]));
        $this->assertTrue($readable['self_readable']);

        $unreadable = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['selfReadable' => false]));
        $this->assertFalse($unreadable['self_readable']);
    }

    public function test_competitor_matched_counts_are_summed_from_the_comparison_table_by_index(): void
    {
        $table = $this->comparisonTable([], [
            ['活動的魅力' => 2, '資産的魅力' => 1],
            ['活動的魅力' => 4],
        ]);
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['comparisonTable' => $table]));

        $this->assertSame(['name' => '競合A社', 'matched' => 3, 'total' => 24, 'is_self' => false, 'material_sufficient' => true], $data['companies'][1]);
        $this->assertSame(['name' => '競合B社', 'matched' => 4, 'total' => 24, 'is_self' => false, 'material_sufficient' => true], $data['companies'][2]);
    }

    /**
     * 依頼BM-1: 領域の並び・名前はconfig('brand_wheel.axes')の順・name_ja
     * をそのまま使うこと(直書きしない)。
     */
    public function test_axes_follow_config_order_and_names(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        $expectedNames = array_column((array) config('brand_wheel.axes'), 'name_ja');
        $this->assertSame($expectedNames, array_column($data['axes'], 'name'));
    }

    /**
     * 依頼BM-1: 分母は該当領域のsub_elements件数(=4)から出すこと
     * (24÷6を直書きしない)。
     */
    public function test_each_axis_denominator_comes_from_the_configured_sub_elements_count(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        foreach ($data['axes'] as $axis) {
            $this->assertSame(4, $axis['denominator']);
        }
    }

    public function test_axis_captions_come_from_config(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        $captions = config('admin_comparison_pptx.axis_captions');
        $axesConfig = (array) config('brand_wheel.axes');
        $keys = array_keys($axesConfig);

        foreach ($data['axes'] as $i => $axis) {
            $this->assertSame($captions[$keys[$i]] ?? null, $axis['caption']);
        }
    }

    /**
     * 依頼BM-2: 自社が「その領域の競合の最高値を下回る」場合にのみ
     * self_gap=trueにすること。同値は網かけしないこと。
     */
    public function test_self_gap_is_true_only_when_self_is_strictly_below_the_competitor_max(): void
    {
        $table = $this->comparisonTable(
            ['活動的魅力' => 2, '資産的魅力' => 3, '経営スタイル' => 4],
            [
                ['活動的魅力' => 3, '資産的魅力' => 3, '経営スタイル' => 2],
                ['活動的魅力' => 1, '資産的魅力' => 1, '経営スタイル' => 1],
            ],
        );
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['comparisonTable' => $table]));

        $byName = collect($data['axes'])->keyBy('name');
        // 自社2 < 競合最高3 → 網かけ。
        $this->assertTrue($byName['活動的魅力']['self_gap']);
        // 自社3 = 競合最高3(同値) → 網かけしない。
        $this->assertFalse($byName['資産的魅力']['self_gap']);
        // 自社4 > 競合最高2 → 網かけしない。
        $this->assertFalse($byName['経営スタイル']['self_gap']);
    }

    public function test_source_note_includes_the_generated_at_label(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['generatedAtLabel' => '2026年1月1日']));

        $this->assertStringContainsString('2026年1月1日', $data['source_note']);
    }

    public function test_page_number_is_null(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        $this->assertNull($data['page_number']);
    }

    /**
     * @return array{axis_name: string, sub_name: string, definition: string, recommendation: string, competitor_matched_count: int, representative_company_name: ?string, quote: ?string, quote_translation: ?string}
     */
    private function missingFromSelfItem(string $axisName, string $subName, int $count, ?string $quote = 'DUMMY QUOTE'): array
    {
        return [
            'axis_name' => $axisName,
            'sub_name' => $subName,
            'definition' => 'DUMMY DEFINITION',
            'recommendation' => 'DUMMY RECOMMENDATION',
            'competitor_matched_count' => $count,
            'representative_company_name' => $quote !== null ? 'DUMMY COMPANY' : null,
            'quote' => $quote,
            'quote_translation' => $quote !== null ? 'DUMMY TRANSLATION' : null,
        ];
    }

    /**
     * 依頼BO-1: axis_name/sub_nameだけを読み、quote/quote_translation/
     * representative_company_name/definition/recommendationは一切
     * 出力に含めないこと(依頼BM-4で止めた引用の復活を防ぐ)。
     */
    public function test_missing_items_reads_only_axis_and_sub_name_never_quotes(): void
    {
        $missingFromSelf = [
            $this->missingFromSelfItem('金銭的便益', '福利厚生', 3),
            $this->missingFromSelfItem('活動的魅力', '社員インタビュー', 1),
        ];
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $this->assertSame(['金銭的便益', '活動的魅力'], array_column($data['missing_items']['items'], 'axis_name'));
        $this->assertSame(['福利厚生', '社員インタビュー'], array_column($data['missing_items']['items'], 'sub_name'));
        $this->assertSame(0, $data['missing_items']['others_count']);

        foreach ($data['missing_items']['items'] as $item) {
            $this->assertArrayNotHasKey('quote', $item);
            $this->assertArrayNotHasKey('quote_translation', $item);
            $this->assertArrayNotHasKey('representative_company_name', $item);
            $this->assertArrayNotHasKey('definition', $item);
            $this->assertArrayNotHasKey('recommendation', $item);
        }

        $encoded = json_encode($data['missing_items']);
        $this->assertStringNotContainsString('DUMMY QUOTE', (string) $encoded);
        $this->assertStringNotContainsString('DUMMY TRANSLATION', (string) $encoded);
        $this->assertStringNotContainsString('DUMMY COMPANY', (string) $encoded);
        // missingFromSelf側のdefinition/recommendationフィールド
        // (DUMMY DEFINITION/DUMMY RECOMMENDATION)も読んでいないこと ――
        // impactはconfig('brand_wheel.axes')から独立に引いたもの。
        $this->assertStringNotContainsString('DUMMY DEFINITION', (string) $encoded);
        $this->assertStringNotContainsString('DUMMY RECOMMENDATION', (string) $encoded);
    }

    /**
     * 依頼CB-2(2026-09-24): 各行に、領域名・「伝わっていないと何が起きるか」
     * の一文(config('brand_wheel.axes.*.sub_element_definitions')ベース)・
     * 候補者調査の対応(config('brand_wheel_candidate_survey'))が追加されて
     * いること。
     */
    public function test_missing_items_are_enriched_with_region_impact_and_candidate_survey(): void
    {
        $missingFromSelf = [$this->missingFromSelfItem('金銭的便益', '福利厚生', 3)];
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $item = $data['missing_items']['items'][0];
        $this->assertSame('仕事の魅力', $item['region']);
        $definition = config('brand_wheel.axes.financial_benefit.sub_element_definitions.benefits');
        $this->assertSame(sprintf((string) config('admin_comparison_pptx.missing_item_impact_template'), $definition), $item['impact']);
        $this->assertSame('福利厚生', $item['candidate_survey']['item']);
        $this->assertSame(12.4, $item['candidate_survey']['percentage']);
        $this->assertSame('unconfirmed', $item['candidate_survey']['self_state']);
    }

    /**
     * 依頼CF-5①(2026-09-29): impact(定義の行)とcandidate_survey(候補者調査の
     * 行)が、同じ「自社サイトでは確認できない」旨を重複して言わないこと
     * (実物のPPTXで3件すべて同義反復になっていた不具合の再発防止)。
     * 候補者調査の行(割合の数字がある方)にこの文言を残す判断とした
     * ―― impact側にはもう含まれないこと。
     */
    public function test_missing_item_impact_no_longer_duplicates_the_not_confirmed_phrase(): void
    {
        $missingFromSelf = [$this->missingFromSelfItem('金銭的便益', '福利厚生', 3)];
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $item = $data['missing_items']['items'][0];
        $definition = (string) config('brand_wheel.axes.financial_benefit.sub_element_definitions.benefits');

        $this->assertSame($definition, $item['impact'], 'impactは定義文そのものであり、余計な接尾辞を付け足さないこと');
        $this->assertStringNotContainsString('確認でき', $item['impact']);

        // 候補者調査の行には引き続き「確認できません」の趣旨が残ること
        // (依頼者の判断: 割合の数字がある方を残す)。
        $surveyText = sprintf(
            (string) config('admin_comparison_pptx.missing_item_survey_template'),
            $item['candidate_survey']['item'],
            $item['candidate_survey']['percentage'],
        );
        $this->assertStringContainsString('確認でき', $surveyText);
    }

    /**
     * 依頼CB-2必須: 対応表(config('brand_wheel_candidate_survey'))で
     * 「該当なし」の項目は、候補者調査の項目名・割合ともnullにすること
     * (数字を捏造しない)。
     */
    public function test_missing_items_candidate_survey_is_null_when_the_mapping_has_no_match(): void
    {
        // 優越感(emotional_benefit.superiority)は対応表でsurvey_item/
        // percentageともnull(別添の素案どおり)。
        $missingFromSelf = [$this->missingFromSelfItem('情緒的便益', '優越感', 3)];
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $this->assertSame(['item' => null, 'percentage' => null, 'self_state' => null], $data['missing_items']['items'][0]['candidate_survey']);
    }

    /**
     * 依頼CB-2必須: 出典(config('brand_wheel_candidate_survey.source_note'))
     * を必ず出力に含めること。
     */
    public function test_candidate_survey_source_note_comes_from_config(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        $this->assertSame(config('brand_wheel_candidate_survey.source_note'), $data['candidate_survey_source_note']);
        $this->assertNotSame('', trim($data['candidate_survey_source_note']));
    }

    /**
     * 依頼CB-3: 「足りないもの」に対応するサイトの導線名(対応表の
     * site_flow_name)を、重複を除いて返すこと。該当なし(null)の項目は
     * 含めないこと(存在しない導線名を推奨しない)。
     */
    public function test_recommended_site_flow_names_are_deduplicated_and_exclude_unmapped_items(): void
    {
        $missingFromSelf = [
            // 同僚・先輩像(colleagues)とpride(誇りに思える)はどちらも
            // site_flow_name「社員を知る」―― 重複除去を確認する。
            $this->missingFromSelfItem('就業環境', '同僚・先輩像', 3),
            $this->missingFromSelfItem('情緒的便益', '誇りに思える', 2),
            // 優越感はsite_flow_nameもnull(該当なし) ―― 含めない。
            $this->missingFromSelfItem('情緒的便益', '優越感', 1),
        ];

        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $this->assertSame(['社員を知る'], $data['recommended_site_flow_names']);
    }

    /**
     * 依頼BO-1: 並び順(=言及している競合の社数が多い順)は
     * missingFromSelf(BrandWheelMultiSiteComparisonComposer側で既に
     * 件数降順)の並びをそのまま維持すること。
     */
    public function test_missing_items_keeps_the_order_of_missing_from_self(): void
    {
        $missingFromSelf = [
            $this->missingFromSelfItem('金銭的便益', '福利厚生', 3),
            $this->missingFromSelfItem('活動的魅力', '社員インタビュー', 2),
            $this->missingFromSelfItem('経営スタイル', 'リーダーシップ', 1),
        ];
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $this->assertSame(
            ['福利厚生', '社員インタビュー', 'リーダーシップ'],
            array_column($data['missing_items']['items'], 'sub_name'),
        );
    }

    /**
     * 依頼BO-1: missing_items_max_countを超える件数のときは、末尾を
     * 「ほかN件」1件に畳み、上限を超えた項目名を出力に含めないこと。
     *
     * 依頼CF-4(2026-09-29): 超過時に実際に表示する件数は
     * missing_items_overflow_display_count(config、$maxCount-1という
     * 暗黙の計算式ではなく明示的な別のconfigキー)から読むこと ――
     * この2つの数値が食い違わない(=設定値が実際の挙動を表す)ことの
     * 確認を兼ねる。
     */
    public function test_missing_items_folds_the_tail_into_others_count_beyond_the_max(): void
    {
        $maxCount = (int) config('admin_comparison_pptx.missing_items_max_count');
        $overflowDisplayCount = (int) config('admin_comparison_pptx.missing_items_overflow_display_count');
        $missingFromSelf = [];
        for ($i = 0; $i < $maxCount + 3; $i++) {
            $missingFromSelf[] = $this->missingFromSelfItem('活動的魅力', "項目{$i}", $maxCount + 3 - $i);
        }

        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $this->assertCount($overflowDisplayCount, $data['missing_items']['items']);
        $this->assertSame(($maxCount + 3) - $overflowDisplayCount, $data['missing_items']['others_count']);
        $this->assertSame('項目0', $data['missing_items']['items'][0]['sub_name']);
        $this->assertSame('項目'.($overflowDisplayCount - 1), $data['missing_items']['items'][$overflowDisplayCount - 1]['sub_name'] ?? null);
    }

    /**
     * 依頼BO-1: 上限ちょうどの件数のときは、末尾を「ほかN件」に畳まず
     * 全件そのまま出力すること(境界値)。
     */
    public function test_missing_items_shows_all_items_when_exactly_at_the_max(): void
    {
        $maxCount = (int) config('admin_comparison_pptx.missing_items_max_count');
        $missingFromSelf = [];
        for ($i = 0; $i < $maxCount; $i++) {
            $missingFromSelf[] = $this->missingFromSelfItem('活動的魅力', "項目{$i}", $maxCount - $i);
        }

        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $this->assertCount($maxCount, $data['missing_items']['items']);
        $this->assertSame(0, $data['missing_items']['others_count']);
    }

    /**
     * 依頼CF-4必須: 該当が0件・1件・上限ちょうど・上限超過のいずれでも、
     * 表示件数+「ほかN件」が該当総数と一致すること(件数が食い違わない)。
     */
    public function test_missing_items_displayed_count_plus_others_count_always_equals_the_total(): void
    {
        $maxCount = (int) config('admin_comparison_pptx.missing_items_max_count');

        foreach ([0, 1, $maxCount, $maxCount + 5] as $total) {
            $missingFromSelf = [];
            for ($i = 0; $i < $total; $i++) {
                $missingFromSelf[] = $this->missingFromSelfItem('活動的魅力', "項目{$i}", $total - $i);
            }

            $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missingFromSelf]));

            $displayed = count($data['missing_items']['items']);
            $others = $data['missing_items']['others_count'];
            $this->assertSame($total, $displayed + $others, "該当{$total}件のとき、表示件数({$displayed})+ほか件数({$others})が総数と一致しないこと");
        }
    }

    /**
     * 依頼BQ-1(2026-09-11): 見出しは「競合N社中M社以上」を埋め込んだ
     * sprintfテンプレートになった。Mは
     * BrandWheelMultiSiteComparisonComposer::majorityThreshold()から
     * 算出する(計算式をテスト側に複製しない)。デフォルトのviewModel()は
     * 競合2社のため、majorityThreshold(2)=2(2社とも、の意味)。
     */
    private function expectedMissingItemsHeading(int $competitorCount): string
    {
        $majorityThreshold = (new BrandWheelMultiSiteComparisonComposer)->majorityThreshold($competitorCount);

        return sprintf(config('admin_comparison_pptx.missing_items_heading'), $competitorCount, $majorityThreshold);
    }

    /**
     * 依頼BO-1: 自社が全24項目を満たす(missingFromSelfが空)とき、
     * itemsは空になるが、見出し・0件時の文言はconfigの値のまま
     * 出力されること(セクションごと消して余白を残さない、依頼者指定)。
     */
    public function test_missing_items_is_empty_with_heading_and_empty_text_when_nothing_is_missing(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => []]));

        $this->assertSame([], $data['missing_items']['items']);
        $this->assertSame(0, $data['missing_items']['others_count']);
        $this->assertSame($this->expectedMissingItemsHeading(2), $data['missing_items']['heading']);
        $this->assertSame(config('admin_comparison_pptx.missing_items_empty_text'), $data['missing_items']['empty_text']);
        $this->assertNotSame('', trim($data['missing_items']['empty_text']));
    }

    public function test_missing_items_heading_is_present_even_when_items_exist(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel([
            'missingFromSelf' => [$this->missingFromSelfItem('活動的魅力', '福利厚生', 2)],
        ]));

        $this->assertSame($this->expectedMissingItemsHeading(2), $data['missing_items']['heading']);
    }

    /**
     * 依頼BQ-1: 過半数の実際の人数(競合N社中M社以上)が、競合社数に応じて
     * 正しく変わること(3社なら2社、5社なら3社)。
     */
    public function test_missing_items_heading_reflects_the_majority_count_for_the_actual_competitor_count(): void
    {
        $data3 = (new AdminComparisonPptxDataBuilder)->build($this->viewModel([
            'competitors' => [
                ['name' => '競合A社', 'url' => 'https://a.example.com'],
                ['name' => '競合B社', 'url' => 'https://b.example.com'],
                ['name' => '競合C社', 'url' => 'https://c.example.com'],
            ],
            'missingFromSelf' => [],
        ]));
        $this->assertSame('競合3社中2社以上が伝えていて、自社サイトでは確認できなかった項目', $data3['missing_items']['heading']);

        $data5 = (new AdminComparisonPptxDataBuilder)->build($this->viewModel([
            'competitors' => [
                ['name' => '競合A社', 'url' => 'https://a.example.com'],
                ['name' => '競合B社', 'url' => 'https://b.example.com'],
                ['name' => '競合C社', 'url' => 'https://c.example.com'],
                ['name' => '競合D社', 'url' => 'https://d.example.com'],
                ['name' => '競合E社', 'url' => 'https://e.example.com'],
            ],
            'missingFromSelf' => [],
        ]));
        $this->assertSame('競合5社中3社以上が伝えていて、自社サイトでは確認できなかった項目', $data5['missing_items']['heading']);
    }

    // ------------------------------------------------------------------
    // 依頼CL-2(2026-10-05): 「求職者が知りたい情報と、自社サイト」(survey_comparison)。
    // ------------------------------------------------------------------

    /**
     * 項目キー("axisKey.subKey")で○を指定してcomparisonTableを組み立てる。
     *
     * @param  list<string>  $selfKeys
     * @param  list<list<string>>  $competitorKeysList  競合ごとの○の項目キー
     */
    private function tableByKeys(array $selfKeys, array $competitorKeysList): array
    {
        $table = [];
        foreach ((array) config('brand_wheel.axes') as $axisKey => $axisConfig) {
            foreach ($axisConfig['sub_elements'] as $subKey => $subName) {
                $path = "{$axisKey}.{$subKey}";
                $table[] = [
                    'axis_name' => $axisConfig['name_ja'],
                    'group' => $axisConfig['group'],
                    'sub_name' => $subName,
                    'self_matched' => in_array($path, $selfKeys, true),
                    'competitor_matched' => array_map(fn (array $keys) => in_array($path, $keys, true), $competitorKeysList),
                ];
            }
        }

        return $table;
    }

    private function surveyRow(array $data, string $optionKey): array
    {
        foreach ($data['survey_comparison']['rows'] as $row) {
            if ($row['key'] === $optionKey) {
                return $row;
            }
        }
        $this->fail("調査の選択肢 {$optionKey} の行が無い");
    }

    public function test_survey_comparison_lists_all_fourteen_options_in_descending_percentage_order(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());
        $rows = $data['survey_comparison']['rows'];

        $this->assertCount(14, $rows);
        $this->assertSame(range(1, 14), array_column($rows, 'rank'));
        $percentages = array_column($rows, 'percentage');
        $sorted = $percentages;
        rsort($sorted);
        $this->assertSame($sorted, $percentages);
        $this->assertSame('希望するポジションの仕事・業務内容', $rows[0]['name']);
    }

    /**
     * 調査の選択肢1つに、24項目のうち複数が対応する(社員インタビュー=3項目)。
     * すべて○=confirmed、一部=partial、すべて×=unconfirmed、対応する項目が無い=not_applicable。
     */
    public function test_survey_comparison_decides_the_self_state_from_all_mapped_items(): void
    {
        $viewModel = $this->viewModel([
            'comparisonTable' => $this->tableByKeys(
                // 社員インタビュー(3項目)は1つだけ○ → partial。給与体系(1項目)は○ → confirmed。福利厚生(1項目)は× → unconfirmed。
                ['relationship.colleagues', 'financial_benefit.salary_level'],
                [[], []],
            ),
        ]);
        $data = (new AdminComparisonPptxDataBuilder)->build($viewModel);

        $partial = $this->surveyRow($data, 'employee_interview');
        $this->assertSame('partial', $partial['self_state']);
        $this->assertSame(3, $partial['mapped_count']);
        $this->assertSame(1, $partial['self_matched_count']);

        $this->assertSame('confirmed', $this->surveyRow($data, 'salary_evaluation')['self_state']);
        $this->assertSame('unconfirmed', $this->surveyRow($data, 'benefits')['self_state']);

        $all = (new AdminComparisonPptxDataBuilder)->build($this->viewModel([
            'comparisonTable' => $this->tableByKeys(['relationship.colleagues', 'emotional_benefit.pride', 'emotional_benefit.talkable'], [[], []]),
        ]));
        $this->assertSame('confirmed', $this->surveyRow($all, 'employee_interview')['self_state']);
    }

    /**
     * 対応する24項目が無い選択肢(研修制度)は「判定の対象外」。競合の数も出さない。
     */
    public function test_survey_comparison_marks_options_without_mapped_items_as_not_applicable(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());
        $row = $this->surveyRow($data, 'training');

        $this->assertSame('not_applicable', $row['self_state']);
        $this->assertSame(0, $row['mapped_count']);
        $this->assertNull($row['competitor_count']);
    }

    /**
     * 競合の「掲載」は、対応する項目のうち1つでも○の会社を数える(自社の判定とは数え方が違う)。
     */
    public function test_survey_comparison_counts_a_competitor_when_any_mapped_item_is_matched(): void
    {
        $viewModel = $this->viewModel([
            'competitors' => [
                ['name' => '競合A社', 'url' => 'https://a.example.com'],
                ['name' => '競合B社', 'url' => 'https://b.example.com'],
                ['name' => '競合C社', 'url' => 'https://c.example.com'],
            ],
            'competitorCount' => 3,
            'comparisonTable' => $this->tableByKeys([], [
                ['emotional_benefit.pride'],                                 // 社員インタビューの3項目のうち1つ → 掲載
                [],                                                          // 掲載なし
                ['relationship.colleagues', 'emotional_benefit.talkable'],   // 2つ → 1社として数える
            ]),
        ]);
        $data = (new AdminComparisonPptxDataBuilder)->build($viewModel);

        $this->assertSame(3, $data['survey_comparison']['competitor_total']);
        $this->assertSame(2, $this->surveyRow($data, 'employee_interview')['competitor_count']);
        $this->assertSame(0, $this->surveyRow($data, 'benefits')['competitor_count']);
    }

    /**
     * 材料不足の競合は、判定が信頼できないため分子にも分母にも含めない。
     */
    public function test_survey_comparison_excludes_competitors_with_insufficient_material_from_numerator_and_denominator(): void
    {
        $viewModel = $this->viewModel([
            'competitors' => [
                ['name' => '競合A社', 'url' => 'https://a.example.com'],
                ['name' => '競合B社', 'url' => 'https://b.example.com'],
            ],
            'competitorCount' => 2,
            'competitorsMaterialSufficient' => [true, false],
            'comparisonTable' => $this->tableByKeys([], [['financial_benefit.benefits'], ['financial_benefit.benefits']]),
        ]);
        $data = (new AdminComparisonPptxDataBuilder)->build($viewModel);

        $this->assertSame(1, $data['survey_comparison']['competitor_total']);
        $this->assertSame(1, $data['survey_comparison']['excluded_competitor_count']);
        $this->assertSame(1, $this->surveyRow($data, 'benefits')['competitor_count'], '材料不足の競合B社は数えない');
    }

    public function test_survey_comparison_has_no_competitor_counts_when_every_competitor_is_excluded(): void
    {
        $viewModel = $this->viewModel([
            'competitors' => [['name' => '競合A社', 'url' => 'https://a.example.com']],
            'competitorCount' => 1,
            'competitorsMaterialSufficient' => [false],
            'comparisonTable' => $this->tableByKeys([], [['financial_benefit.benefits']]),
        ]);
        $data = (new AdminComparisonPptxDataBuilder)->build($viewModel);

        $this->assertSame(0, $data['survey_comparison']['competitor_total']);
        $this->assertNull($this->surveyRow($data, 'benefits')['competitor_count']);
    }

    /**
     * 既存の「足りないもの」の各行の調査の文(候補者調査の項目名・割合)が、
     * 持ち方を変える前と同じ結果になること(24項目すべて)。
     */
    public function test_missing_items_candidate_survey_is_unchanged_for_all_24_items(): void
    {
        config(['admin_comparison_pptx.missing_items_max_count' => 100]);

        $missing = [];
        foreach ((array) config('brand_wheel.axes') as $axisConfig) {
            foreach ($axisConfig['sub_elements'] as $subName) {
                $missing[] = ['axis_name' => $axisConfig['name_ja'], 'sub_name' => $subName, 'competitor_matched_count' => 2];
            }
        }
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missing]));
        $items = $data['missing_items']['items'];
        $this->assertCount(24, $items);

        $legacy = CandidateSurveyCatalogTest::legacyMapping();
        $i = 0;
        foreach ((array) config('brand_wheel.axes') as $axisKey => $axisConfig) {
            foreach (array_keys($axisConfig['sub_elements']) as $subKey) {
                [$expectedName, $expectedPercentage] = $legacy["{$axisKey}.{$subKey}"];
                $this->assertSame(
                    ['item' => $expectedName, 'percentage' => $expectedPercentage],
                    ['item' => $items[$i]['candidate_survey']['item'], 'percentage' => $items[$i]['candidate_survey']['percentage']],
                    "{$axisKey}.{$subKey}: 調査の文の材料が変更前と同じ",
                );
                $i++;
            }
        }
    }

    /** 依頼CN-A3: 導線名(従来どおり)に、対応する調査の選択肢のキーを添えて渡す。 */
    public function test_recommended_site_flows_carry_the_survey_option_key_alongside_the_names(): void
    {
        $viewModel = $this->viewModel([
            'missingFromSelf' => [
                ['axis_name' => '経営スタイル', 'sub_name' => '会社の性格', 'competitor_matched_count' => 2],
                ['axis_name' => '資産的魅力', 'sub_name' => '規模・影響力', 'competitor_matched_count' => 2],
            ],
        ]);
        $data = (new AdminComparisonPptxDataBuilder)->build($viewModel);

        $this->assertSame($data['recommended_site_flow_names'], array_column($data['recommended_site_flows'], 'name'));
        foreach ($data['recommended_site_flows'] as $flow) {
            $this->assertArrayHasKey('option', $flow);
        }
        $this->assertContains('culture', array_column($data['recommended_site_flows'], 'option'));
        $this->assertContains(null, array_column($data['recommended_site_flows'], 'option'), '調査に対応しない導線はnull');
    }

    // ---- 依頼CO-2(2026-10-06): 「足りないもの」の文を、表と同じ状態に合わせる ----

    private function workEnvironmentMissingItem(): array
    {
        $axis = (array) config('brand_wheel.axes.asset');

        return $this->missingFromSelfItem($axis['name_ja'], $axis['sub_elements']['office_facility'], 3);
    }

    /** 調査の選択肢(働き方や職場環境=2項目)の一部が○なら、×の1項目の文も「一部」(表の△と同じ)。 */
    public function test_a_missing_item_carries_the_same_self_state_as_the_survey_table_row(): void
    {
        $partial = (new AdminComparisonPptxDataBuilder)->build($this->viewModel([
            'missingFromSelf' => [$this->workEnvironmentMissingItem()],
            'comparisonTable' => $this->tableByKeys(['relationship.mental_freedom'], [[], []]),
        ]));
        $this->assertSame('partial', $partial['missing_items']['items'][0]['candidate_survey']['self_state']);
        $this->assertSame($this->surveyRow($partial, 'work_environment')['self_state'], $partial['missing_items']['items'][0]['candidate_survey']['self_state']);

        $none = (new AdminComparisonPptxDataBuilder)->build($this->viewModel([
            'missingFromSelf' => [$this->workEnvironmentMissingItem()],
            'comparisonTable' => $this->tableByKeys([], [[], []]),
        ]));
        $this->assertSame('unconfirmed', $none['missing_items']['items'][0]['candidate_survey']['self_state']);
        $this->assertSame($this->surveyRow($none, 'work_environment')['self_state'], $none['missing_items']['items'][0]['candidate_survey']['self_state']);
    }

    public function test_a_missing_item_without_a_survey_option_has_no_self_state(): void
    {
        $axis = (array) config('brand_wheel.axes.emotional_benefit');
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel([
            'missingFromSelf' => [$this->missingFromSelfItem($axis['name_ja'], $axis['sub_elements']['superiority'], 3)],
        ]));

        $this->assertNull($data['missing_items']['items'][0]['candidate_survey']['self_state']);
    }

    public function test_the_state_is_computed_in_one_place_for_the_sentence_and_the_table(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/Report/AdminComparisonPptxDataBuilder.php'));

        $this->assertSame(1, substr_count($source, "\$selfMatched === \$mapped => 'confirmed'"), '状態の分岐(すべて○/一部/すべて×)を別々に持たない');
    }
}
