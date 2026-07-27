<?php

namespace App\Support\Report;

/**
 * Word/PDF生成の両方が参照する唯一のデータ構造。文面・レイアウトの変更は
 * WordReportGenerator/PdfReportGeneratorそれぞれの中だけで行い、スコア計算
 * ロジックや文面の分岐ロジック自体はここに持ち込まない
 * (ReportSummaryComposer/CategoryAvailabilityClassifier等、既存の計算結果を
 * そのまま保持するだけの読み取り専用DTO)。
 *
 * スクリーンショットは意図的に含めない
 * (リード分析ではCaptureScreenshotJob自体を省略するため)。
 */
readonly class ReportViewModel
{
    /**
     * @param  array<string, mixed>  $selfScore  WebsiteScoreResult::toArray()
     * @param  ?array<string, mixed>  $competitorScore  WebsiteScoreResult::toArray()、競合なしの場合はnull
     * @param  list<ReportCategoryRow>  $categoryBreakdown
     * @param  list<ReportRecommendationRow>  $topRecommendations
     */
    public function __construct(
        public string $companyDisplayName,
        public string $generatedAtLabel,
        public string $selfWebsiteUrl,
        public ?string $competitorWebsiteUrl,
        public array $selfScore,
        public ?array $competitorScore,
        public string $overallSummaryText,
        public ?string $comparisonSentence,
        public array $categoryBreakdown,
        public array $topRecommendations,
        public bool $isPartial,
    ) {}
}
