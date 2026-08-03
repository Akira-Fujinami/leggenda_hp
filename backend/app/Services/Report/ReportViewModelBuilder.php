<?php

namespace App\Services\Report;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelComparisonSummaryComposer;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use App\Services\Lead\LeadPerspectiveComposer;
use App\Services\Lead\LeadRecommendationComposer;
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
        private readonly LeadRecommendationComposer $recommendationComposer,
        // ブランド・ホイール(6軸)。JSON API(LeadAnalysisController)と同じ
        // Composerを共有する ―― 画面(旧)・レポートで判定ロジックが
        // 食い違わないようにするため(2026-08-03、画面から診断内容を
        // 外したことでレポートが6軸の唯一の配信経路になった)。
        private readonly BrandWheelLeadResponseComposer $brandWheelComposer,
        private readonly BrandWheelComparisonSummaryComposer $brandWheelSummaryComposer,
    ) {}

    public function build(Analysis $analysis, LeadSession $leadSession): ReportViewModel
    {
        $analysis->loadMissing([
            'websiteAnalyses.website',
            'websiteAnalyses.recommendations.metricResult.metricDefinition',
            'websiteAnalyses.metricResults.metricDefinition',
            // is_mock/status以外に絞る必要はない(1件のみ使うため軽量)。
            // latest('id')でwebsite_analysis_idあたり1件(最新)に絞る
            // (LeadAnalysisController::results()と同じ方針)。
            'websiteAnalyses.brandWheelAnalysisResults' => fn ($query) => $query->latest('id')->limit(1),
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

        // title/descriptionはLeadRecommendationComposer経由(screen/JSON APIの
        // LeadAnalysisControllerと同一クラスを共有) ―― 画面だけ直して
        // レポートに技術用語が残る食い違いを作らないため(2026-08-03)。
        $topRecommendations = $this->recommendationComposer->compose(
            $selfWebsiteAnalysis?->recommendations ?? collect(),
            self::MAX_RECOMMENDATIONS,
        );

        $overallSummaryText = $this->summaryComposer->composeOverallSummary(
            $selfScoreArray,
            count($topRecommendations),
            $this->nameFormatter->format($leadSession->company_name),
        );

        $perspectives = $this->perspectiveComposer->compose($selfWebsiteAnalysis?->metricResults ?? collect());

        $recommendationRows = array_values(array_map(fn (array $row) => new ReportRecommendationRow(
            title: $row['title'],
            description: $row['description'],
            priorityLabel: $this->labelFormatter->priorityLabel($row['recommendation']->priority),
            impactLabel: $this->labelFormatter->impactLabel($row['recommendation']->impact),
            effortLabel: $this->labelFormatter->effortLabel($row['recommendation']->effort),
        ), $topRecommendations));

        $brandWheelSelf = $selfWebsiteAnalysis !== null
            ? $this->brandWheelComposer->compose($selfWebsiteAnalysis->brandWheelAnalysisResults->first(), $selfWebsiteAnalysis->website)
            : null;
        $brandWheelCompetitor = $competitorWebsiteAnalysis !== null
            ? $this->brandWheelComposer->compose($competitorWebsiteAnalysis->brandWheelAnalysisResults->first(), $competitorWebsiteAnalysis->website)
            : null;

        // 判定ロジックはBrandWheelComparisonSummaryComposerのみに置く(ここでは
        // 呼び出すだけ) ―― 件数からの導出ルールを2箇所に持たない。
        $selfAxes = (array) ($brandWheelSelf['axes'] ?? []);
        $competitorAxes = (array) ($brandWheelCompetitor['axes'] ?? []);

        $brandWheelComparison = [
            'self_points' => $this->brandWheelSummaryComposer->points($selfAxes),
            'competitor_points' => $this->brandWheelSummaryComposer->points($competitorAxes),
            'one_point' => $this->brandWheelSummaryComposer->onePoint($selfAxes),
        ];

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
            brandWheelSelf: $brandWheelSelf,
            brandWheelCompetitor: $brandWheelCompetitor,
            brandWheelComparison: $brandWheelComparison,
        );
    }
}
