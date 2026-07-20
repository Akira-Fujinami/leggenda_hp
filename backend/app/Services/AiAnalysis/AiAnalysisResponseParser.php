<?php

namespace App\Services\AiAnalysis;

use App\Services\AiAnalysis\Data\AiAnalysisInput;
use App\Services\AiAnalysis\Data\AiAnalysisResult;
use App\Services\AiAnalysis\Data\AiCompetitorInsightItem;
use App\Services\AiAnalysis\Data\AiPriorityActionItem;
use App\Services\AiAnalysis\Data\AiStrengthItem;
use App\Services\AiAnalysis\Data\AiWeaknessItem;

/**
 * AI Providerが返したJSON(デコード済み連想配列)を検証し、AiAnalysisResultへ
 * 変換する。AIの出力はそのまま信用しない ―― 実際にAiAnalysisInputへ渡した
 * metric keyやwebsite_analysis_idに存在しない参照は黙って除外し、
 * 必須フィールド(summary/confidence)が欠落・型不正な場合は例外にする。
 */
class AiAnalysisResponseParser
{
    private const VALID_PRIORITIES = ['critical', 'high', 'medium', 'low'];

    private const VALID_IMPACTS = ['high', 'medium', 'low'];

    private const VALID_EFFORTS = ['small', 'medium', 'large'];

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws AiAnalysisException
     */
    public function parse(array $raw, AiAnalysisInput $input, string $provider, ?string $model, bool $isMock): AiAnalysisResult
    {
        $summary = $raw['summary'] ?? null;

        if (! is_string($summary) || trim($summary) === '') {
            throw new AiAnalysisException('AI_INVALID_STRUCTURE', 'AIの応答にsummaryが含まれていないか、不正な形式です。');
        }

        $confidenceRaw = $raw['confidence'] ?? null;

        if (! is_numeric($confidenceRaw)) {
            throw new AiAnalysisException('AI_INVALID_STRUCTURE', 'AIの応答にconfidenceが含まれていないか、不正な形式です。');
        }

        $validMetricKeys = $this->validMetricKeys($input);
        $validWebsiteAnalysisIds = $this->validWebsiteAnalysisIds($input);

        return new AiAnalysisResult(
            summary: trim($summary),
            strengths: $this->parseEvidenceItems($raw['strengths'] ?? [], $validMetricKeys, fn ($title, $description, $keys) => new AiStrengthItem($title, $description, $keys)),
            weaknesses: $this->parseEvidenceItems($raw['weaknesses'] ?? [], $validMetricKeys, fn ($title, $description, $keys) => new AiWeaknessItem($title, $description, $keys)),
            priorityActions: $this->parsePriorityActions($raw['priority_actions'] ?? [], $validMetricKeys),
            competitorInsights: $this->parseCompetitorInsights($raw['competitor_insights'] ?? [], $validWebsiteAnalysisIds),
            cautions: $this->parseStringList($raw['cautions'] ?? []),
            confidence: max(0.0, min(1.0, (float) $confidenceRaw)),
            provider: $provider,
            model: $model,
            isMock: $isMock,
        );
    }

    /**
     * @return list<string>
     */
    private function validMetricKeys(AiAnalysisInput $input): array
    {
        return $input->importantMetrics->pluck('key')
            ->merge($input->unavailableMetrics)
            ->merge($input->errorMetrics)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function validWebsiteAnalysisIds(AiAnalysisInput $input): array
    {
        return $input->competitorGaps->pluck('websiteAnalysisId')
            ->push($input->websiteAnalysisId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $validKeys
     * @return list<string>
     */
    private function filterMetricKeys(mixed $keys, array $validKeys): array
    {
        if (! is_array($keys)) {
            return [];
        }

        $stringKeys = array_map(fn ($k) => is_scalar($k) ? (string) $k : null, $keys);

        return array_values(array_intersect(array_filter($stringKeys, fn ($k) => $k !== null), $validKeys));
    }

    /**
     * @param  list<int>  $validIds
     * @return list<int>
     */
    private function filterWebsiteAnalysisIds(mixed $ids, array $validIds): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $intIds = array_map(fn ($id) => is_numeric($id) ? (int) $id : null, $ids);

        return array_values(array_intersect(array_filter($intIds, fn ($id) => $id !== null), $validIds));
    }

    /**
     * @param  list<string>  $validMetricKeys
     * @return list<mixed>
     */
    private function parseEvidenceItems(mixed $items, array $validMetricKeys, \Closure $factory): array
    {
        if (! is_array($items)) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['title'], $item['description'])) {
                continue;
            }

            if (! is_string($item['title']) || ! is_string($item['description'])) {
                continue;
            }

            $result[] = $factory(
                $item['title'],
                $item['description'],
                $this->filterMetricKeys($item['evidence_metric_keys'] ?? [], $validMetricKeys),
            );
        }

        return $result;
    }

    /**
     * @param  list<string>  $validMetricKeys
     * @return list<AiPriorityActionItem>
     */
    private function parsePriorityActions(mixed $items, array $validMetricKeys): array
    {
        if (! is_array($items)) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['title'], $item['description'])) {
                continue;
            }

            if (! is_string($item['title']) || ! is_string($item['description'])) {
                continue;
            }

            $priority = is_string($item['priority'] ?? null) && in_array($item['priority'], self::VALID_PRIORITIES, true)
                ? $item['priority'] : 'medium';
            $impact = is_string($item['impact'] ?? null) && in_array($item['impact'], self::VALID_IMPACTS, true)
                ? $item['impact'] : 'medium';
            $effort = is_string($item['effort'] ?? null) && in_array($item['effort'], self::VALID_EFFORTS, true)
                ? $item['effort'] : 'medium';

            $result[] = new AiPriorityActionItem(
                title: $item['title'],
                description: $item['description'],
                priority: $priority,
                impact: $impact,
                effort: $effort,
                evidenceMetricKeys: $this->filterMetricKeys($item['evidence_metric_keys'] ?? [], $validMetricKeys),
            );
        }

        return $result;
    }

    /**
     * @param  list<int>  $validWebsiteAnalysisIds
     * @return list<AiCompetitorInsightItem>
     */
    private function parseCompetitorInsights(mixed $items, array $validWebsiteAnalysisIds): array
    {
        if (! is_array($items)) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['title'], $item['description'])) {
                continue;
            }

            if (! is_string($item['title']) || ! is_string($item['description'])) {
                continue;
            }

            $result[] = new AiCompetitorInsightItem(
                title: $item['title'],
                description: $item['description'],
                competitorWebsiteAnalysisIds: $this->filterWebsiteAnalysisIds($item['competitor_website_analysis_ids'] ?? [], $validWebsiteAnalysisIds),
            );
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function parseStringList(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($i) => is_string($i) ? $i : null, $items)));
    }
}
