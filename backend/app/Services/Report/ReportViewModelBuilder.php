<?php

namespace App\Services\Report;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\CategoryDefinition;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\WebsiteAnalysis;
use App\Services\Scoring\OverallScoreCalculator;
use App\Support\Report\ReportCategoryRow;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;
use App\Support\Scoring\WebsiteScoreResult;
use Illuminate\Support\Collection;

/**
 * Analysis(+LeadSession)から、Word/PDF生成が共通で参照するReportViewModelを
 * 組み立てる。スコア計算・強み弱み判定は既存のOverallScoreCalculator等を
 * そのまま呼び出すだけで、再実装は一切しない。
 */
class ReportViewModelBuilder
{
    /**
     * カテゴリ配点(CategoryDefinitionSeeder)は将来変わり得るため、
     * 説明文はkeyで引く。Seeder側のdescription列は現状nullのため、
     * レポート専用の一言説明をここに保持する
     * (2026-07-27 ユーザーレビューで承認済みの文面。technologyのみ、
     * CMS検出はinformational専用でありスコアに寄与しないという指摘を受けて
     * 「アクセス解析タグの設置状況とWeb標準への準拠」に修正済み)。
     *
     * @var array<string, string>
     */
    private const CATEGORY_DESCRIPTIONS = [
        'technical_seo' => '検索エンジンに正しく認識されるための基本設定',
        'content' => 'タイトルや説明文など、ページ内容の充実度',
        'performance' => 'ページの表示速度と体感的な快適さ',
        'accessibility' => '誰にとっても使いやすいページ作りへの配慮',
        'technology' => 'アクセス解析タグの設置状況とWeb標準への準拠',
        'conversion' => '問い合わせや資料請求につながる導線の設計',
        'authority' => '外部からの評価やドメインの信頼性',
    ];

    private const MAX_RECOMMENDATIONS = 5;

    public function __construct(
        private readonly OverallScoreCalculator $scoreCalculator,
        private readonly ReportSummaryComposer $summaryComposer,
        private readonly CategoryAvailabilityClassifier $availabilityClassifier,
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

        $activeCategories = CategoryDefinition::query()->where('is_active', true)->orderBy('display_order')->get();
        $activeDefinitions = MetricDefinition::query()->where('is_active', true)->get();
        $definitionsByCategory = $activeDefinitions->groupBy('category_key');

        $selfWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        $competitorWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => ! (bool) $wa->website?->is_primary);

        $selfScore = $this->scoreCalculator->calculate($activeCategories, $activeDefinitions, $selfWebsiteAnalysis?->metricResults ?? collect());
        $selfScoreArray = $selfScore->toArray();

        $competitorScoreArray = null;
        $comparisonSentence = null;

        if ($competitorWebsiteAnalysis !== null) {
            $competitorScore = $this->scoreCalculator->calculate($activeCategories, $activeDefinitions, $competitorWebsiteAnalysis->metricResults);
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

        $categoryBreakdown = $this->buildCategoryBreakdown(
            $selfScore,
            $definitionsByCategory,
            $selfWebsiteAnalysis?->metricResults ?? collect(),
            $analysis,
        );

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
            categoryBreakdown: $categoryBreakdown,
            topRecommendations: $recommendationRows,
            isPartial: $analysis->status === AnalysisStatus::Partial,
        );
    }

    /**
     * @param  Collection<string, Collection<int, MetricDefinition>>  $definitionsByCategory
     * @param  Collection<int, MetricResult>  $selfMetricResults
     * @return list<ReportCategoryRow>
     */
    private function buildCategoryBreakdown(
        WebsiteScoreResult $selfScore,
        Collection $definitionsByCategory,
        Collection $selfMetricResults,
        Analysis $analysis,
    ): array {
        $resultsByDefinitionId = $selfMetricResults->keyBy('metric_definition_id');

        return $selfScore->categoryScores->map(function ($category) use ($definitionsByCategory, $resultsByDefinitionId, $analysis) {
            $categoryDefinitions = $definitionsByCategory->get($category->key, collect());

            $availability = $this->availabilityClassifier->classify(
                $category->maxAvailableScore,
                $categoryDefinitions,
                $resultsByDefinitionId,
                $analysis,
            );

            return new ReportCategoryRow(
                key: $category->key,
                name: $category->name,
                description: self::CATEGORY_DESCRIPTIONS[$category->key] ?? '',
                score: round($category->score, 2),
                configuredMaxScore: round($category->configuredMaxScore, 2),
                coverageRate: round($category->coverageRate, 2),
                availability: $availability,
            );
        })->values()->all();
    }
}
