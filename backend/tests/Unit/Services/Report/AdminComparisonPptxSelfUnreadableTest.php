<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\AdminComparisonPptxDataBuilder;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Support\Report\MultiSiteReportViewModel;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼CD-3(2026-09-28): 自社のブランド・ホイール判定が成立していない
 * (selfReadable===false)ときの比較スライド(CB-1)・足りないものスライド
 * (CB-2)の表示。
 *
 * 背景(CD-1調査で判明): 自社の指定URLがそれ自体「採用ページ」と認識される
 * 場合、自己参照検出(HtmlSeoAnalyzer::isRecruitPageUrl()、既存の正しい
 * 設計、この依頼では変更しない)により入力が薄くなり、
 * insufficient_input等のstatus(success以外はすべてaxes:[]に畳まれる、
 * BrandWheelLeadResponseComposer::resolveStatus()参照)で終わることがある。
 * これは巡回やディスパッチの不具合ではなく正当な終了状態のため、判定
 * ロジック自体は変更しない ―― 代わりに、この状態を「0/24」等の数字として
 * 見せず、判定が成立していないことが一目で分かる専用の文言に置き換える
 * (config('admin_comparison_pptx.self_data_unavailable_notice'))。
 */
class AdminComparisonPptxSelfUnreadableTest extends TestCase
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
        $tmp = $this->reservedTempPath('self-unreadable-slide', 'pptx');
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

    private function data(bool $selfReadable): array
    {
        $viewModel = new MultiSiteReportViewModel(
            selfCompanyDisplayName: 'テスト株式会社',
            generatedAtLabel: '2026年9月28日',
            selfWebsiteUrl: 'https://example.com',
            competitors: [
                ['name' => '競合A社', 'url' => 'https://a.example.com'],
                ['name' => '競合B社', 'url' => 'https://b.example.com'],
            ],
            competitorCount: 2,
            majorityThreshold: 2,
            selfReadable: $selfReadable,
            // 依頼CD-1調査で確認した実際の壊れ方: selfReadable=falseの
            // ときaxesが空になり、selfTotalMatched/selfTotalMaxはともに0
            // になる(BrandWheelLeadResponseComposer::compose()参照)。
            selfTotalMatched: $selfReadable ? 10 : 0,
            selfTotalMax: $selfReadable ? 24 : 0,
            brandWheelRadarPngCombined: null,
            missingFromSelf: [],
            selfStrengths: [],
            // 依頼CD-3テスト注記: 競合側は全6領域に非ゼロのmatched件数を
            // 与える ―― そうしないと「0 / 4」が競合側の正当な表示として
            // 出てしまい、「0を判定結果であるかのように見せない」の検証
            // (assertStringNotContainsString('0 / 4', …))が自社起因かどうか
            // 区別できなくなるため。
            comparisonTable: $this->comparisonTable(
                $selfReadable ? ['活動的魅力' => 2] : [],
                [
                    ['活動的魅力' => 3, '資産的魅力' => 2, '経営スタイル' => 3, '就業環境' => 2, '情緒的便益' => 3, '金銭的便益' => 2],
                    ['活動的魅力' => 1, '資産的魅力' => 2, '経営スタイル' => 1, '就業環境' => 2, '情緒的便益' => 1, '金銭的便益' => 2],
                ],
            ),
            selfEvidenceByAxis: [],
            hasQuoteTranslations: false,
        );

        return (new AdminComparisonPptxDataBuilder)->build($viewModel);
    }

    /**
     * 依頼CD-3必須: 自社が判定不能のとき、比較スライド(CB-1)に
     * 「0 / 24」「0 / 4」等、0を判定結果であるかのように見せる数字を
     * 一切出さないこと。専用の文言(config)は出すこと。
     */
    public function test_comparison_slide_shows_the_notice_instead_of_zero_scores_when_self_is_unreadable(): void
    {
        $data = $this->data(selfReadable: false);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generate($data));

        $this->assertStringContainsString(
            (string) config('admin_comparison_pptx.self_data_unavailable_notice'),
            $xml,
        );
        $this->assertStringNotContainsString('0 / 24', $xml);
        $this->assertStringNotContainsString('0 / 4', $xml);
    }

    /**
     * 対照実験: 自社が判定できているときは、従来どおり数字(総合+領域別)が
     * 出ること。専用の文言は出ないこと(退行していないことの確認)。
     */
    public function test_comparison_slide_shows_real_numbers_when_self_is_readable(): void
    {
        $data = $this->data(selfReadable: true);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generate($data));

        $this->assertStringContainsString('10 / 24', $xml);
        $this->assertStringNotContainsString(
            (string) config('admin_comparison_pptx.self_data_unavailable_notice'),
            $xml,
        );
    }

    /**
     * 依頼CD-3必須: 「足りないもの」スライド(CB-2)も、自社が判定不能の
     * ときは通常の一覧(self_matchedが全項目falseになるため実質ほぼ全項目が
     * 「足りない」と誤解を招く一覧になってしまう)ではなく、専用の文言に
     * 置き換えること。
     */
    public function test_missing_items_slide_shows_the_notice_when_self_is_unreadable(): void
    {
        $data = $this->data(selfReadable: false);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateMissingItemsSlide($data));

        $this->assertStringContainsString(
            (string) config('admin_comparison_pptx.self_data_unavailable_notice'),
            $xml,
        );
        $this->assertStringNotContainsString((string) config('admin_comparison_pptx.missing_items_empty_text'), $xml);
    }

    public function test_missing_items_slide_shows_the_normal_heading_when_self_is_readable(): void
    {
        $data = $this->data(selfReadable: true);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateMissingItemsSlide($data));

        $this->assertStringNotContainsString(
            (string) config('admin_comparison_pptx.self_data_unavailable_notice'),
            $xml,
        );
    }
}
