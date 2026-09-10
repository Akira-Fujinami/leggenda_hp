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
        $this->assertSame(['name' => 'テスト株式会社', 'matched' => 10, 'total' => 24, 'is_self' => true], $data['companies'][0]);
    }

    public function test_competitor_matched_counts_are_summed_from_the_comparison_table_by_index(): void
    {
        $table = $this->comparisonTable([], [
            ['活動的魅力' => 2, '資産的魅力' => 1],
            ['活動的魅力' => 4],
        ]);
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['comparisonTable' => $table]));

        $this->assertSame(['name' => '競合A社', 'matched' => 3, 'total' => 24, 'is_self' => false], $data['companies'][1]);
        $this->assertSame(['name' => '競合B社', 'matched' => 4, 'total' => 24, 'is_self' => false], $data['companies'][2]);
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

    /**
     * 依頼BM-2: 網かけが0件のとき、専用の文言に切り替わること
     * (空欄にしない、依頼者指定)。
     */
    public function test_summary_uses_the_no_gap_template_when_nothing_is_below_competitors(): void
    {
        $table = $this->comparisonTable(
            ['活動的魅力' => 4, '資産的魅力' => 4, '経営スタイル' => 4, '就業環境' => 4, '情緒的便益' => 4, '金銭的便益' => 4],
            [['活動的魅力' => 2]],
        );
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['comparisonTable' => $table, 'competitors' => [['name' => '競合A社', 'url' => 'https://a.example.com']], 'competitorCount' => 1]));

        $this->assertSame(
            config('admin_comparison_pptx.summary_templates.total_rank_ahead')
                .config('admin_comparison_pptx.summary_templates.no_gap_axes'),
            $data['summary'],
        );
    }

    /**
     * 依頼BN-1: 網かけが1件以上・閾値(既定2)以内のとき、下回っている
     * 領域名を「」を隣接させて列挙すること(「と」でつながない)。
     * axis_captions(表に既に出ている補足)を繰り返さないこと。2文構成
     * (総合の文+領域の文)で、読点で1文につながないこと。
     */
    public function test_summary_names_gap_axes_when_within_the_list_threshold(): void
    {
        $table = $this->comparisonTable(
            ['活動的魅力' => 4, '資産的魅力' => 4, '経営スタイル' => 1, '就業環境' => 4, '情緒的便益' => 4, '金銭的便益' => 1],
            [['経営スタイル' => 3, '金銭的便益' => 3]],
        );
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['comparisonTable' => $table, 'competitors' => [['name' => '競合A社', 'url' => 'https://a.example.com']], 'competitorCount' => 1, 'selfTotalMatched' => 18, 'selfTotalMax' => 24]));

        $expected = config('admin_comparison_pptx.summary_templates.total_rank_ahead')
            .sprintf(config('admin_comparison_pptx.summary_templates.gap_axes_named_other'), '「経営スタイル」「金銭的便益」', 2);
        $this->assertSame($expected, $data['summary']);
        // 表に既に出ている捕捉(axis_captions)を繰り返さないこと。
        $this->assertStringNotContainsString('理念・組織・意思決定', $data['summary']);
        // 終止形へ読点を続けていないこと(1文目は「。」で終わること)。
        $this->assertStringNotContainsString('ます、', $data['summary']);
        $this->assertStringNotContainsString('と「', $data['summary']);
    }

    /**
     * 依頼BN-1: 総合が下回るときは「とくに」、並ぶ/上回るときは「ただし」
     * で領域の文を始めること。
     */
    public function test_summary_uses_a_different_connector_when_the_total_is_behind(): void
    {
        $table = $this->comparisonTable(
            ['活動的魅力' => 1, '資産的魅力' => 1],
            [['活動的魅力' => 4, '資産的魅力' => 4]],
        );
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['comparisonTable' => $table, 'competitors' => [['name' => '競合A社', 'url' => 'https://a.example.com']], 'competitorCount' => 1, 'selfTotalMatched' => 2, 'selfTotalMax' => 24]));

        $this->assertStringContainsString('とくに', $data['summary']);
        $this->assertStringStartsWith(config('admin_comparison_pptx.summary_templates.total_rank_behind'), $data['summary']);
    }

    /**
     * 依頼BN-1で導入、依頼BO-2で閾値を2→4へ引き上げた際に更新。閾値を
     * 超える件数(6領域中5領域、gap_axes_all(全6領域)とは別のケース)の
     * ときは、領域名を列挙せず件数だけを述べること。
     */
    public function test_summary_omits_axis_names_beyond_the_list_threshold(): void
    {
        $table = $this->comparisonTable(
            ['活動的魅力' => 1, '資産的魅力' => 1, '経営スタイル' => 1, '就業環境' => 1, '情緒的便益' => 1, '金銭的便益' => 4],
            [['活動的魅力' => 3, '資産的魅力' => 3, '経営スタイル' => 3, '就業環境' => 3, '情緒的便益' => 3]],
        );
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['comparisonTable' => $table, 'competitors' => [['name' => '競合A社', 'url' => 'https://a.example.com']], 'competitorCount' => 1, 'selfTotalMatched' => 9, 'selfTotalMax' => 24]));

        $expected = config('admin_comparison_pptx.summary_templates.total_rank_behind')
            .sprintf(config('admin_comparison_pptx.summary_templates.gap_axes_unnamed_behind'), 5);
        $this->assertSame($expected, $data['summary']);
        $this->assertStringNotContainsString('「', $data['summary']);
    }

    /**
     * 依頼BO-2: 閾値を4へ引き上げた境界値。ちょうど4領域は、名前を
     * 「」で隣接させて列挙すること(5領域からは列挙しない)。
     */
    public function test_summary_names_gap_axes_at_the_new_threshold_boundary_of_four(): void
    {
        $table = $this->comparisonTable(
            ['活動的魅力' => 1, '資産的魅力' => 1, '経営スタイル' => 1, '就業環境' => 1, '情緒的便益' => 4, '金銭的便益' => 4],
            [['活動的魅力' => 3, '資産的魅力' => 3, '経営スタイル' => 3, '就業環境' => 3]],
        );
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['comparisonTable' => $table, 'competitors' => [['name' => '競合A社', 'url' => 'https://a.example.com']], 'competitorCount' => 1, 'selfTotalMatched' => 20, 'selfTotalMax' => 24]));

        $expected = config('admin_comparison_pptx.summary_templates.total_rank_ahead')
            .sprintf(config('admin_comparison_pptx.summary_templates.gap_axes_named_other'), '「活動的魅力」「資産的魅力」「経営スタイル」「就業環境」', 4);
        $this->assertSame($expected, $data['summary']);
    }

    /**
     * 依頼BN-1: 6領域すべてが該当するときは、専用の文言(6領域すべて)に
     * なること(件数を列挙する一般ルートを通らないこと)。
     */
    public function test_summary_uses_the_all_axes_template_when_every_axis_is_a_gap(): void
    {
        $table = $this->comparisonTable(
            ['活動的魅力' => 1, '資産的魅力' => 1, '経営スタイル' => 1, '就業環境' => 1, '情緒的便益' => 1, '金銭的便益' => 1],
            [['活動的魅力' => 3, '資産的魅力' => 3, '経営スタイル' => 3, '就業環境' => 3, '情緒的便益' => 3, '金銭的便益' => 3]],
        );
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['comparisonTable' => $table, 'competitors' => [['name' => '競合A社', 'url' => 'https://a.example.com']], 'competitorCount' => 1, 'selfTotalMatched' => 6, 'selfTotalMax' => 24]));

        $expected = config('admin_comparison_pptx.summary_templates.total_rank_behind')
            .config('admin_comparison_pptx.summary_templates.gap_axes_all');
        $this->assertSame($expected, $data['summary']);
    }

    public function test_summary_never_empty(): void
    {
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel());

        $this->assertNotSame('', trim($data['summary']));
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

        $this->assertSame(
            [
                ['axis_name' => '金銭的便益', 'sub_name' => '福利厚生'],
                ['axis_name' => '活動的魅力', 'sub_name' => '社員インタビュー'],
            ],
            $data['missing_items']['items'],
        );
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
     */
    public function test_missing_items_folds_the_tail_into_others_count_beyond_the_max(): void
    {
        $maxCount = (int) config('admin_comparison_pptx.missing_items_max_count');
        $missingFromSelf = [];
        for ($i = 0; $i < $maxCount + 3; $i++) {
            $missingFromSelf[] = $this->missingFromSelfItem('活動的魅力', "項目{$i}", $maxCount + 3 - $i);
        }

        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $this->assertCount($maxCount - 1, $data['missing_items']['items']);
        $this->assertSame(4, $data['missing_items']['others_count']);
        $this->assertSame('項目0', $data['missing_items']['items'][0]['sub_name']);
        $this->assertSame('項目'.($maxCount - 2), $data['missing_items']['items'][$maxCount - 2]['sub_name'] ?? null);
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
        $this->assertSame('競合3社中2社以上が伝えていて、自社が伝えていない項目', $data3['missing_items']['heading']);

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
        $this->assertSame('競合5社中3社以上が伝えていて、自社が伝えていない項目', $data5['missing_items']['heading']);
    }
}
