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

    /**
     * 修正4(2026-08-25): 上限内に収まる最後の句点(。)で切る。句点が
     * 上限より手前にあっても、句点の直後で切る(文の途中で切らない)。
     */
    public function test_truncates_mid_term_action_at_the_last_sentence_boundary_within_the_limit(): void
    {
        // 120字上限(MID_TERM_ACTION_MAX_CHARS)。1文目は句点まで短く、
        // 2文目が上限を跨いで途中で終わる長さにする。
        $firstSentence = str_repeat('あ', 30).'。';
        $secondSentence = str_repeat('い', 100).'。';
        $text = $firstSentence.$secondSentence;

        $result = $this->parser()->parse([
            'one_point' => null,
            'recommendation' => null,
            'mid_term_action' => $text,
            'focus_sub_element_keys' => [],
        ], provider: 'openai', model: null, isMock: false, promptVersion: 'v3');

        $this->assertSame($firstSentence, $result->midTermAction);
        $this->assertStringEndsWith('。', $result->midTermAction);
        $this->assertStringEndsNotWith('…', $result->midTermAction);
    }

    /**
     * 句点が1つも無い場合のみ、従来どおり上限で切って末尾に「…」を付ける
     * (回帰防止)。
     */
    public function test_truncates_reason_with_an_ellipsis_when_no_sentence_boundary_exists(): void
    {
        $longText = str_repeat('あ', 500);

        $result = $this->parser()->parse([
            'one_point' => null,
            'recommendation' => null,
            'reason' => $longText,
            'focus_sub_element_keys' => [],
        ], provider: 'openai', model: null, isMock: false, promptVersion: 'v3');

        $this->assertLessThanOrEqual(201, mb_strlen($result->reason));
        $this->assertStringEndsWith('…', $result->reason);
    }

    public function test_returns_null_for_missing_or_blank_fields(): void
    {
        $result = $this->parser()->parse([], provider: 'mock', model: null, isMock: true, promptVersion: null);

        $this->assertNull($result->onePoint);
        $this->assertNull($result->recommendation);
        $this->assertSame([], $result->focusSubElementKeys);
    }

    /**
     * 依頼S(2026-08-26): v7のプロンプト例`"mid_term_action": "string または
     * null"`がnullをクォートで囲んだ記法だったため、モデルが文字列"null"
     * (4文字)を返し、レポートに「null」がそのまま印字される事故が実物
     * レポート37で発生した。値全体が"null"(大文字小文字を問わない完全一致)
     * の場合はPHPのnullとして扱うこと。
     */
    public function test_treats_the_literal_string_null_as_php_null(): void
    {
        $result = $this->parser()->parse([
            'one_point' => 'null',
            'recommendation' => 'NULL',
            'reason' => 'Null',
            'mid_term_action' => 'null',
            'focus_items_reason' => 'null',
            'focus_sub_element_keys' => [],
        ], provider: 'openai', model: null, isMock: false, promptVersion: 'v8');

        $this->assertNull($result->onePoint);
        $this->assertNull($result->recommendation);
        $this->assertNull($result->reason);
        $this->assertNull($result->midTermAction);
        $this->assertNull($result->focusItemsReason);
    }

    /**
     * 依頼AF-2(2026-08-27): focus_items_reason(BrandWheelImprovementFocus
     * Composerが選んだ項目についてAIが書いた理由)も、既存のreason等と
     * 同じparseForbiddenPhraseSafeText()を通ることを確認する(禁止語・
     * 文字列"null"の扱いが同じであること)。
     */
    public function test_parses_focus_items_reason(): void
    {
        $result = $this->parser()->parse([
            'focus_items_reason' => '組織構造と職場の雰囲気は、候補者が働くイメージを持つうえで重要な情報です。',
            'focus_sub_element_keys' => [],
        ], provider: 'openai', model: 'gpt-4o', isMock: false, promptVersion: 'v11');

        $this->assertSame('組織構造と職場の雰囲気は、候補者が働くイメージを持つうえで重要な情報です。', $result->focusItemsReason);
    }

    public function test_focus_items_reason_is_null_when_missing(): void
    {
        $result = $this->parser()->parse([
            'focus_sub_element_keys' => [],
        ], provider: 'openai', model: 'gpt-4o', isMock: false, promptVersion: 'v11');

        $this->assertNull($result->focusItemsReason);
    }

    /**
     * 依頼S最重要: 前後の空白だけを含む"null"(例: " null "や全角スペース)も
     * 完全一致として扱う一方、本文の途中に"null"という語がたまたま含まれる
     * 正当な文章までは絶対に捨てないこと(str_contains()ではなく完全一致で
     * 判定していることの回帰防止)。
     */
    public function test_does_not_null_out_legitimate_text_that_merely_contains_the_word_null(): void
    {
        $result = $this->parser()->parse([
            'one_point' => ' null ',
            'reason' => 'DBの値がnullのままでも動作に問題はありません。既存のnull許容設計を維持してください。',
            'focus_sub_element_keys' => [],
        ], provider: 'openai', model: null, isMock: false, promptVersion: 'v8');

        $this->assertNull($result->onePoint);
        $this->assertSame(
            'DBの値がnullのままでも動作に問題はありません。既存のnull許容設計を維持してください。',
            $result->reason,
        );
    }

    /**
     * 依頼S: recommended_contentsはparseTextList()経由でも同じ
     * parseForbiddenPhraseSafeText()を通るため、"null"の要素だけが
     * 除外され、他の正当な要素は残ること。
     */
    public function test_recommended_contents_drops_only_the_null_string_elements(): void
    {
        $result = $this->parser()->parse([
            'one_point' => null,
            'recommendation' => null,
            'recommended_contents' => ['入社数年目の社員紹介', 'null', 'NULL', '部署間の関わり方が分かるエピソード'],
            'focus_sub_element_keys' => [],
        ], provider: 'openai', model: null, isMock: false, promptVersion: 'v8');

        $this->assertSame(
            ['入社数年目の社員紹介', '部署間の関わり方が分かるエピソード'],
            $result->recommendedContents,
        );
    }
}
