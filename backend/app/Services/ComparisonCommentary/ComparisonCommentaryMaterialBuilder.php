<?php

namespace App\Services\ComparisonCommentary;

use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelEvidenceLookupBuilder;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use App\Services\BrandWheel\BrandWheelMaterialSufficiency;

/**
 * 依頼CU-1: 自社と競合の24項目の ○／× と、○ の根拠の文から、強み・弱みの一文の「材料」を作る(AIを使わない)。
 *
 *  - 自社だけの強み : 自社が○で、競合のうち○が半数未満の項目
 *  - 自社の穴       : 自社が×で、競合のうち○が半数以上の項目(○の競合の名前と、その根拠の文を添える)
 *  - 差がつかない領域: 全社が同じ点数の領域
 *
 * 読み出し口は、比較のスライドの数字と同じもの(各WebsiteAnalysisの最新のBrandWheelAnalysisResult。
 * ○ = axes[].matched_sub_elements[]、根拠の文はBrandWheelEvidenceLookupBuilder)。保存されている根拠の文は、
 * 判定の段階(BrandWheelAnalysisResponseParser)で原文との突き合わせを通ったものだけ。
 *
 * 自社の判定が成立していない・競合がいない・読み取れた記述が少ない会社(依頼CH)がある・競合の判定が成立していない
 * ときは、材料を作らない(status=skipped、AIも呼ばない)。依頼CT-1と同じく、強み・弱みを出さない/CT-1に任せる。
 *
 * 閾値・上限・文言はすべて config('comparison_commentary.*')。
 */
class ComparisonCommentaryMaterialBuilder
{
    public function __construct(
        private readonly BrandWheelLeadResponseComposer $composer,
        private readonly BrandWheelEvidenceLookupBuilder $evidenceLookup,
        private readonly BrandWheelMaterialSufficiency $sufficiency,
    ) {}

    /**
     * @return array<string, mixed>
     *   status: 'ok'|'skipped', reason: ?string,
     *   self_name, competitors: list<array{label: string, name: string}>,
     *   only_strengths / holes / tie_axes: プロンプトに載せる材料(visible),
     *   self_matched_by_key: array<string, bool>, matched_labels_by_key: array<string, list<string>>(検証用、24項目すべて)
     */
    public function build(Analysis $analysis): array
    {
        $analysis->loadMissing(['project.leadCompany', 'websiteAnalyses.website']);

        $self = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        $competitors = $analysis->websiteAnalyses
            ->filter(fn (WebsiteAnalysis $wa) => ! (bool) $wa->website?->is_primary)
            ->sortBy(fn (WebsiteAnalysis $wa) => $wa->website?->display_order ?? PHP_INT_MAX)
            ->values();

        $entry = fn (WebsiteAnalysis $wa) => ['name' => (string) ($wa->website?->name ?? ''), 'record' => $this->latestRecord($wa), 'website' => $wa->website];

        return $this->buildFromEntries(
            (string) ($analysis->project?->leadCompany?->company_name ?? 'お客様'),
            $self !== null ? $entry($self) : null,
            $competitors->map($entry)->all(),
        );
    }

    /**
     * 自社と競合の判定結果から直接、材料を作る(測定コマンド用: 保存していない判定結果も渡せる)。
     * 競合は表示順(比較表の見出しと同じ並び)で渡す。
     *
     * @param  ?array{name: string, record: ?BrandWheelAnalysisResult, website: ?Website}  $self
     * @param  list<array{name: string, record: ?BrandWheelAnalysisResult, website: ?Website}>  $competitors
     * @return array<string, mixed> build()と同じ
     */
    public function buildFromEntries(string $selfName, ?array $self, array $competitors): array
    {
        if ($self === null) {
            return $this->skipped('self_unreadable', $selfName);
        }
        if ($competitors === []) {
            return $this->skipped('no_competitors', $selfName);
        }

        $selfRecord = $self['record'];
        if (! $this->readable($selfRecord, $self['website'])) {
            return $this->skipped('self_unreadable', $selfName);
        }
        if (! $this->sufficiency->isSufficient($selfRecord?->input_origin_chars)) {
            return $this->skipped('material_insufficient', $selfName);
        }

        $useNames = count($competitors) <= (int) config('admin_comparison_pptx.wheel_names_max_competitors');
        $symbols = (string) config('admin_comparison_pptx.wheel_competitor_symbols');

        $labels = [];
        $records = [];
        $companies = [];
        foreach (array_values($competitors) as $i => $competitor) {
            $record = $competitor['record'];
            $name = (string) $competitor['name'];
            $label = $useNames ? $name : (mb_substr($symbols, $i, 1) !== '' ? mb_substr($symbols, $i, 1) : (string) ($i + 1));
            if (! $this->readable($record, $competitor['website'])) {
                return $this->skipped('competitor_unreadable', $selfName);
            }
            if (! $this->sufficiency->isSufficient($record?->input_origin_chars)) {
                return $this->skipped('material_insufficient', $selfName);
            }
            $labels[] = $label;
            $records[] = $record;
            $companies[] = ['label' => $label, 'name' => $name];
        }

        $selfMatched = $this->matchedKeys($selfRecord);
        $selfEvidence = $this->evidenceLookup->build($selfRecord);
        $competitorMatched = array_map(fn (?BrandWheelAnalysisResult $r) => $this->matchedKeys($r), $records);
        $competitorEvidence = array_map(fn (?BrandWheelAnalysisResult $r) => $this->evidenceLookup->build($r), $records);

        $n = count($records);
        $threshold = $n * (float) config('comparison_commentary.majority_ratio', 0.5);
        $perItem = (int) config('comparison_commentary.competitor_quotes_per_item', 2);

        $onlyStrengths = [];
        $holes = [];
        $allItems = [];
        $selfMatchedByKey = [];
        $matchedLabelsByKey = [];
        $axisScores = [];
        $order = 0;

        foreach ((array) config('brand_wheel.axes') as $axisKey => $axis) {
            $axisSelf = 0;
            $axisCompetitors = array_fill(0, $n, 0);
            $denominator = count((array) ($axis['sub_elements'] ?? []));

            foreach ((array) ($axis['sub_elements'] ?? []) as $subKey => $subName) {
                $order++;
                $compKey = $axisKey.'.'.$subKey;
                $selfHas = isset($selfMatched[$compKey]);
                $matchedBy = [];
                foreach ($records as $i => $_) {
                    if (isset($competitorMatched[$i][$compKey])) {
                        $matchedBy[] = $i;
                        $axisCompetitors[$i]++;
                    }
                }
                if ($selfHas) {
                    $axisSelf++;
                }
                $count = count($matchedBy);
                $selfMatchedByKey[$subKey] = $selfHas;
                $matchedLabelsByKey[$subKey] = array_map(fn (int $i) => $labels[$i], $matchedBy);

                // 依頼CUA: 24項目すべての○×と、根拠の文(切り詰めない原文のまま)。AIを使わない一文の組み立てに使う
                // (プロンプトには載せない)。
                $allItems[] = [
                    'order' => $order,
                    'key' => $subKey,
                    'axis_key' => (string) $axisKey,
                    'axis' => (string) ($axis['name_ja'] ?? $axisKey),
                    'name' => (string) $subName,
                    'self_matched' => $selfHas,
                    'self_evidence' => $selfHas ? trim((string) ($selfEvidence[$axisKey][$subKey] ?? '')) : '',
                    'competitor_matched_count' => $count,
                    'competitors' => array_map(fn (int $i) => ['label' => $labels[$i], 'matched' => isset($competitorMatched[$i][$compKey]), 'evidence' => isset($competitorMatched[$i][$compKey]) ? trim((string) ($competitorEvidence[$i][$axisKey][$subKey] ?? '')) : ''], array_keys($records)),
                ];

                if ($selfHas && $count < $threshold) {
                    $onlyStrengths[] = [
                        'order' => $order,
                        'key' => $subKey,
                        'axis' => (string) ($axis['name_ja'] ?? $axisKey),
                        'name' => (string) $subName,
                        'self_evidence' => $selfText = $this->truncate((string) ($selfEvidence[$axisKey][$subKey] ?? '')),
                        'quote_candidates' => $this->quoteCandidates($selfText),
                        'competitor_matched_count' => $count,
                    ];
                } elseif (! $selfHas && $count >= $threshold && $count > 0) {
                    $quotes = [];
                    foreach ($matchedBy as $i) {
                        $evidence = $this->truncate((string) ($competitorEvidence[$i][$axisKey][$subKey] ?? ''));
                        if ($evidence !== '' && count($quotes) < $perItem) {
                            $quotes[] = ['company' => $labels[$i], 'evidence' => $evidence, 'quote_candidates' => $this->quoteCandidates($evidence)];
                        }
                    }
                    $holes[] = [
                        'order' => $order,
                        'key' => $subKey,
                        'axis' => (string) ($axis['name_ja'] ?? $axisKey),
                        'name' => (string) $subName,
                        'competitor_matched_count' => $count,
                        'matched_companies' => array_map(fn (int $i) => $labels[$i], $matchedBy),
                        'competitor_evidence' => $quotes,
                    ];
                }
            }

            $axisScores[] = [
                'axis' => (string) ($axis['name_ja'] ?? $axisKey),
                'self' => $axisSelf,
                'competitors' => $axisCompetitors,
                'denominator' => $denominator,
            ];
        }

        // 自社だけの強みは競合の○が少ない順、穴は競合の○が多い順(同数は項目の並び順)。上限まで。
        usort($onlyStrengths, fn (array $a, array $b) => [$a['competitor_matched_count'], $a['order']] <=> [$b['competitor_matched_count'], $b['order']]);
        usort($holes, fn (array $a, array $b) => [$b['competitor_matched_count'], $a['order']] <=> [$a['competitor_matched_count'], $b['order']]);
        $onlyStrengths = array_slice($onlyStrengths, 0, (int) config('comparison_commentary.only_strengths_max', 8));
        $holes = array_slice($holes, 0, (int) config('comparison_commentary.holes_max', 8));

        $tieAxes = [];
        foreach ($axisScores as $row) {
            if (count(array_unique(array_merge([$row['self']], $row['competitors']))) === 1) {
                $tieAxes[] = ['axis' => $row['axis'], 'score' => $row['self'], 'denominator' => $row['denominator'], 'company_count' => $n + 1];
            }
        }

        // 依頼CT-1の一文(点数から作る)と並べて見るための、領域ごとの点数と合計(プロンプトには載せない)。
        $totals = [
            'self' => array_sum(array_column($axisScores, 'self')),
            'competitors' => array_map(fn (int $i) => array_sum(array_map(fn (array $row) => $row['competitors'][$i], $axisScores)), array_keys($records)),
        ];

        return [
            'status' => 'ok',
            'reason' => null,
            'axis_scores' => $axisScores,
            'totals' => $totals,
            'self_name' => $selfName,
            'competitors' => $companies,
            'competitor_count' => $n,
            'only_strengths' => array_map(fn (array $r) => array_diff_key($r, ['order' => 1]), $onlyStrengths),
            'holes' => array_map(fn (array $r) => array_diff_key($r, ['order' => 1]), $holes),
            'tie_axes' => $tieAxes,
            'items' => $allItems,
            'self_matched_by_key' => $selfMatchedByKey,
            'matched_labels_by_key' => $matchedLabelsByKey,
        ];
    }

    /**
     * プロンプトに載せる部分(検証用の24項目の対応表は含めない)。
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public function visible(array $material): array
    {
        return [
            'self_name' => $material['self_name'],
            'competitors' => $material['competitors'],
            'competitor_count' => $material['competitor_count'],
            'only_strengths' => $material['only_strengths'],
            'holes' => $material['holes'],
            'tie_axes' => $material['tie_axes'],
        ];
    }

    private function latestRecord(WebsiteAnalysis $wa): ?BrandWheelAnalysisResult
    {
        return $wa->brandWheelAnalysisResults()->latest('id')->first();
    }

    private function readable(?BrandWheelAnalysisResult $record, ?Website $website): bool
    {
        if ($record === null || $website === null) {
            return false;
        }
        $wheel = $this->composer->compose($record, $website);

        return ($wheel['status'] ?? null) === 'success' && (array) ($wheel['axes'] ?? []) !== [];
    }

    /**
     * @return array<string, true> "axis_key.sub_key" => true
     */
    private function matchedKeys(?BrandWheelAnalysisResult $record): array
    {
        $keys = [];
        foreach ((array) ($record?->axes ?? []) as $axis) {
            foreach ((array) ($axis['matched_sub_elements'] ?? []) as $sub) {
                $keys[($axis['axis_key'] ?? '').'.'.($sub['key'] ?? '')] = true;
            }
        }

        return $keys;
    }

    /**
     * 根拠の文を、読点・句点で区切った短い連続部分にする(AIが「」で引用するときの候補。どれも根拠の文にそのまま含まれる)。
     * 区切りで終わった部分は、末尾の読点・句点を除く。隣り合う部分は、合わせてconfig('comparison_commentary.quote_hint_max_chars')
     * 以内ならつなぐ。1つの部分がそれより長いときは、途中で切らずそのまま(単語の途中で切れた引用を避けるため)。
     * 区切りの無い非常に長い部分だけ、config('comparison_commentary.quote_candidate_hard_max_chars')で切る。
     *
     * @return list<string>
     */
    private function quoteCandidates(string $evidence): array
    {
        $max = (int) config('comparison_commentary.quote_hint_max_chars', 30);
        $hardMax = max($max, (int) config('comparison_commentary.quote_candidate_hard_max_chars', 50));
        $pieces = preg_split('/(?<=[、。,，．.])/u', $evidence, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $candidates = [];
        $current = '';
        foreach ($pieces as $piece) {
            if ($current !== '' && mb_strlen($current.$piece) > $max) {
                $candidates[] = $current;
                $current = '';
            }
            $current .= $piece;
            while (mb_strlen($current) > $hardMax) {
                $candidates[] = mb_substr($current, 0, $hardMax);
                $current = mb_substr($current, $hardMax);
            }
        }
        if ($current !== '') {
            $candidates[] = $current;
        }

        // マルチバイトの文字を含むので、trim()ではなく/uの正規表現で前後の読点・句点・空白を除く。
        $strip = fn (string $c) => (string) preg_replace('/^[、。,，．.\s]+|[、。,，．.\s]+$/u', '', $c);

        return array_values(array_filter(array_map($strip, $candidates), fn (string $c) => $c !== ''));
    }

    private function truncate(string $text): string
    {
        $text = trim($text);
        $max = (int) config('comparison_commentary.evidence_max_chars', 80);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        // 末尾の「…」を含めて上限以内にする。
        $ellipsis = (string) config('comparison_commentary.evidence_ellipsis', '…');

        return mb_substr($text, 0, max(0, $max - mb_strlen($ellipsis))).$ellipsis;
    }

    /**
     * @return array<string, mixed>
     */
    private function skipped(string $reason, string $selfName): array
    {
        return ['status' => 'skipped', 'reason' => $reason, 'self_name' => $selfName, 'competitors' => [], 'only_strengths' => [], 'holes' => [], 'tie_axes' => [], 'items' => [], 'axis_scores' => [], 'totals' => ['self' => 0, 'competitors' => []], 'self_matched_by_key' => [], 'matched_labels_by_key' => []];
    }
}
