<?php

namespace Tests\Unit\Services\Report;

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
     * 依頼BN-1: 閾値を超える件数のときは、領域名を列挙せず件数だけを
     * 述べること。
     */
    public function test_summary_omits_axis_names_beyond_the_list_threshold(): void
    {
        $table = $this->comparisonTable(
            ['活動的魅力' => 1, '資産的魅力' => 1, '経営スタイル' => 1, '就業環境' => 4, '情緒的便益' => 4, '金銭的便益' => 4],
            [['活動的魅力' => 3, '資産的魅力' => 3, '経営スタイル' => 3]],
        );
        $data = (new AdminComparisonPptxDataBuilder)->build($this->viewModel(['comparisonTable' => $table, 'competitors' => [['name' => '競合A社', 'url' => 'https://a.example.com']], 'competitorCount' => 1, 'selfTotalMatched' => 15, 'selfTotalMax' => 24]));

        $expected = config('admin_comparison_pptx.summary_templates.total_rank_ahead')
            .sprintf(config('admin_comparison_pptx.summary_templates.gap_axes_unnamed_other'), 3);
        $this->assertSame($expected, $data['summary']);
        $this->assertStringNotContainsString('「', $data['summary']);
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
}
