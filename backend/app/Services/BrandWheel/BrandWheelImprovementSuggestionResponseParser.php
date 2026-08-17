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
        );
    }

    private function parseForbiddenPhraseSafeText(mixed $raw, int $maxChars): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $text = trim($raw);
        $forbiddenPhrases = (array) config('brand_wheel.forbidden_phrases', []);

        foreach ($forbiddenPhrases as $phrase) {
            if (is_string($phrase) && $phrase !== '' && str_contains($text, $phrase)) {
                return null;
            }
        }

        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars).'…' : $text;
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
