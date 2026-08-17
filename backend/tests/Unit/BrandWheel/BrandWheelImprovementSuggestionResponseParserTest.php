<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelImprovementSuggestionResponseParser;
use Tests\TestCase;

class BrandWheelImprovementSuggestionResponseParserTest extends TestCase
{
    private function parser(): BrandWheelImprovementSuggestionResponseParser
    {
        return new BrandWheelImprovementSuggestionResponseParser;
    }

    public function test_parses_one_point_and_recommendation(): void
    {
        $result = $this->parser()->parse([
            'one_point' => 'まずは仕事の魅力から着手しましょう。',
            'recommendation' => 'まずは仕事の魅力に関する情報を拡充することを推奨します。',
            'focus_sub_element_keys' => ['purpose'],
        ], provider: 'openai', model: 'gpt-4o-mini', isMock: false, promptVersion: 'v1');

        $this->assertSame('まずは仕事の魅力から着手しましょう。', $result->onePoint);
        $this->assertSame('まずは仕事の魅力に関する情報を拡充することを推奨します。', $result->recommendation);
        $this->assertSame(['purpose'], $result->focusSubElementKeys);
    }

    /**
     * key_message/impressionと同じ安全側の検証(2026-07-30の指摘)を
     * one_point/recommendationにも適用する。
     */
    public function test_nulls_one_point_and_recommendation_when_they_contain_forbidden_phrases(): void
    {
        config(['brand_wheel.forbidden_phrases' => ['不足']]);

        $result = $this->parser()->parse([
            'one_point' => '情報が不足しているため追加しましょう。',
            'recommendation' => 'まずは仕事の魅力に関する情報を拡充することを推奨します。',
            'focus_sub_element_keys' => [],
        ], provider: 'openai', model: null, isMock: false, promptVersion: 'v1');

        $this->assertNull($result->onePoint);
        $this->assertNotNull($result->recommendation);
    }

    /**
     * AIが実在しない下位要素キーを捏造して言及した場合、そのキーは除外する
     * (config('brand_wheel.axes')由来の実在24キーのみを残す、捏造防止)。
     */
    public function test_filters_out_focus_sub_element_keys_that_do_not_exist_in_the_24_item_framework(): void
    {
        $result = $this->parser()->parse([
            'one_point' => null,
            'recommendation' => null,
            'focus_sub_element_keys' => ['purpose', 'this_key_does_not_exist', 'colleagues'],
        ], provider: 'openai', model: null, isMock: false, promptVersion: 'v1');

        $this->assertSame(['purpose', 'colleagues'], array_values($result->focusSubElementKeys));
    }

    public function test_truncates_recommendation_to_the_configured_max_length(): void
    {
        $longText = str_repeat('あ', 500);

        $result = $this->parser()->parse([
            'one_point' => null,
            'recommendation' => $longText,
            'focus_sub_element_keys' => [],
        ], provider: 'openai', model: null, isMock: false, promptVersion: 'v1');

        $this->assertLessThanOrEqual(401, mb_strlen($result->recommendation));
        $this->assertStringEndsWith('…', $result->recommendation);
    }

    /**
     * 2026-08-19追加: サイト分析だけでは分からない社内事情(実施工数・担当
     * 部署等)を断定した実際の生成例(クライアントレビューで指摘)を踏まえた
     * 防波堤。プロンプト側の指示が最初の防波堤、これはAIが指示に従わなかった
     * 場合の最後の防波堤(forbidden_phrasesと同じ二重構成)。
     */
    public function test_nulls_reason_when_it_contains_an_assertive_phrase(): void
    {
        $result = $this->parser()->parse([
            'one_point' => null,
            'recommendation' => null,
            'reason' => '実行難易度も低く、既存の社内資料を活用することで迅速に対応可能です。',
            'focus_sub_element_keys' => [],
        ], provider: 'openai', model: null, isMock: false, promptVersion: 'v3');

        $this->assertNull($result->reason);
    }

    public function test_returns_null_for_missing_or_blank_fields(): void
    {
        $result = $this->parser()->parse([], provider: 'mock', model: null, isMock: true, promptVersion: null);

        $this->assertNull($result->onePoint);
        $this->assertNull($result->recommendation);
        $this->assertSame([], $result->focusSubElementKeys);
    }
}
