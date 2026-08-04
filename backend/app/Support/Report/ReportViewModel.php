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
     * @param  array{self_points: list<string>, one_point: ?array{key: string, text: string}}  $brandWheelComparison  BrandWheelComparisonSummaryComposerの戻り値。2026-08-04: 「他社ページ比較とのまとめ」ページ削除に伴いcompetitor_pointsは廃止(所見が「24項目の対比」ページの言い換えになっていたため)。one_pointは「改善提案」ページ冒頭のワンポイントとして使う。
     * @param  ?string  $brandWheelRadarPng  BrandWheelRadarSvgBuilder+BrandWheelHexagonRendererで生成したPNGの生バイナリ。ラスタライズ失敗時・自社のブランド・ホイールがstatus!=='success'のときはnull(画像を省略し表だけで成立させる、既存メールと同じ方針)
     * @param  list<array{axis_key: string, axis_name: string, group: string, sub_element_name: string, evidence: string}>  $selfBrandWheelEvidenceItems  自社のみ。「サイトから読み取れた記述」ページ(evidence一覧)用。BrandWheelLeadResponseComposer::compose()はJSON API向けに意図的にevidenceを含めないため、ReportViewModelBuilderが生のBrandWheelAnalysisResult.axesから自社分のみ別途組み立てる(競合側のevidenceは含めない、他社サイトの本文をレポートに出さないため)。空配列の場合、レポート側はこのページ自体を出さない。
     * @param  int  $selfTotalMatched  自社の24項目中の該当件数合計。「自社ページの分析結果」「24項目の対比」「改善提案」の3ページが必ず同じ値を参照する(2026-08-04、README「合計件数Nは3・6・8ページ目で必ず同じソースから算出すること」への対応。BrandWheelLeadResponseComposerが組み立てたaxes配列から、このクラスで1回だけ集計する)。
     * @param  int  $selfTotalMax  自社の24項目の分母(config('brand_wheel.axes.*.sub_elements')の総数。24を固定値で書かない)。
     * @param  int  $competitorTotalMatched  競合の該当件数合計。競合なし/非successの場合は0。
     * @param  int  $competitorTotalMax  競合の分母。競合なし/非successの場合は0。
     * @param  list<array{axis_key: string, axis_name: string, group: string, sub_key: string, sub_name: string, definition: string, self_matched: bool, competitor_matched: bool}>  $subElementComparison  BrandWheelSubElementComparisonComposer::compose()の戻り値(24項目、config順)。「24項目の対比」ページの●／－表示と、「サイトで触れられていなかった項目」ページのA/B/C分類の唯一の情報源。
     * @param  array{a: list<array<string, mixed>>, b: list<array<string, mixed>>, c: list<array<string, mixed>>}  $gapAnalysis  BrandWheelSubElementComparisonComposer::splitByGap()の戻り値。自社・競合の双方がstatus==='success'でない場合は3つとも空配列(このときページ自体を出さない判断はテンプレート側がstatusを見て行う ―― 空配列は「差が無かった」ことと区別できないため)。
     * @param  ?array{selected_group: string, groups: list<array{group: string, label: string, self_count: int, competitor_count: int, max_count: int}>, items: list<array{axis_name: string, sub_name: string, definition: string, competitor_evidence: ?string}>}  $improvementFocus  BrandWheelImprovementFocusComposer::compose()の戻り値。「改善提案」ページの領域選択・3項目・比較サイトのevidence。自社・競合のいずれかがstatus!=='success'の場合はnull。
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
        public int $selfTotalMatched,
        public int $selfTotalMax,
        public int $competitorTotalMatched,
        public int $competitorTotalMax,
        public array $subElementComparison,
        public array $gapAnalysis,
        public ?array $improvementFocus,
    ) {}
}
