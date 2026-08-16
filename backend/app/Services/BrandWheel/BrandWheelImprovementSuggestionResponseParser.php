<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionResult;

/**
 * 改善提案AIが返したJSON(デコード済み連想配列)を検証し、
 * BrandWheelImprovementSuggestionResultへ変換する。
 * BrandWheelAnalysisResponseParserと同じ「AIの出力はそのまま信用しない」方針:
 *
 * - one_point/recommendationはforbidden_phrasesチェックを通過しない場合null
 *   にする(社外に出る文章のため、key_message/impressionと同じ扱い)。
 * - focus_sub_element_keysは実在する24キー(config('brand_wheel.axes')由来)
 *   以外を除外する ―― AIが実在しない項目キーを捏造して言及することを防ぐ
 *   (UI表示には使わないが、将来的なトレーサビリティ・検証用に残す)。
 * - 文字数上限(ONE_POINT_MAX_CHARS/RECOMMENDATION_MAX_CHARS)は
 *   BrandWheelImprovementFocusComposerのEVIDENCE_MAX_CHARSと同じ理由
 *   (PDF/Wordのレイアウト崩れ防止)。実PDF確認で最終調整する
 *   (docs/lead-report-layout/README.mdの検証方法論に従う)。
 */
class BrandWheelImprovementSuggestionResponseParser
{
    private const ONE_POINT_MAX_CHARS = 70;

    private const RECOMMENDATION_MAX_CHARS = 400;

    public function parse(
        array $raw,
        string $provider,
        ?string $model,
        bool $isMock,
        ?string $promptVersion,
    ): BrandWheelImprovementSuggestionResult {
        return new BrandWheelImprovementSuggestionResult(
            onePoint: $this->parseForbiddenPhraseSafeText($raw['one_point'] ?? null, self::ONE_POINT_MAX_CHARS),
            recommendation: $this->parseForbiddenPhraseSafeText($raw['recommendation'] ?? null, self::RECOMMENDATION_MAX_CHARS),
            focusSubElementKeys: $this->parseFocusSubElementKeys($raw['focus_sub_element_keys'] ?? null),
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
     * @return list<string>
     */
    private function parseFocusSubElementKeys(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $validKeys = [];
        foreach ((array) config('brand_wheel.axes', []) as $axis) {
            $validKeys = array_merge($validKeys, array_keys((array) ($axis['sub_elements'] ?? [])));
        }

        return array_values(array_intersect(
            array_filter($raw, fn ($k) => is_string($k)),
            $validKeys,
        ));
    }
}
