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
     * @param  list<array<string, mixed>>  $perspectives  LeadPerspectiveComposer::compose()の戻り値+各要素に'one_liner'(string)を1つ追加したもの(ReportViewModelBuilderが付与、LeadPerspectiveComposer自体・JSON APIの形は変更しない)
     * @param  list<ReportRecommendationRow>  $topRecommendations
     * @param  ?array<string, mixed>  $brandWheelSelf  BrandWheelLeadResponseComposer::compose()の戻り値
     * @param  ?array<string, mixed>  $brandWheelCompetitor  BrandWheelLeadResponseComposer::compose()の戻り値、競合なしの場合はnull
     * @param  array{self_points: list<string>, competitor_points: list<string>, one_point: ?array{key: string, text: string}}  $brandWheelComparison  BrandWheelComparisonSummaryComposerの戻り値
     * @param  ?string  $brandWheelRadarPng  BrandWheelRadarSvgBuilder+BrandWheelHexagonRendererで生成したPNGの生バイナリ。ラスタライズ失敗時・自社のブランド・ホイールがstatus!=='success'のときはnull(画像を省略し表だけで成立させる、既存メールと同じ方針)
     * @param  list<array{axis_key: string, axis_name: string, group: string, sub_element_name: string, evidence: string}>  $selfBrandWheelEvidenceItems  自社のみ。「サイトから読み取れた記述」ページ(evidence一覧)用。BrandWheelLeadResponseComposer::compose()はJSON API向けに意図的にevidenceを含めないため、ReportViewModelBuilderが生のBrandWheelAnalysisResult.axesから自社分のみ別途組み立てる(競合側のevidenceは含めない、他社サイトの本文をレポートに出さないため)。空配列の場合、レポート側はこのページ自体を出さない。
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
        public ?array $brandWheelSelf,
        public ?array $brandWheelCompetitor,
        public array $brandWheelComparison,
        public ?string $brandWheelRadarPng,
        public array $selfBrandWheelEvidenceItems,
    ) {}
}
