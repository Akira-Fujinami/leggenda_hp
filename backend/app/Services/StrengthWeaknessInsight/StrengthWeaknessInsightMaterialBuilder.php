<?php

namespace App\Services\StrengthWeaknessInsight;

use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\ComparisonCommentary\ComparisonCommentaryMaterialBuilder;
use App\Services\SiteFirstImpression\SiteFirstImpressionMaterialBuilder;

/**
 * 依頼DB-2: 強み・弱みの下書きの材料(AIを使わない・通信しない。巡回・判定はやり直さない)。
 *
 *  1. 会社名と、比較に入っている全社の名前
 *  2. 24項目の判定(○／×。自社と競合全社)。項目名と定義
 *  3. 根拠の文: 自社が○の項目の自社の根拠の文 / 自社が×で競合が○の項目の競合の根拠の文(会社名つき)
 *  4. 自社の採用TOPの構造(PageStructureExtractor。描画済みのHTMLがあればそれを使う)
 *  5. 領域別の点数(比較表の数字)
 *
 * 判定の読み出しは、比較のスライドの数字と同じもの(ComparisonCommentaryMaterialBuilder)。自社の判定が成立していない・競合がいない・
 * 読み取れた記述が少ない会社がある・競合の判定が成立していないときは、材料を作らない(status=skipped、AIも呼ばない)。
 *
 * 材料の上限は config('strength_weakness_insight.material_max_chars')。超えたら、根拠の文を後ろから削る(競合の根拠の文 → 自社の根拠の文)。
 * サーバー側の確認(StrengthWeaknessInsightVerifier)のために、会社ごとの「引いてよい文字」(corpus)と、使ってよい数字(numbers)を
 * 持つ(プロンプトには載せない。削った根拠の文は corpus にも入れない)。
 */
class StrengthWeaknessInsightMaterialBuilder
{
    public function __construct(
        private readonly ComparisonCommentaryMaterialBuilder $base,
        private readonly SiteFirstImpressionMaterialBuilder $firstImpression,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Analysis $analysis): array
    {
        $analysis->loadMissing(['websiteAnalyses.website']);
        $selfWa = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);

        $base = $this->base->build($analysis);

        $structurePage = null;
        if (($base['status'] ?? null) === 'ok' && $selfWa !== null) {
            try {
                $structurePage = $this->firstImpression->build($selfWa)['pages']['recruit'] ?? null;
            } catch (\Throwable) {
                $structurePage = null;   // 構造が取れなくても、判定と根拠の文だけで書く
            }
        }

        return $this->fromBase($base, $structurePage, array_filter([(string) ($selfWa?->website?->name ?? '')]));
    }

    /**
     * @param  array<string, mixed>  $base  ComparisonCommentaryMaterialBuilder::build()の戻り値
     * @param  ?array<string, mixed>  $structurePage  SiteFirstImpressionMaterialBuilder の pages['recruit'](label・url・structure)
     * @param  list<string>  $extraSelfNames  自社の呼び名(自社サイトの名前など)
     * @return array<string, mixed>
     */
    public function fromBase(array $base, ?array $structurePage, array $extraSelfNames = []): array
    {
        if (($base['status'] ?? null) !== 'ok') {
            return $this->skipped((string) ($base['reason'] ?? 'unknown'), (string) ($base['self_name'] ?? ''));
        }

        $competitors = [];
        foreach ((array) $base['competitors'] as $competitor) {
            $competitors[] = ['label' => (string) $competitor['label'], 'name' => (string) $competitor['name'], 'variants' => $this->variants((string) $competitor['name'])];
        }

        $selfNames = [];
        foreach (array_merge([(string) $base['self_name']], array_values($extraSelfNames)) as $name) {
            array_push($selfNames, ...$this->variants($name));
        }

        $items = [];
        $selfEvidence = [];
        $competitorEvidence = [];
        foreach ((array) $base['items'] as $item) {
            $matched = [];
            foreach ((array) $item['competitors'] as $i => $c) {
                $matched[$i] = (bool) $c['matched'];
            }
            $items[] = [
                'key' => (string) $item['key'],
                'axis' => (string) $item['axis'],
                'name' => (string) $item['name'],
                'definition' => $this->definition((string) $item['key']),
                'self' => (bool) $item['self_matched'],
                'competitors' => $matched,
            ];
            if ($item['self_matched'] && trim((string) $item['self_evidence']) !== '') {
                $selfEvidence[] = ['axis' => (string) $item['axis'], 'name' => (string) $item['name'], 'text' => trim((string) $item['self_evidence'])];
            } elseif (! $item['self_matched']) {
                foreach ((array) $item['competitors'] as $i => $c) {
                    if ($c['matched'] && trim((string) $c['evidence']) !== '') {
                        $competitorEvidence[] = ['axis' => (string) $item['axis'], 'name' => (string) $item['name'], 'company_index' => $i, 'company' => $competitors[$i]['name'], 'text' => trim((string) $c['evidence'])];
                    }
                }
            }
        }

        $structure = $structurePage['structure'] ?? null;
        $structureTexts = $structure !== null ? array_values((array) ($structure['elements'] ?? [])) : [];
        $structureChars = array_sum(array_map('mb_strlen', $structureTexts));

        // 上限を超えたら、根拠の文を後ろから削る(競合の根拠の文 → 自社の根拠の文)。
        $limit = (int) config('strength_weakness_insight.material_max_chars');
        $count = fn (array $self, array $competitor) => $structureChars + array_sum(array_map(fn (array $r) => mb_strlen($r['text']), $self)) + array_sum(array_map(fn (array $r) => mb_strlen($r['text']), $competitor));
        $trimmed = 0;
        while ($limit > 0 && $count($selfEvidence, $competitorEvidence) > $limit && ($competitorEvidence !== [] || $selfEvidence !== [])) {
            if ($competitorEvidence !== []) {
                array_pop($competitorEvidence);
            } else {
                array_pop($selfEvidence);
            }
            $trimmed++;
        }

        // 引いてよい文字(会社ごと)。自社 = 自社の根拠の文 + 採用TOPの構造の文字。競合 = その会社の根拠の文。
        $corpus = ['self' => array_column($selfEvidence, 'text') + [], 'competitors' => array_fill(0, count($competitors), [])];
        array_push($corpus['self'], ...$structureTexts);
        foreach ($competitorEvidence as $row) {
            $corpus['competitors'][$row['company_index']][] = $row['text'];
        }

        $visibleStructure = $structurePage !== null && $structure !== null ? $this->visibleStructure($structure) : null;

        return [
            'status' => 'ok',
            'reason' => null,
            'self_name' => (string) $base['self_name'],
            'self_names' => array_values(array_unique($selfNames)),
            'competitors' => $competitors,
            'axis_scores' => (array) $base['axis_scores'],
            'totals' => (array) $base['totals'],
            'items' => $items,
            'self_evidence' => $selfEvidence,
            'competitor_evidence' => $competitorEvidence,
            'structure' => $visibleStructure,
            'corpus' => $corpus,
            'numbers' => $this->numbers($base, $items, count($competitors), $structure, array_merge(array_column($selfEvidence, 'text'), array_column($competitorEvidence, 'text'), $structureTexts)),
            'evidence_trimmed' => $trimmed,
        ];
    }

    /**
     * 会社名と、法人格を除いた言い換え(2文字以上のものだけ)。
     *
     * @return list<string>
     */
    public function variants(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $stripped = $name;
        foreach ((array) config('strength_weakness_insight.company_legal_forms', []) as $form) {
            $stripped = str_replace((string) $form, '', $stripped);
        }
        $stripped = trim((string) preg_replace('/\s+/u', '', $stripped));

        return array_values(array_unique(array_filter([$name, $stripped], fn (string $v) => mb_strlen($v) >= 2)));
    }

    /**
     * 文に出してよい数字: 点数・分母・合計・社数・項目数と、材料の文字に出る数字、構造の順番・項目数。
     *
     * @param  array<string, mixed>  $base
     * @param  list<array<string, mixed>>  $items
     * @param  ?array<string, mixed>  $structure
     * @param  list<string>  $texts  プロンプトに載せた文字(根拠の文・構造の文字)
     * @return list<string>
     */
    private function numbers(array $base, array $items, int $competitorCount, ?array $structure, array $texts): array
    {
        $numbers = [$competitorCount, $competitorCount + 1, count($items)];
        foreach ((array) $base['axis_scores'] as $row) {
            $numbers[] = $row['self'];
            $numbers[] = $row['denominator'];
            array_push($numbers, ...(array) $row['competitors']);
        }
        $numbers[] = $base['totals']['self'] ?? 0;
        array_push($numbers, ...(array) ($base['totals']['competitors'] ?? []));
        // 軸ごとの○の数(1社ぶん)の最大は分母。項目ごとの○の社数も、文に出てよい。
        foreach ($items as $item) {
            $numbers[] = count(array_filter($item['competitors'])) + ($item['self'] ? 1 : 0);
            $numbers[] = count(array_filter($item['competitors']));
        }

        if ($structure !== null) {
            $numbers[] = count((array) ($structure['nav'] ?? []));
            $numbers[] = count((array) ($structure['hero'] ?? []));
            $numbers[] = count((array) ($structure['sections'] ?? []));
            $numbers[] = (int) ($structure['counts']['items'] ?? 0);
            // 「N番目」のような順番: 並びの長さまで
            foreach (range(1, max(count((array) ($structure['nav'] ?? [])), 1)) as $n) {
                $numbers[] = $n;
            }
            foreach ((array) ($structure['sections'] ?? []) as $i => $section) {
                $numbers[] = $i + 1;
                $numbers[] = (int) ($section['item_count'] ?? 0);
                foreach (range(1, max(count((array) ($section['items'] ?? [])), 1)) as $n) {
                    $numbers[] = $n;
                }
            }
            foreach ((array) ($structure['recruit_link'] ?? []) as $value) {
                if (is_int($value)) {
                    $numbers[] = $value;
                }
            }
        }

        $normalized = array_map(fn ($n) => (string) $n, $numbers);
        foreach ($texts as $text) {
            array_push($normalized, ...self::extractNumbers($text));
        }

        return array_values(array_unique($normalized));
    }

    /**
     * 文字に出る数字(全角は半角にそろえ、桁区切りのカンマを除く)。
     *
     * @return list<string>
     */
    public static function extractNumbers(string $text): array
    {
        $text = mb_convert_kana($text, 'n');
        preg_match_all('/\d+(?:[.,]\d+)*/u', $text, $m);

        return array_values(array_unique(array_map(fn (string $n) => self::canonicalNumber($n), $m[0])));
    }

    public static function canonicalNumber(string $number): string
    {
        $value = (float) str_replace(',', '', $number);
        $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /**
     * プロンプトに載せる構造(確認用の項目・数字は含めない)。
     *
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    private function visibleStructure(array $structure): array
    {
        $max = (int) config('strength_weakness_insight.structure_text_max_chars');
        $cut = fn (string $text) => $max > 0 && mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
        $structure = array_diff_key($structure, ['elements' => 1, 'numbers' => 1, 'prefix' => 1]);
        foreach (['nav', 'hero'] as $key) {
            $structure[$key] = array_map(fn (array $row) => ['text' => $cut((string) $row['text'])] + $row, (array) ($structure[$key] ?? []));
        }

        return $structure;
    }

    private function definition(string $subKey): string
    {
        foreach ((array) config('brand_wheel.axes') as $axis) {
            if (isset($axis['sub_element_definitions'][$subKey])) {
                return (string) $axis['sub_element_definitions'][$subKey];
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function skipped(string $reason, string $selfName): array
    {
        return ['status' => 'skipped', 'reason' => $reason, 'self_name' => $selfName, 'self_names' => [], 'competitors' => [], 'axis_scores' => [], 'totals' => [], 'items' => [], 'self_evidence' => [], 'competitor_evidence' => [], 'structure' => null, 'corpus' => ['self' => [], 'competitors' => []], 'numbers' => [], 'evidence_trimmed' => 0];
    }
}
