<?php

namespace App\Services\Report;

use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelEvidenceLookupBuilder;
use App\Services\BrandWheel\BrandWheelHexagonRenderer;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use App\Services\BrandWheel\BrandWheelMultiSiteComparisonComposer;
use App\Services\BrandWheel\BrandWheelQuoteTranslator;
use App\Services\BrandWheel\BrandWheelRadarSvgBuilder;
use App\Services\BrandWheel\BrandWheelTextTruncator;
use App\Support\Report\MultiSiteReportViewModel;

/**
 * 依頼AC(2026-08-27): 管理者向け多社比較レポート(自社1×競合N社)専用の
 * ビルダー。既存のReportViewModelBuilder(リード向け、自社×競合1社専用)は
 * 無改修のまま ―― こちらは新しいAnalysis形状(source_analysis_idを持つ、
 * websiteAnalysesが自社1+競合N件)専用に新規実装する。判定ロジック自体は
 * 既存のComposer群(BrandWheelLeadResponseComposer/BrandWheelEvidenceLookupBuilder/
 * BrandWheelQuoteTranslator等)をそのまま再利用し、多社版の集計だけ新設の
 * BrandWheelMultiSiteComparisonComposerに委ねる。
 */
class MultiSiteReportViewModelBuilder
{
    private const RADAR_WIDTH_PX = 760;

    private const RADAR_HEIGHT_PX = 552;

    public function __construct(
        private readonly BrandWheelLeadResponseComposer $brandWheelComposer,
        private readonly BrandWheelMultiSiteComparisonComposer $multiSiteComposer,
        private readonly BrandWheelEvidenceLookupBuilder $evidenceLookupBuilder,
        private readonly BrandWheelRadarSvgBuilder $radarSvgBuilder,
        private readonly BrandWheelHexagonRenderer $pngRenderer,
        private readonly BrandWheelQuoteTranslator $quoteTranslator,
    ) {}

    public function build(Analysis $analysis): MultiSiteReportViewModel
    {
        $analysis->loadMissing([
            'project.leadCompany',
            'project.websites',
            'websiteAnalyses.website',
            'websiteAnalyses.brandWheelAnalysisResults' => fn ($query) => $query->latest('id')->limit(1),
        ]);

        $selfWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        // 依頼AB-3: display_orderが競合の列順(依頼AC-2の唯一の情報源)。
        $competitorWebsiteAnalyses = $analysis->websiteAnalyses
            ->filter(fn (WebsiteAnalysis $wa) => ! (bool) $wa->website?->is_primary)
            ->sortBy(fn (WebsiteAnalysis $wa) => $wa->website?->display_order ?? PHP_INT_MAX)
            ->values();

        $selfRecord = $selfWebsiteAnalysis?->brandWheelAnalysisResults->first();
        $selfWheel = $selfWebsiteAnalysis !== null
            ? $this->brandWheelComposer->compose($selfRecord, $selfWebsiteAnalysis->website)
            : ['status' => 'error', 'axes' => []];
        $selfAxes = (array) ($selfWheel['axes'] ?? []);
        $selfReadable = ($selfWheel['status'] ?? null) === 'success' && $selfAxes !== [];

        $competitors = [];
        $competitorRecords = [];
        $competitorAxesList = [];
        foreach ($competitorWebsiteAnalyses as $competitorWebsiteAnalysis) {
            $record = $competitorWebsiteAnalysis->brandWheelAnalysisResults->first();
            $wheel = $this->brandWheelComposer->compose($record, $competitorWebsiteAnalysis->website);

            $competitors[] = [
                'name' => (string) ($competitorWebsiteAnalysis->website?->name ?? ''),
                'url' => (string) ($competitorWebsiteAnalysis->website?->url ?? ''),
            ];
            $competitorRecords[] = $record;
            $competitorAxesList[] = (array) ($wheel['axes'] ?? []);
        }

        $items = $this->multiSiteComposer->compose($selfAxes, $competitorAxesList);
        $missingItems = $this->multiSiteComposer->extractMissingFromSelf($items);
        $strengthItems = $this->multiSiteComposer->extractSelfStrengths($items);

        // 依頼AC-3: ①(自社に足りない項目)のみ、代表競合1社の引用を添える
        // (依頼者承認済みの範囲 ―― ②には付けない)。代表社の選定は
        // representativeCompetitorIndex()(display_orderが最も早い1社、
        // 決定的)に一元化する。
        $maxChars = (int) config('brand_wheel.evidence_page_quote_max_chars');
        $translationCandidates = [];

        $missingFromSelf = array_map(function (array $item) use ($competitorRecords, $competitors, $maxChars, &$translationCandidates) {
            $representativeIndex = $this->multiSiteComposer->representativeCompetitorIndex($item['competitor_matched_flags']);
            $quote = null;
            $representativeCompanyName = null;

            if ($representativeIndex !== null) {
                $evidenceLookup = $this->evidenceLookupBuilder->build($competitorRecords[$representativeIndex] ?? null);
                $rawEvidence = trim((string) ($evidenceLookup[$item['axis_key']][$item['sub_key']] ?? ''));

                if ($rawEvidence !== '') {
                    $quote = BrandWheelTextTruncator::truncateAtSentenceBoundary($rawEvidence, $maxChars);
                    $representativeCompanyName = $competitors[$representativeIndex]['name'];

                    if (! BrandWheelQuoteTranslator::isJapanese($quote)) {
                        $translationCandidates[$quote] = true;
                    }
                }
            }

            return [
                'axis_name' => $item['axis_name'],
                'sub_name' => $item['sub_name'],
                'definition' => $item['definition'],
                'recommendation' => $item['recommendation'],
                'competitor_matched_count' => $item['competitor_matched_count'],
                'representative_company_name' => $representativeCompanyName,
                'quote' => $quote,
                'quote_translation' => null,
            ];
        }, $missingItems);

        $selfStrengths = array_map(fn (array $item) => [
            'axis_name' => $item['axis_name'],
            'sub_name' => $item['sub_name'],
            'definition' => $item['definition'],
            'competitor_matched_count' => $item['competitor_matched_count'],
        ], $strengthItems);

        $comparisonTable = array_map(fn (array $item) => [
            'axis_name' => $item['axis_name'],
            'group' => $item['group'],
            'sub_name' => $item['sub_name'],
            'self_matched' => $item['self_matched'],
            'competitor_matched' => $item['competitor_matched_flags'],
        ], $items);

        $selfTotalMatched = array_sum(array_column($selfAxes, 'matched_count'));
        $selfTotalMax = array_sum(array_column($selfAxes, 'max_count'));

        $selfEvidenceByAxis = $this->buildSelfEvidenceByAxis($items, $this->evidenceLookupBuilder->build($selfRecord));
        foreach ($selfEvidenceByAxis as $axisGroup) {
            foreach ($axisGroup['items'] as $evidenceItem) {
                if (! BrandWheelQuoteTranslator::isJapanese($evidenceItem['evidence'])) {
                    $translationCandidates[$evidenceItem['evidence']] = true;
                }
            }
        }

        $quoteTranslations = $this->quoteTranslator->translate(array_keys($translationCandidates));

        $missingFromSelf = array_map(function (array $item) use ($quoteTranslations) {
            $item['quote_translation'] = $item['quote'] !== null ? ($quoteTranslations[$item['quote']] ?? null) : null;

            return $item;
        }, $missingFromSelf);

        $selfEvidenceByAxis = array_map(function (array $axisGroup) use ($quoteTranslations) {
            $axisGroup['items'] = array_map(
                fn (array $evidenceItem) => $evidenceItem + ['evidence_translation' => $quoteTranslations[$evidenceItem['evidence']] ?? null],
                $axisGroup['items'],
            );

            return $axisGroup;
        }, $selfEvidenceByAxis);

        return new MultiSiteReportViewModel(
            selfCompanyDisplayName: (string) ($analysis->project?->leadCompany?->company_name ?? 'お客様'),
            generatedAtLabel: sprintf('%d年%d月%d日', now()->year, now()->month, now()->day),
            selfWebsiteUrl: (string) ($selfWebsiteAnalysis?->website?->url ?? ''),
            competitors: $competitors,
            competitorCount: count($competitors),
            majorityThreshold: $this->multiSiteComposer->majorityThreshold(count($competitors)),
            selfReadable: $selfReadable,
            selfTotalMatched: $selfTotalMatched,
            selfTotalMax: $selfTotalMax,
            brandWheelRadarPngCombined: $this->buildCombinedRadarPng($selfReadable ? $selfAxes : null, $competitorAxesList),
            missingFromSelf: $missingFromSelf,
            selfStrengths: $selfStrengths,
            comparisonTable: $comparisonTable,
            selfEvidenceByAxis: $selfEvidenceByAxis,
            hasQuoteTranslations: $quoteTranslations !== [],
        );
    }

    /**
     * 依頼R方式を踏襲(自社のみ、matched項目のみ、原文のまま切り詰め)。
     * 既存のReportViewModelBuilder::buildSelfEvidenceByAxis()と同じロジックを
     * 独立実装する(既存クラスは無改修のため呼び出せない ――
     * $subElementComparisonの形が異なる: competitor_matchedがbool1件ではなく
     * list<bool>のため)。
     *
     * @param  list<array{axis_key: string, axis_name: string, sub_key: string, sub_name: string, self_matched: bool}>  $items
     * @param  array<string, array<string, string>>  $selfEvidenceLookup
     * @return list<array{axis_name: string, items: list<array{sub_name: string, evidence: string}>}>
     */
    private function buildSelfEvidenceByAxis(array $items, array $selfEvidenceLookup): array
    {
        $maxChars = (int) config('brand_wheel.evidence_page_quote_max_chars');

        $byAxisKey = [];
        foreach ($items as $item) {
            if (! $item['self_matched']) {
                continue;
            }

            $evidence = trim((string) ($selfEvidenceLookup[$item['axis_key']][$item['sub_key']] ?? ''));

            if ($evidence === '') {
                continue;
            }

            $byAxisKey[$item['axis_key']]['axis_name'] = $item['axis_name'];
            $byAxisKey[$item['axis_key']]['items'][] = [
                'sub_name' => $item['sub_name'],
                'evidence' => BrandWheelTextTruncator::truncateAtSentenceBoundary($evidence, $maxChars),
            ];
        }

        return array_values($byAxisKey);
    }

    /**
     * 依頼AB-4/AC(承認済み設計): 自社は実線、競合は「N社平均」として1本の
     * 破線で重ねる(個社ごとの線は描かない ―― 個社差は対比表で追える、
     * 依頼者承認)。平均は読み取れた(status==='success')競合のみを対象に
     * 算出する ―― 読み取れなかった競合(axesが空)は「0点」として平均を
     * 引き下げない(既存方針「読み取れなかった=0点にしない」との一貫性)。
     * 読み取れる競合が1社も無い場合は自社単独の実線のみ描く。
     *
     * @param  ?list<array{key: string, name: string, matched_count: int, max_count: int}>  $selfAxes
     * @param  list<list<array{key: string, name: string, matched_count: int, max_count: int}>>  $competitorAxesList
     */
    private function buildCombinedRadarPng(?array $selfAxes, array $competitorAxesList): ?string
    {
        if ($selfAxes === null || $selfAxes === []) {
            return null;
        }

        $readableCompetitorAxesList = array_values(array_filter($competitorAxesList, fn (array $axes) => $axes !== []));

        $averageAxes = null;
        if ($readableCompetitorAxesList !== []) {
            $averageAxes = array_map(function (array $selfAxis) use ($readableCompetitorAxesList) {
                $matchedCounts = array_map(
                    fn (array $competitorAxes) => (float) (collect($competitorAxes)->firstWhere('key', $selfAxis['key'])['matched_count'] ?? 0),
                    $readableCompetitorAxesList,
                );

                return [
                    'key' => $selfAxis['key'],
                    'name' => $selfAxis['name'],
                    'matched_count' => array_sum($matchedCounts) / count($matchedCounts),
                    'max_count' => $selfAxis['max_count'],
                ];
            }, $selfAxes);
        }

        $svg = $this->radarSvgBuilder->build(
            $selfAxes,
            $averageAxes,
            BrandWheelRadarSvgBuilder::selfColor(),
            BrandWheelRadarSvgBuilder::competitorColor(),
            secondaryDashed: true,
        );

        return $this->pngRenderer->renderPng($svg, self::RADAR_WIDTH_PX, self::RADAR_HEIGHT_PX);
    }
}
