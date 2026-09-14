<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\AdminComparisonPptxGenerator;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼BZ-1: ブランド・ホイールの説明ページ(generateExplanationSlide())。
 * 比較スライドと同じ制約(スライドサイズ・schemeClr不使用・Meiryo明示・
 * 外部参照ゼロ)を守っていること、載せる内容がconfig('brand_wheel.axes')・
 * axis_unread_caveatと一字一句一致すること、分析結果(会社名・スコア)に
 * 依存しない固定内容であることを確認する。
 */
class AdminComparisonPptxExplanationSlideTest extends TestCase
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

    /**
     * @return array{0: string, 1: string}  [slide1.xmlの中身, presentation.xmlの中身]
     */
    private function explanationSlideXml(): array
    {
        $bytes = app(AdminComparisonPptxGenerator::class)->generateExplanationSlide();

        $tmp = $this->reservedTempPath('explanation-slide', 'pptx');
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $presentationXml = $zip->getFromName('ppt/presentation.xml');
        $zip->close();

        return [$slideXml, $presentationXml];
    }

    public function test_it_has_no_external_references_no_scheme_colors_and_explicit_meiryo_font(): void
    {
        [$slideXml, ] = $this->explanationSlideXml();

        $this->assertSame(0, preg_match('/\br:(id|embed|link)="/', $slideXml), '説明ページは画像・グラフ等を参照していないこと');
        $this->assertSame(0, substr_count($slideXml, 'schemeClr'), 'テーマ色(schemeClr)を使っていないこと');
        $this->assertGreaterThan(0, substr_count($slideXml, 'Meiryo'), 'フォントを明示指定していること');
    }

    public function test_it_uses_the_required_16_9_slide_size(): void
    {
        [, $presentationXml] = $this->explanationSlideXml();

        $this->assertMatchesRegularExpression('/<p:sldSz\b[^>]*cx="12192000"[^>]*\/>/', $presentationXml);
        $this->assertMatchesRegularExpression('/<p:sldSz\b[^>]*cy="6858000"[^>]*\/>/', $presentationXml);
    }

    /**
     * 依頼BZ-1: 6軸の名前・定義は、config('brand_wheel.axes.*.name_ja'/
     * 'definition')と一致すること(直書きしない、文言を書き換えない)。
     */
    public function test_it_shows_all_six_axis_names_and_definitions_matching_config_verbatim(): void
    {
        [$slideXml, ] = $this->explanationSlideXml();

        foreach ((array) config('brand_wheel.axes') as $axisConfig) {
            $this->assertStringContainsString(
                htmlspecialchars((string) $axisConfig['name_ja'], ENT_QUOTES | ENT_XML1),
                $slideXml,
                "軸名「{$axisConfig['name_ja']}」がそのまま含まれていること",
            );
            $this->assertStringContainsString(
                htmlspecialchars((string) $axisConfig['definition'], ENT_QUOTES | ENT_XML1),
                $slideXml,
                "「{$axisConfig['name_ja']}」の定義文がconfigの値と一字一句一致すること",
            );
        }
    }

    /**
     * 依頼BZ-1(必須): axis_unread_caveatは「絶対に消してはいけない文言」
     * (lead-pdf.blade.phpのコメント参照)。文言を短縮・書き換えず、
     * configの値と一字一句一致すること。
     */
    public function test_it_shows_the_axis_unread_caveat_matching_config_verbatim(): void
    {
        [$slideXml, ] = $this->explanationSlideXml();

        $caveat = (string) config('brand_wheel.axis_unread_caveat');
        $this->assertNotSame('', $caveat, 'テスト自体が空文字と比較して常に成功する事態を避ける');
        $this->assertStringContainsString(htmlspecialchars($caveat, ENT_QUOTES | ENT_XML1), $slideXml);
    }

    /**
     * 依頼BZ-1: 3領域の区分(会社の魅力・会社との距離・仕事の魅力)が
     * 載っていること。
     */
    public function test_it_shows_the_three_region_groupings(): void
    {
        [$slideXml, ] = $this->explanationSlideXml();

        $this->assertStringContainsString('会社の魅力', $slideXml);
        $this->assertStringContainsString('会社との距離', $slideXml);
        $this->assertStringContainsString('仕事の魅力', $slideXml);
    }

    /**
     * 依頼BZ-1: 分析結果に依存しない固定内容 ―― generateExplanationSlide()
     * は引数を取らない(会社名・スコアを渡しようがない)。比較スライド側の
     * ダミー会社名(テスト用フィクスチャ)が誤って混入していないことも
     * あわせて確認する。
     */
    public function test_it_takes_no_arguments_and_contains_no_company_or_score_data(): void
    {
        $method = new \ReflectionMethod(AdminComparisonPptxGenerator::class, 'generateExplanationSlide');
        $this->assertCount(0, $method->getParameters(), '分析結果を渡せない(固定内容である)こと');

        [$slideXml, ] = $this->explanationSlideXml();
        $this->assertStringNotContainsString('競合A社', $slideXml);
        $this->assertStringNotContainsString('テスト株式会社', $slideXml);
    }
}
