<?php

namespace Tests\Unit\BrandWheel;

use App\Models\BrandWheelAnalysisResult;
use App\Services\BrandWheel\BrandWheelEmailContentBuilder;
use Tests\TestCase;

class BrandWheelEmailContentBuilderTest extends TestCase
{
    private BrandWheelEmailContentBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new BrandWheelEmailContentBuilder;
    }

    public function test_insufficient_input_returns_minimal_data_without_axes_or_hexagon_content(): void
    {
        $result = new BrandWheelAnalysisResult([
            'status' => 'insufficient_input',
            'source_pages' => ['recruit_page' => 'absent', 'home_page' => 'unreadable'],
        ]);

        $data = $this->builder->build($result, '株式会社サンプル', '山田太郎', 'https://example.com');

        $this->assertTrue($data['insufficientInput']);
        $this->assertArrayNotHasKey('axes', $data);
        $this->assertArrayNotHasKey('axisStateCounts', $data);
        $this->assertArrayNotHasKey('coreValue', $data);
        $this->assertSame('サイト上に見つかりませんでした', $data['sourcePages']['recruit_page']['label']);
        $this->assertSame('取得できませんでした(保存データの読み込みに失敗しました)', $data['sourcePages']['home_page']['label']);
    }

    public function test_axis_state_summary_text_is_never_a_fraction(): void
    {
        $result = new BrandWheelAnalysisResult([
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'パーパスの記述']]],
                ['axis_key' => 'asset', 'state' => 'partial', 'matched_sub_elements' => []],
            ],
            'axis_state_counts' => ['read' => 1, 'partial' => 1, 'unread' => 4],
            'core_value_readable' => false,
            'core_value_evidence' => null,
            'quality_dimension_notes' => [],
            'cautions' => [],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);

        $data = $this->builder->build($result, '株式会社サンプル', '山田太郎', 'https://example.com');

        $this->assertStringNotContainsString('/', $data['axisStateSummaryText']);
        $this->assertStringNotContainsString('1/6', $data['axisStateSummaryText']);
        $this->assertSame('読み取れた1軸／一部読み取れた1軸／読み取れなかった4軸', $data['axisStateSummaryText']);
        $this->assertCount(6, $data['axes']);
    }

    public function test_matched_sub_elements_are_resolved_to_japanese_labels_with_evidence(): void
    {
        $result = new BrandWheelAnalysisResult([
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '地域社会に貢献するという理念'],
                ]],
            ],
            'axis_state_counts' => ['read' => 1, 'partial' => 0, 'unread' => 5],
            'core_value_readable' => true,
            'core_value_evidence' => '仕事の舞台裏にこそ価値がある',
            'quality_dimension_notes' => ['consistency' => '6軸を通じて一貫したメッセージが見られます。'],
            'cautions' => ['この分析はAIによる参考情報です。'],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);

        $data = $this->builder->build($result, '株式会社サンプル', '山田太郎', 'https://example.com');

        $willActivity = collect($data['axes'])->firstWhere('nameJa', '活動的魅力');
        $this->assertSame('パーパス', $willActivity['matchedSubElements'][0]['label']);
        $this->assertSame('地域社会に貢献するという理念', $willActivity['matchedSubElements'][0]['evidence']);

        $this->assertTrue($data['coreValue']['readable']);
        $this->assertSame('仕事の舞台裏にこそ価値がある', $data['coreValue']['evidence']);

        $this->assertSame('一貫性', $data['qualityDimensionNotes'][0]['nameJa']);
        $this->assertStringContainsString('本内容はAIによる参考情報です', $data['disclaimer']);
    }

    public function test_alt_text_has_no_leading_decorative_glyph_and_no_color_or_quality_language(): void
    {
        $result = new BrandWheelAnalysisResult([
            'status' => 'success',
            'axes' => [],
            'axis_state_counts' => ['read' => 2, 'partial' => 1, 'unread' => 3],
            'core_value_readable' => false,
            'core_value_evidence' => null,
            'quality_dimension_notes' => [],
            'cautions' => [],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);

        $data = $this->builder->build($result, '株式会社サンプル', '山田太郎', 'https://example.com');
        $altText = $data['altText'];

        // 装飾記号(▎等の記号類)で始まらないこと。
        $this->assertMatchesRegularExpression('/^[\p{L}\p{N}]/u', $altText);
        $this->assertStringNotContainsString('緑', $altText);
        $this->assertStringNotContainsString('赤', $altText);
        $this->assertStringContainsString('読み取れた軸2', $altText);
        $this->assertStringContainsString('一部読み取れた軸1', $altText);
        $this->assertStringContainsString('読み取れなかった軸3', $altText);
    }
}
