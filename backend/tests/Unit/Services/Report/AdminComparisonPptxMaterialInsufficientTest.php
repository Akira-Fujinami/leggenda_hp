<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\AdminComparisonPptxDataBuilder;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Support\Report\MultiSiteReportViewModel;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼CH-1b(2026-10-01): status不成立(依頼CD-3、AdminComparisonPptxSelfUnreadableTest
 * 参照)とは独立に、AIに渡した材料の量(input_char_count)が閾値未満のとき、
 * 比較スライド(CB-1)・足りないものスライド(CB-2)の数字を専用の文言に
 * 置き換えること。自社・競合の両方が対象。
 */
class AdminComparisonPptxMaterialInsufficientTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function reservedTempPath(string $prefix, string $extension): string
    {
        $reserved = tempnam(sys_get_temp_dir(), $prefix);
        $path = $reserved.'.'.$extension;
        rename($reserved, $path);

        return $path;
    }

    private function slideXml(string $bytes): string
    {
        $tmp = $this->reservedTempPath('material-insufficient-slide', 'pptx');
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        return $xml;
    }

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

    private function data(bool $selfMaterialSufficient, array $competitorsMaterialSufficient = [true, true]): array
    {
        $viewModel = new MultiSiteReportViewModel(
            selfCompanyDisplayName: 'テスト株式会社',
            generatedAtLabel: '2026年10月1日',
            selfWebsiteUrl: 'https://example.com',
            competitors: [
                ['name' => '競合A社', 'url' => 'https://a.example.com'],
                ['name' => '競合B社', 'url' => 'https://b.example.com'],
            ],
            competitorCount: 2,
            majorityThreshold: 2,
            selfReadable: true,
            selfTotalMatched: 2,
            selfTotalMax: 24,
            brandWheelRadarPngCombined: null,
            missingFromSelf: [],
            selfStrengths: [],
            comparisonTable: $this->comparisonTable(
                ['活動的魅力' => 2],
                [
                    ['活動的魅力' => 3, '資産的魅力' => 2, '経営スタイル' => 3, '就業環境' => 2, '情緒的便益' => 3, '金銭的便益' => 2],
                    ['活動的魅力' => 1, '資産的魅力' => 2, '経営スタイル' => 1, '就業環境' => 2, '情緒的便益' => 1, '金銭的便益' => 2],
                ],
            ),
            selfEvidenceByAxis: [],
            hasQuoteTranslations: false,
            selfMaterialSufficient: $selfMaterialSufficient,
            competitorsMaterialSufficient: $competitorsMaterialSufficient,
        );

        return (new AdminComparisonPptxDataBuilder)->build($viewModel);
    }

    /**
     * 依頼CH-1b必須: 自社がstatus=success(selfReadable=true)でも、材料
     * (material_sufficient)が閾値未満のとき、比較スライドで実際の件数
     * ("2 / 24")を出さず、専用の文言(insufficient_material_notice)に
     * 置き換えること。CD-3の文言(self_data_unavailable_notice)とは
     * 区別すること。
     */
    public function test_comparison_slide_shows_the_material_notice_instead_of_the_real_self_score(): void
    {
        $data = $this->data(selfMaterialSufficient: false);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generate($data));

        $this->assertStringContainsString(
            (string) config('brand_wheel.insufficient_material_notice'),
            $xml,
        );
        $this->assertStringNotContainsString('2 / 24', $xml);
        $this->assertStringNotContainsString(
            (string) config('admin_comparison_pptx.self_data_unavailable_notice'),
            $xml,
            'status不成立の文言(CD-3)ではなく材料不足専用の文言が出ること',
        );
    }

    public function test_comparison_slide_shows_real_numbers_when_self_material_is_sufficient(): void
    {
        $data = $this->data(selfMaterialSufficient: true);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generate($data));

        $this->assertStringContainsString('2 / 24', $xml);
        $this->assertStringNotContainsString((string) config('brand_wheel.insufficient_material_notice'), $xml);
    }

    /**
     * 依頼CH-1b必須: 競合側も対象。競合A社が材料不足のとき、その社の
     * 件数("3 / 24")を出さず、マトリクスの該当列も「－」に置き換える
     * (自社・競合B社は通常どおり数字を出す)。
     */
    public function test_comparison_slide_shows_the_material_notice_for_an_insufficient_competitor(): void
    {
        $data = $this->data(selfMaterialSufficient: true, competitorsMaterialSufficient: [false, true]);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generate($data));

        $this->assertStringContainsString((string) config('brand_wheel.insufficient_material_notice'), $xml);
        $this->assertStringNotContainsString('3 / 24', $xml, '競合A社(材料不足)の総合件数が出ないこと');
        $this->assertStringContainsString('2 / 24', $xml, '自社は通常どおり数字が出ること');
    }

    /**
     * 依頼CH-1b必須: 「足りないもの」スライド(自社視点)も、自社が材料
     * 不足のとき専用の文言に置き換えること。
     */
    public function test_missing_items_slide_shows_the_material_notice_when_self_material_is_insufficient(): void
    {
        $data = $this->data(selfMaterialSufficient: false);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateMissingItemsSlide($data));

        $this->assertStringContainsString((string) config('brand_wheel.insufficient_material_notice'), $xml);
        $this->assertStringNotContainsString((string) config('admin_comparison_pptx.missing_items_empty_text'), $xml);
    }
}
