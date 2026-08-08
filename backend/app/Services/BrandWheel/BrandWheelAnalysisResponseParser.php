<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use App\Services\BrandWheel\Data\BrandWheelAnalysisResult;
use App\Services\BrandWheel\Data\BrandWheelAxisResult;
use App\Services\BrandWheel\Data\BrandWheelCoreValueResult;
use App\Services\BrandWheel\Data\BrandWheelDiscardedSubElement;
use App\Services\BrandWheel\Data\BrandWheelSubElementMatch;
use Illuminate\Support\Facades\Log;

/**
 * AI Providerが返したJSON(デコード済み連想配列)を検証し、BrandWheelAnalysisResultへ
 * 変換する。AiAnalysisResponseParserの「AIの出力はそのまま信用しない」方針を
 * さらに一段階厳格にしたもの:
 *
 * - 各evidenceは、実際にBrandWheelAnalysisInputへ渡した原文(採用ページ/
 *   トップページの本文・見出し・ナビゲーションラベル)に正規化後の部分一致で
 *   実在するかを検証する。実在しない場合はその下位要素キーだけを破棄する
 *   (軸全体は破棄しない)。
 * - state(unread/partial/read)はAIに一切出力させない。検証を生き延びた
 *   matched_sub_elementsの件数から、config('brand_wheel.state_thresholds')に
 *   従ってこのクラスが計算する(overrides[axis] ?? default)。
 * - 破棄した下位要素は無言で捨てず、reason付きでdiscardedSubElementsに残す。
 * - 2026-08-05: raw['sub_elements']はconfigの24キーをトップレベルに固定した
 *   フラット形式(prompt_version v4〜)。config側の24キーを1つずつ引き当てる
 *   方式に変更した(AIが申告したキーを検証する従来方式から、config側から
 *   引き当てる方式へ ―― Structured Outputs(strict)によりAI側はconfig外の
 *   キーを構造的に返せなくなったため、unknown_sub_elementは新規には発生しない。
 *   値の定義自体は本番DBの既存行(v3以前生成分)のため削除しない、
 *   BrandWheelDiscardedSubElement参照)。
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
        $headingSet = $this->buildHeadingSet($input);
        $linkLabelSet = $this->buildLinkLabelSet($input);

        $axesConfig = (array) config('brand_wheel.axes', []);
        $rawSubElements = is_array($raw['sub_elements'] ?? null) ? $raw['sub_elements'] : [];

        $this->guardAgainstIncompleteSchema($rawSubElements, $axesConfig);

        // 1段階目: 軸ごとに(empty_evidence/evidence_not_found/label_only_evidence の)
        // 検証を行うが、stateはまだ計算しない ―― 2段階目の軸をまたいだ重複evidence
        // 除去でmatched件数が変わりうるため、state計算はその後に行う必要がある
        // (2026-08-04のユーザー指摘: 同一文が異なる2つの下位要素の根拠として
        // 二重計上されるケースを、軸をまたいで検出・除去する)。
        $axisDrafts = [];
        foreach ($axesConfig as $axisKey => $axisDefinition) {
            $subElementKeys = array_keys((array) ($axisDefinition['sub_elements'] ?? []));

            $axisDrafts[$axisKey] = $this->parseAxisDraft($rawSubElements, $subElementKeys, $haystack, $headingSet, $linkLabelSet);
        }

        $axisDrafts = $this->deduplicateEvidenceAcrossAxes($axisDrafts, $axesConfig);

        $axisResults = [];
        foreach ($axisDrafts as $axisKey => $draft) {
            $axisResults[] = new BrandWheelAxisResult(
                axisKey: $axisKey,
                matchedSubElements: $draft['matched'],
                discardedSubElements: $draft['discarded'],
                claimedSubElementCount: $draft['claimed'],
                state: $this->computeState($axisKey, count($draft['matched'])),
            );
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
     * config('brand_wheel.axes')側から24キーを1つずつ引き当てる方式(2026-08-05〜)。
     * AIが申告したキーを検証するのではなく、config側の正となるキー集合を
     * 起点にrawSubElementsを引く ―― Structured Outputs(strict)によりAI側は
     * config外のキーを構造的に返せないため、この向きで安全に成立する。
     *
     * @param  array<string, mixed>  $rawSubElements  raw['sub_elements'](24キーのフラット辞書)
     * @param  list<string>  $subElementKeys  この軸に属する下位要素キー
     * @param  array<string, true>  $headingSet  正規化済みの見出し文字列の集合
     * @param  array<string, true>  $linkLabelSet  正規化済みのリンクラベル文字列の集合
     * @return array{matched: list<BrandWheelSubElementMatch>, discarded: list<BrandWheelDiscardedSubElement>, claimed: int}
     */
    private function parseAxisDraft(array $rawSubElements, array $subElementKeys, string $haystack, array $headingSet, array $linkLabelSet): array
    {
        $matched = [];
        $discarded = [];
        $claimedCount = 0;

        foreach ($subElementKeys as $key) {
            $entry = $rawSubElements[$key] ?? null;

            if (! is_array($entry) || ($entry['matched'] ?? null) !== true) {
                continue;
            }

            $evidence = $entry['evidence'] ?? null;

            if (! is_string($evidence) || trim($evidence) === '') {
                $discarded[] = new BrandWheelDiscardedSubElement($key, is_string($evidence) ? $evidence : null, 'empty_evidence');

                continue;
            }

            $evidence = trim($evidence);
            $claimedCount++;

            $normalizedEvidence = $this->normalizeForEvidenceMatch($evidence);

            if (! str_contains($haystack, $normalizedEvidence)) {
                $discarded[] = new BrandWheelDiscardedSubElement($key, $evidence, 'evidence_not_found');

                continue;
            }

            // 2026-08-05の指摘: evidenceが原文に実在する(=evidence_not_foundは
            // 通過する)だけでは不十分 ―― 見出し・リンクラベル文字列そのものを
            // 一字一句コピーしただけの「その単語がページにある」を「それに
            // ついて書かれている」証拠にすり替える循環論法が実測で確認された
            // (味の素の実測でsub_elements形式化後にmatched件数が9件→6件が
            // 見出しラベル単体という結果になった)。
            //
            // リンクラベルと見出しで条件を分ける(2026-08-05の閾値設計修正):
            // リンクラベルは定義上ナビゲーションであり、長さに関わらず根拠に
            // ならない ―― 完全一致すれば長さを問わず破棄する(「DE&I
            // （ダイバーシティ・エクイティ＆インクルージョン）」28文字が
            // 20文字閾値の抜け道として生き残った実測への対応)。
            // 見出しは20文字以上の完全一致なら本物の文章であることがあるため
            // (本文中の正当な短文、例:「互いに認め合い、高め合うこと」14文字を
            // 誤って弾かないための下限でもある)、20文字未満の場合のみ破棄する。
            if (isset($linkLabelSet[$normalizedEvidence])) {
                $discarded[] = new BrandWheelDiscardedSubElement($key, $evidence, 'label_only_evidence');

                continue;
            }

            if (isset($headingSet[$normalizedEvidence]) && mb_strlen($evidence) < 20) {
                $discarded[] = new BrandWheelDiscardedSubElement($key, $evidence, 'label_only_evidence');

                continue;
            }

            $matched[] = new BrandWheelSubElementMatch($key, $evidence);
        }

        return ['matched' => $matched, 'discarded' => $discarded, 'claimed' => $claimedCount];
    }

    /**
     * 24キーのうち1つ欠けただけで診断が丸ごと失敗する設計にはしない
     * (3-3の縮退方針)。欠落キーはmatched:false扱いとしてparseAxisDraft()側で
     * 自然に処理されるため、ここでは欠落キー名をLog::warningへ記録するのみ。
     * 欠落が24の1/4(6個)を超えたときのみ、AI_INCOMPLETE_SCHEMAとして例外を
     * 投げる ―― response_formatをjson_schema(strict:true)化したことで、この
     * 分岐は実際にはほぼ発火しない保険になる。
     *
     * @param  array<string, mixed>  $rawSubElements
     * @param  array<string, mixed>  $axesConfig
     */
    private function guardAgainstIncompleteSchema(array $rawSubElements, array $axesConfig): void
    {
        $allSubElementKeys = [];
        foreach ($axesConfig as $axisDefinition) {
            $allSubElementKeys = array_merge($allSubElementKeys, array_keys((array) ($axisDefinition['sub_elements'] ?? [])));
        }

        $missingKeys = array_values(array_filter(
            $allSubElementKeys,
            fn (string $key) => ! is_array($rawSubElements[$key] ?? null) || ! is_bool($rawSubElements[$key]['matched'] ?? null),
        ));

        if ($missingKeys === []) {
            return;
        }

        Log::warning('Brand wheel analysis: missing or malformed sub_element keys in AI response', [
            'missing_keys' => $missingKeys,
        ]);

        if (count($missingKeys) > 6) {
            throw new BrandWheelAnalysisException(
                'AI_INCOMPLETE_SCHEMA',
                sprintf('AIの応答からsub_elementsのキーが%d個欠落しています(24キー中、閾値6個を超過)。', count($missingKeys)),
            );
        }
    }

    /**
     * 軸をまたいで同一のevidence文字列(正規化後)が複数の下位要素の根拠として
     * 使われている場合、1件だけを残し残りをduplicate_evidenceとして破棄する。
     * 2026-08-04の実測(gpt-5.6-terra)で、「正解のない仕事をやり抜く鍵は、
     * 互いに認め合い、高め合うこと」という同一の1文がcore_valuesとcolleagues
     * の両方の根拠として二重計上される事例が確認されたため。
     *
     * どちらを残すかは「config('brand_wheel.axes')の並び順→各軸内の
     * sub_elementsの並び順」で先に来るほうを残す、という決定的なルールに
     * 固定する(モデル・実行順に依存させない ―― AIが返したJSON内の配列順は
     * 決定的ではないため、判定基準にしてはならない)。
     *
     * @param  array<string, array{matched: list<BrandWheelSubElementMatch>, discarded: list<BrandWheelDiscardedSubElement>, claimed: int}>  $axisDrafts
     * @param  array<string, mixed>  $axesConfig
     * @return array<string, array{matched: list<BrandWheelSubElementMatch>, discarded: list<BrandWheelDiscardedSubElement>, claimed: int}>
     */
    private function deduplicateEvidenceAcrossAxes(array $axisDrafts, array $axesConfig): array
    {
        $axisOrder = array_values(array_keys($axesConfig));

        // 正規化後のevidence文字列ごとに、それを根拠としているmatched項目を
        // 軸をまたいで集約する。
        $groupsByEvidence = [];
        foreach ($axisOrder as $axisRank => $axisKey) {
            $subElementOrder = array_values(array_keys((array) ($axesConfig[$axisKey]['sub_elements'] ?? [])));

            foreach ($axisDrafts[$axisKey]['matched'] as $match) {
                $normalized = $this->normalizeForEvidenceMatch($match->evidence);
                $subRank = array_search($match->key, $subElementOrder, true);

                $groupsByEvidence[$normalized][] = [
                    'axisKey' => $axisKey,
                    'match' => $match,
                    'rank' => [$axisRank, $subRank === false ? PHP_INT_MAX : $subRank],
                ];
            }
        }

        $duplicatesToDiscard = []; // axisKey => list<BrandWheelSubElementMatch>
        foreach ($groupsByEvidence as $entries) {
            if (count($entries) <= 1) {
                continue;
            }

            usort($entries, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);
            array_shift($entries); // 先頭(config順で最も早いもの)を残し、残りを破棄対象にする

            foreach ($entries as $entry) {
                $duplicatesToDiscard[$entry['axisKey']][] = $entry['match'];
            }
        }

        if ($duplicatesToDiscard === []) {
            return $axisDrafts;
        }

        foreach ($duplicatesToDiscard as $axisKey => $matchesToRemove) {
            $removedKeys = array_map(fn (BrandWheelSubElementMatch $m) => $m->key, $matchesToRemove);

            $axisDrafts[$axisKey]['matched'] = array_values(array_filter(
                $axisDrafts[$axisKey]['matched'],
                fn (BrandWheelSubElementMatch $m) => ! in_array($m->key, $removedKeys, true),
            ));

            foreach ($matchesToRemove as $m) {
                $axisDrafts[$axisKey]['discarded'][] = new BrandWheelDiscardedSubElement($m->key, $m->evidence, 'duplicate_evidence');
            }
        }

        return $axisDrafts;
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
     * label_only_evidence判定用に、見出しの個々の文字列(正規化済み)を集合
     * として持つ。buildSourceText()は照合対象を1本の文字列に連結するため
     * 個々の見出し境界が失われる ―― ここでは逆に、見出し「だけ」を個別の
     * 要素として保持し、evidenceがそのいずれかと完全一致するかを判定できる
     * ようにする(本文中の部分一致は対象外。本文の中に偶然見出しと同じ短い句が
     * 含まれる場合まで破棄しない)。
     *
     * リンクラベル(buildLinkLabelSet()参照)とは別集合にする(2026-08-05の
     * 閾値設計修正) ―― 見出しは20文字以上の完全一致なら本物の文章のことが
     * あるが、リンクラベルは定義上ナビゲーションであり長さに関わらず根拠に
     * ならない。「種類で分ける」ため、判定側(parseAxisDraft())で別々の
     * 長さ条件を適用できるよう、集合自体を分けて持つ。
     *
     * @return array<string, true>
     */
    private function buildHeadingSet(BrandWheelAnalysisInput $input): array
    {
        $headings = array_merge(
            array_map(fn (array $h) => $h['text'], $input->recruitPageHeadings),
            array_map(fn (array $h) => $h['text'], $input->homepageHeadings),
        );

        return $this->normalizeToSet($headings);
    }

    /**
     * label_only_evidence判定用に、リンクラベルの個々の文字列(正規化済み)を
     * 集合として持つ(buildHeadingSet()参照、見出しとは別集合)。
     * businessLinkLabels(header/nav/footerタグ配下限定)に加えallLinkLabels
     * (ページ内の全リンク、タグの意味に依存しない)も含める ―― 2026-08-05の
     * 実測(味の素)で、`<div class="gnav_wrap">`のようにセマンティックタグを
     * 使わずナビゲーションを実装しているサイトのリンクラベルがbusinessLinkLabels
     * に含まれず、label_only_evidenceが素通りする事例が確認されたため。
     *
     * @return array<string, true>
     */
    private function buildLinkLabelSet(BrandWheelAnalysisInput $input): array
    {
        return $this->normalizeToSet(array_merge(
            $input->businessLinkLabels,
            $input->allLinkLabels,
        ));
    }

    /**
     * @param  list<mixed>  $texts
     * @return array<string, true>
     */
    private function normalizeToSet(array $texts): array
    {
        $set = [];
        foreach ($texts as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $set[$this->normalizeForEvidenceMatch(trim($text))] = true;
        }

        return $set;
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
