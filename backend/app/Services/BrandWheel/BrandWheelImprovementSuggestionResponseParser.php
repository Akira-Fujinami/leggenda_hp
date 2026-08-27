<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionResult;

/**
 * 改善提案AIが返したJSON(デコード済み連想配列)を検証し、
 * BrandWheelImprovementSuggestionResultへ変換する。
 * BrandWheelAnalysisResponseParserと同じ「AIの出力はそのまま信用しない」方針:
 *
 * - one_point/reason/mid_term_action/recommendationはforbidden_phrasesチェックを
 *   通過しない場合nullにする(社外に出る文章のため、key_message/impressionと
 *   同じ扱い)。recommended_contents/focus_sub_element_keysは項目ごとに独立して
 *   同じチェックを適用する(1件が禁止語を含んでいても他の項目まで巻き添えで
 *   捨てない)。
 * - 依頼S(2026-08-26): 上記4フィールド+recommended_contentsの各要素は、
 *   値全体が"null"(大文字小文字を問わない完全一致)の場合もnullとして扱う
 *   ―― モデルが文字列"null"(4文字)を返し、レポートに「null」がそのまま
 *   印字される事故が実物レポート37で発生したため
 *   (parseForbiddenPhraseSafeText()参照)。
 * - focus_sub_element_keys/gap_closing/differentiation_opportunitiesは実在する
 *   24キー(config('brand_wheel.axes')由来)以外を除外する ―― AIが実在しない
 *   項目キーを捏造して言及することを防ぐ。
 * - implementation_difficulty/candidate_impactはlow/medium/highの3値以外を
 *   nullに丸める(AIが自由記述で返してきた場合に備える)。
 * - 文字数上限はBrandWheelImprovementFocusComposerのEVIDENCE_MAX_CHARSと
 *   同じ理由(PDF/Wordのレイアウト崩れ防止)。実PDF確認で最終調整する
 *   (docs/lead-report-layout/README.mdの検証方法論に従う)。
 */
class BrandWheelImprovementSuggestionResponseParser
{
    private const ONE_POINT_MAX_CHARS = 70;

    private const RECOMMENDATION_MAX_CHARS = 400;

    private const REASON_MAX_CHARS = 200;

    private const RECOMMENDED_CONTENT_MAX_CHARS = 45;

    private const RECOMMENDED_CONTENT_MAX_COUNT = 3;

    private const MID_TERM_ACTION_MAX_CHARS = 120;

    // 依頼AF-2(2026-08-27): reasonと同じ性質の自由記述(3文程度)のため同じ上限。
    private const FOCUS_ITEMS_REASON_MAX_CHARS = 200;

    /**
     * @var list<string>
     */
    private const VALID_LEVELS = ['low', 'medium', 'high'];

    public function parse(
        array $raw,
        string $provider,
        ?string $model,
        bool $isMock,
        ?string $promptVersion,
    ): BrandWheelImprovementSuggestionResult {
        $validSubElementKeys = $this->validSubElementKeys();

        return new BrandWheelImprovementSuggestionResult(
            onePoint: $this->parseForbiddenPhraseSafeText($raw['one_point'] ?? null, self::ONE_POINT_MAX_CHARS),
            recommendation: $this->parseForbiddenPhraseSafeText($raw['recommendation'] ?? null, self::RECOMMENDATION_MAX_CHARS),
            focusSubElementKeys: $this->parseSubElementKeyList($raw['focus_sub_element_keys'] ?? null, $validSubElementKeys),
            reason: $this->parseForbiddenPhraseSafeText($raw['reason'] ?? null, self::REASON_MAX_CHARS),
            recommendedContents: $this->parseTextList($raw['recommended_contents'] ?? null, self::RECOMMENDED_CONTENT_MAX_CHARS, self::RECOMMENDED_CONTENT_MAX_COUNT),
            midTermAction: $this->parseForbiddenPhraseSafeText($raw['mid_term_action'] ?? null, self::MID_TERM_ACTION_MAX_CHARS),
            quickWin: is_bool($raw['quick_win'] ?? null) ? $raw['quick_win'] : null,
            implementationDifficulty: $this->parseLevel($raw['implementation_difficulty'] ?? null),
            candidateImpact: $this->parseLevel($raw['candidate_impact'] ?? null),
            gapClosing: $this->parseSubElementKeyList($raw['gap_closing'] ?? null, $validSubElementKeys),
            differentiationOpportunities: $this->parseSubElementKeyList($raw['differentiation_opportunities'] ?? null, $validSubElementKeys),
            provider: $provider,
            model: $model,
            isMock: $isMock,
            promptVersion: $promptVersion,
            focusItemsReason: $this->parseForbiddenPhraseSafeText($raw['focus_items_reason'] ?? null, self::FOCUS_ITEMS_REASON_MAX_CHARS),
        );
    }

    /**
     * 2026-08-19: forbidden_phrases(「不足」等の否定的評価語)に加え、
     * assertive_phrases(サイト分析だけでは分からない社内事情を断定する
     * 表現)も同じ扱いでチェックする。プロンプト側の指示(条件付き表現を
     * 使わせる)が最初の防波堤、これはAIが指示に従わなかった場合の
     * 最後の防波堤(forbidden_phrasesと同じ二重構成)。
     *
     * 依頼S(2026-08-26): 値全体が"null"(大文字小文字を問わない、前後の
     * 空白を除いた完全一致)の場合もnullとして扱う ―― JSON Schemaは
     * ['string', 'null']を許可しているが、v7のプロンプト例
     * `"mid_term_action": "string または null"` がnullをクォートで囲んだ
     * 記法だったため、モデルが文字列"null"(4文字)を返す事故が実物
     * レポート37で発生した(v8でプロンプト側の例も修正済み、
     * OpenAiBrandWheelImprovementSuggestionProvider参照)。この
     * パーサ側の防御はプロンプト修正後も残す ―― 出力の信用しすぎを
     * 避ける既存方針(このクラスのdocblock参照)と同じ考え方のため。
     * 「値全体が一致する場合のみ」判定する ―― 本文中にたまたま"null"と
     * いう語が含まれる正当な文章(例:「値がnullのままでも問題ありません」
     * のような技術的な言及)まで捨てないため、str_contains()ではなく
     * 完全一致で判定する。
     */
    private function parseForbiddenPhraseSafeText(mixed $raw, int $maxChars): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $text = trim($raw);

        if (strtolower($text) === 'null') {
            return null;
        }

        $bannedPhrases = array_merge(
            (array) config('brand_wheel.forbidden_phrases', []),
            (array) config('brand_wheel.assertive_phrases', []),
        );

        foreach ($bannedPhrases as $phrase) {
            if (is_string($phrase) && $phrase !== '' && str_contains($text, $phrase)) {
                return null;
            }
        }

        return BrandWheelTextTruncator::truncateAtSentenceBoundary($text, $maxChars);
    }

    /**
     * recommended_contents用。禁止語を含む項目だけを個別に除外し、
     * 最大件数・各項目の文字数上限を適用する。
     *
     * @return list<string>
     */
    private function parseTextList(mixed $raw, int $maxCharsPerItem, int $maxCount): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $item) {
            $safe = $this->parseForbiddenPhraseSafeText($item, $maxCharsPerItem);

            if ($safe !== null) {
                $items[] = $safe;
            }
        }

        return array_slice($items, 0, $maxCount);
    }

    private function parseLevel(mixed $raw): ?string
    {
        return is_string($raw) && in_array($raw, self::VALID_LEVELS, true) ? $raw : null;
    }

    /**
     * @return list<string>
     */
    private function validSubElementKeys(): array
    {
        $validKeys = [];
        foreach ((array) config('brand_wheel.axes', []) as $axis) {
            $validKeys = array_merge($validKeys, array_keys((array) ($axis['sub_elements'] ?? [])));
        }

        return $validKeys;
    }

    /**
     * @param  list<string>  $validKeys
     * @return list<string>
     */
    private function parseSubElementKeyList(mixed $raw, array $validKeys): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_intersect(
            array_filter($raw, fn ($k) => is_string($k)),
            $validKeys,
        ));
    }
}
