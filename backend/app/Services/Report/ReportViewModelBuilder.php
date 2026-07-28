<?php

namespace App\Services\Report;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\WebsiteAnalysis;
use App\Services\Lead\LeadPerspectiveComposer;
use App\Services\Lead\LeadScoreCalculator;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;

/**
 * Analysis(+LeadSession)から、Word/PDF生成が共通で参照するReportViewModelを
 * 組み立てる。スコア計算・強み弱み判定は既存のLeadScoreCalculator等を
 * そのまま呼び出すだけで、再実装は一切しない。
 *
 * Phase 3: 内部の7カテゴリ別内訳の代わりに、採用担当向けの4観点
 * (LeadPerspectiveComposer)を組み込む。JSON API(LeadAnalysisController)と
 * 同じComposerを使うため、画面とレポートで表示内容が食い違わない。
 *
 * スコアも同様にLeadScoreCalculator(社内版OverallScoreCalculatorとは別建て、
 * 4観点に表示している指標だけを対象に算出)をJSON APIと共有する
 * ―― 画面の点数とレポートの点数が食い違うことがないようにするため
 * (2026-07-28のユーザー指摘への対応)。
 */
class ReportViewModelBuilder
{
    private const MAX_RECOMMENDATIONS = 5;

    public function __construct(
        private readonly LeadScoreCalculator $scoreCalculator,
        private readonly ReportSummaryComposer $summaryComposer,
        private readonly LeadPerspectiveComposer $perspectiveComposer,
        private readonly HonorificNameFormatter $nameFormatter,
        private readonly RecommendationLabelFormatter $labelFormatter,
    ) {}

    public function build(Analysis $analysis, LeadSession $leadSession): ReportViewModel
    {
        $analysis->loadMissing([
            'websiteAnalyses.website',
            'websiteAnalyses.recommendations',
            'websiteAnalyses.metricResults.metricDefinition',
        ]);

        $activeDefinitions = MetricDefinition::query()->where('is_active', true)->get();

        $selfWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        $competitorWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => ! (bool) $wa->website?->is_primary);

        $selfScore = $this->scoreCalculator->calculate($activeDefinitions, $selfWebsiteAnalysis?->metricResults ?? collect());
        $selfScoreArray = $selfScore->toArray();

        $competitorScoreArray = null;
        $comparisonSentence = null;

        if ($competitorWebsiteAnalysis !== null) {
            $competitorScore = $this->scoreCalculator->calculate($activeDefinitions, $competitorWebsiteAnalysis->metricResults);
            $competitorScoreArray = $competitorScore->toArray();
            $comparisonSentence = $this->summaryComposer->composeComparisonSentence($selfScoreArray, $competitorScoreArray);
        }

        $topRecommendations = ($selfWebsiteAnalysis?->recommendations ?? collect())
            ->sortByDesc('sort_score')
            ->take(self::MAX_RECOMMENDATIONS)
            ->values();

        $overallSummaryText = $this->summaryComposer->composeOverallSummary(
            $selfScoreArray,
            $topRecommendations->count(),
            $this->nameFormatter->format($leadSession->company_name),
        );

        $perspectives = $this->perspectiveComposer->compose($selfWebsiteAnalysis?->metricResults ?? collect());

        $recommendationRows = $topRecommendations->map(fn ($r) => new ReportRecommendationRow(
            title: $r->title,
            description: $r->description,
            priorityLabel: $this->labelFormatter->priorityLabel($r->priority),
            impactLabel: $this->labelFormatter->impactLabel($r->impact),
            effortLabel: $this->labelFormatter->effortLabel($r->effort),
        ))->values()->all();

        return new ReportViewModel(
            companyDisplayName: $this->nameFormatter->format($leadSession->company_name),
            generatedAtLabel: sprintf('%d年%d月%d日', now()->year, now()->month, now()->day),
            selfWebsiteUrl: $selfWebsiteAnalysis?->website?->url ?? '',
            competitorWebsiteUrl: $competitorWebsiteAnalysis?->website?->url,
            selfScore: $selfScoreArray,
            competitorScore: $competitorScoreArray,
            overallSummaryText: $overallSummaryText,
            comparisonSentence: $comparisonSentence,
            perspectives: $perspectives,
            topRecommendations: $recommendationRows,
            isPartial: $analysis->status === AnalysisStatus::Partial,
        );
    }
}
