<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use App\Services\BrandWheel\Data\BrandWheelAnalysisResult;
use App\Services\BrandWheel\Data\BrandWheelAxisResult;
use App\Services\BrandWheel\Data\BrandWheelCoreValueResult;
use App\Services\BrandWheel\Data\BrandWheelDiscardedSubElement;
use App\Services\BrandWheel\Data\BrandWheelSubElementMatch;

/**
 * AI Providerが返したJSON(デコード済み連想配列)を検証し、BrandWheelAnalysisResultへ
 * 変換する。AiAnalysisResponseParserの「AIの出力はそのまま信用しない」方針を
 * さらに一段階厳格にしたもの:
 *
 * - matched_sub_elementsの各evidenceは、実際にBrandWheelAnalysisInputへ渡した
 *   原文(採用ページ/トップページの本文・見出し・ナビゲーションラベル)に
 *   正規化後の部分一致で実在するかを検証する。実在しない場合はその下位要素
 *   キーだけを破棄する(軸全体は破棄しない)。
 * - state(unread/partial/read)はAIに一切出力させない。検証を生き延びた
 *   matched_sub_elementsの件数から、config('brand_wheel.state_thresholds')に
 *   従ってこのクラスが計算する(overrides[axis] ?? default)。
 * - 破棄した下位要素は無言で捨てず、reason付きでdiscardedSubElementsに残す。
 */
class BrandWheelAnalysisResponseParser
{
    public function parse(
        array $raw,
        BrandWheelAnalysisInput $input,
        string $provider,
        ?string $model,
        bool $isMock,
        ?string $promptVersion,
    ): BrandWheelAnalysisResult {
        $haystack = $this->normalizeForEvidenceMatch($this->buildSourceText($input));

        $axesConfig = (array) config('brand_wheel.axes', []);
        $rawAxes = is_array($raw['axes'] ?? null) ? $raw['axes'] : [];

        $axisResults = [];
        foreach ($axesConfig as $axisKey => $axisDefinition) {
            $validSubElementKeys = array_keys((array) ($axisDefinition['sub_elements'] ?? []));
            $rawAxis = is_array($rawAxes[$axisKey] ?? null) ? $rawAxes[$axisKey] : [];

            $axisResults[] = $this->parseAxis($axisKey, $rawAxis, $validSubElementKeys, $haystack);
        }

        $axisStateCounts = [
            'read' => count(array_filter($axisResults, fn (BrandWheelAxisResult $a) => $a->state === 'read')),
            'partial' => count(array_filter($axisResults, fn (BrandWheelAxisResult $a) => $a->state === 'partial')),
            'unread' => count(array_filter($axisResults, fn (BrandWheelAxisResult $a) => $a->state === 'unread')),
        ];

        return new BrandWheelAnalysisResult(
            axes: $axisResults,
            coreValue: $this->parseCoreValue($raw['core_value'] ?? null, $haystack),
            keyMessage: $this->parseForbiddenPhraseSafeText($raw['key_message'] ?? null),
            impression: $this->parseForbiddenPhraseSafeText($raw['impression'] ?? null),
            qualityDimensionNotes: $this->parseQualityDimensionNotes($raw['quality_notes'] ?? []),
            cautions: $this->parseStringList($raw['cautions'] ?? []),
            axisStateCounts: $axisStateCounts,
            provider: $provider,
            model: $model,
            isMock: $isMock,
            promptVersion: $promptVersion,
        );
    }

    /**
     * key_message/impressionは下位要素のような「原文にこの語句があるか」という
     * 個別の主張ではなく、サイト全体から読み取れる要約・印象のためevidence実在
     * 検証の対象外とする。ただしimpressionはリード向け画面に表示される社外向け
     * 文章のため、config('brand_wheel.forbidden_phrases')を含む場合は
     * (プロンプト側の指示だけに頼らず)ここでnullにする ―― AIの出力を
     * 無条件に信用しないという既存方針を、evidence検証とは別の形でここにも
     * 適用する(2026-08-03のユーザー指摘)。key_messageも同じ画面に表示される
     * ため、安全側に倒して同じ検証を適用する。
     */
    private function parseForbiddenPhraseSafeText(mixed $raw): ?string
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

        return $text;
    }

    /**
     * @param  array<string, mixed>  $rawAxis
     * @param  list<string>  $validSubElementKeys
     */
    private function parseAxis(string $axisKey, array $rawAxis, array $validSubElementKeys, string $haystack): BrandWheelAxisResult
    {
        $items = is_array($rawAxis['matched_sub_elements'] ?? null) ? $rawAxis['matched_sub_elements'] : [];

        $matched = [];
        $discarded = [];
        $claimedCount = 0;
        $seenKeys = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $item['key'] ?? null;
            $evidence = $item['evidence'] ?? null;

            if (! is_string($key) || ! in_array($key, $validSubElementKeys, true)) {
                $discarded[] = new BrandWheelDiscardedSubElement(
                    key: is_string($key) ? $key : '(invalid)',
                    evidence: is_string($evidence) ? $evidence : null,
                    reason: 'unknown_sub_element',
                );

                continue;
            }

            if (isset($seenKeys[$key])) {
                // AIが同じキーを複数回申告した場合は、最初の1件のみを扱う
                // (破棄理由付きの記録は「新しい情報の欠落」ではないため作らない)。
                continue;
            }
            $seenKeys[$key] = true;

            if (! is_string($evidence) || trim($evidence) === '') {
                $discarded[] = new BrandWheelDiscardedSubElement($key, is_string($evidence) ? $evidence : null, 'empty_evidence');

                continue;
            }

            $evidence = trim($evidence);
            $claimedCount++;

            if (! str_contains($haystack, $this->normalizeForEvidenceMatch($evidence))) {
                $discarded[] = new BrandWheelDiscardedSubElement($key, $evidence, 'evidence_not_found');

                continue;
            }

            $matched[] = new BrandWheelSubElementMatch($key, $evidence);
        }

        return new BrandWheelAxisResult(
            axisKey: $axisKey,
            matchedSubElements: $matched,
            discardedSubElements: $discarded,
            claimedSubElementCount: $claimedCount,
            state: $this->computeState($axisKey, count($matched)),
        );
    }

    private function computeState(string $axisKey, int $survivingCount): string
    {
        $overrides = (array) config('brand_wheel.state_thresholds.overrides', []);
        $default = (array) config('brand_wheel.state_thresholds.default', ['partial' => 1, 'read' => 2]);
        $thresholds = is_array($overrides[$axisKey] ?? null) ? $overrides[$axisKey] : $default;

        $readThreshold = (int) ($thresholds['read'] ?? $default['read']);
        $partialThreshold = (int) ($thresholds['partial'] ?? $default['partial']);

        if ($survivingCount >= $readThreshold) {
            return 'read';
        }

        if ($survivingCount >= $partialThreshold) {
            return 'partial';
        }

        return 'unread';
    }

    private function parseCoreValue(mixed $raw, string $haystack): BrandWheelCoreValueResult
    {
        if (! is_array($raw)) {
            return new BrandWheelCoreValueResult(readable: false, evidence: null);
        }

        $claimedReadable = ($raw['readable'] ?? false) === true;
        $evidence = $raw['evidence'] ?? null;

        if (! $claimedReadable || ! is_string($evidence) || trim($evidence) === '') {
            return new BrandWheelCoreValueResult(readable: false, evidence: null);
        }

        $evidence = trim($evidence);

        if (! str_contains($haystack, $this->normalizeForEvidenceMatch($evidence))) {
            return new BrandWheelCoreValueResult(readable: false, evidence: null);
        }

        return new BrandWheelCoreValueResult(readable: true, evidence: $evidence);
    }

    /**
     * @return array<string, string>
     */
    private function parseQualityDimensionNotes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $dimensionKeys = array_keys((array) config('brand_wheel.quality_dimensions', []));
        $notes = [];

        foreach ($dimensionKeys as $key) {
            $note = $raw[$key] ?? null;

            if (is_string($note) && trim($note) !== '') {
                $notes[$key] = trim($note);
            }
        }

        return $notes;
    }

    /**
     * @return list<string>
     */
    private function parseStringList(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($i) => is_string($i) ? trim($i) : null, $items)));
    }

    private function buildSourceText(BrandWheelAnalysisInput $input): string
    {
        $headingTexts = fn (array $headings) => implode("\n", array_map(fn (array $h) => $h['text'], $headings));

        return implode("\n", [
            (string) $input->recruitPageTitle,
            $input->recruitPageBodyText,
            $headingTexts($input->recruitPageHeadings),
            (string) $input->homepageTitle,
            $input->homepageBodyText,
            $headingTexts($input->homepageHeadings),
            implode("\n", $input->businessLinkLabels),
        ]);
    }

    /**
     * evidence検証専用の正規化。空白の除去・全角半角の統一・引用符/ダッシュの
     * 統一のみを行い、それ以上は緩めない(句読点除去や部分文字列の再分割は
     * しない)。緩い正規化はAIの言い換えを「実在する記述」として通してしまう。
     */
    private function normalizeForEvidenceMatch(string $text): string
    {
        // 半角カタカナ→全角、全角英数記号→半角、に統一する(mb_convert_kanaの
        // K=半角かな→全角, V=濁点結合修正, a=全角英数記号→半角)。
        $text = mb_convert_kana($text, 'KVa', 'UTF-8');

        // 空白は単一化ではなく完全に除去する(改行・タブ・全角空白含む)。
        $text = preg_replace('/\s+/u', '', $text) ?? $text;

        // 引用符・ダッシュ類の表記ゆれのみ統一する。日本語の長音記号(ー)は
        // 単語の一部として意味を持つため対象に含めない(過剰な正規化を避ける)。
        $text = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2212}", "\u{2015}"],
            ["'", "'", '"', '"', '-', '-', '-', '-'],
            $text,
        );

        return $text;
    }
}
