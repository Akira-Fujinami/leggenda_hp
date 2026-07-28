<?php

namespace App\Support\Report;

/**
 * Word/PDF生成の両方が参照する唯一のデータ構造。文面・レイアウトの変更は
 * WordReportGenerator/PdfReportGeneratorそれぞれの中だけで行い、スコア計算
 * ロジックや文面の分岐ロジック自体はここに持ち込まない
 * (ReportSummaryComposer/LeadPerspectiveComposer等、既存の計算結果を
 * そのまま保持するだけの読み取り専用DTO)。
 *
 * スクリーンショットは意図的に含めない
 * (リード分析ではCaptureScreenshotJob自体を省略するため)。
 *
 * Phase 3: 内部の7カテゴリ別内訳(旧categoryBreakdown)は、採用担当向けの
 * 4観点(perspectives、LeadPerspectiveComposer::compose()の戻り値)へ
 * 置き換えた ―― 表示のグルーピングを変えるだけで、採点ロジック自体は
 * 変更していない。
 */
readonly class ReportViewModel
{
    /**
     * @param  array<string, mixed>  $selfScore  WebsiteScoreResult::toArray()
     * @param  ?array<string, mixed>  $competitorScore  WebsiteScoreResult::toArray()、競合なしの場合はnull
     * @param  list<array<string, mixed>>  $perspectives  LeadPerspectiveComposer::compose()の戻り値
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
        public array $perspectives,
        public array $topRecommendations,
        public bool $isPartial,
    ) {}
}
