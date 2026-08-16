<?php

namespace App\Support\Report;

/**
 * Word/PDF生成の両方が参照する唯一のデータ構造。文面・レイアウトの変更は
 * WordReportGenerator/PdfReportGeneratorそれぞれの中だけで行い、判定
 * ロジックや文面の分岐ロジック自体はここに持ち込まない(既存の計算結果を
 * そのまま保持するだけの読み取り専用DTO)。
 *
 * スクリーンショットは意図的に含めない
 * (リード分析ではCaptureScreenshotJob自体を省略するため)。
 *
 * 2026-08-08: 7ページ構成への再編に伴い、内部の7カテゴリ/4観点由来の
 * フィールド(selfScore/competitorScore/overallSummaryText/comparisonSentence/
 * perspectives/topRecommendations)と「サイトから読み取れた記述」
 * (selfBrandWheelEvidenceItems)・「サイトで触れられていなかった項目」
 * (gapAnalysis)ページ用フィールドを削除した(該当ページ自体を削除したため。
 * 4観点自体はJSON API/画面向けにLeadPerspectiveComposerとして引き続き
 * 存在するが、レポートはもう参照しない)。レーダー画像は自社単独・競合単独・
 * 対比表用の重ね図の3種類に分割した(旧brandWheelRadarPngは廃止)。
 */
readonly class ReportViewModel
{
    /**
     * @param  ?array<string, mixed>  $brandWheelSelf  BrandWheelLeadResponseComposer::compose()の戻り値
     * @param  ?array<string, mixed>  $brandWheelCompetitor  BrandWheelLeadResponseComposer::compose()の戻り値、競合なしの場合はnull
     * @param  array{self_points: list<string>, competitor_points: list<string>, one_point: ?array{key: string, text: string}}  $brandWheelComparison  BrandWheelComparisonSummaryComposerの戻り値。self_points/competitor_pointsはそれぞれ3・4ページ目の「サマリー」箇条書き。one_pointは「改善提案」ページ冒頭のワンポイントとして使う。
     * @param  ?string  $brandWheelRadarPngSelf  自社単独のレーダー図PNG(3ページ目)。ラスタライズ失敗時・自社がstatus!=='success'のときはnull。
     * @param  ?string  $brandWheelRadarPngCompetitor  競合単独のレーダー図PNG(4ページ目)。競合が無い/status!=='success'のときはnull。
     * @param  ?string  $brandWheelRadarPngComparison  自社×競合を重ねたレーダー図PNG(対比表ページ冒頭)。競合が無い/いずれかがstatus!=='success'でないときはnull。
     * @param  int  $selfTotalMatched  自社の24項目中の○(本文照合済み)件数合計。「自社ページの分析結果」「対比表」「改善提案」が必ず同じ値を参照する(同じソースから1回だけ集計)。
     * @param  int  $selfTotalMax  自社の24項目の分母(config('brand_wheel.axes.*.sub_elements')の総数。24を固定値で書かない)。
     * @param  int  $competitorTotalMatched  競合の○件数合計。競合なし/非successの場合は0。
     * @param  int  $competitorTotalMax  競合の分母。競合なし/非successの場合は0。
     * @param  int  $selfTotalLabelOnly  自社の△(見出し・リンクラベルのみ)件数合計。対比表の「(参考)」表示用、合計には含めない。
     * @param  int  $competitorTotalLabelOnly  競合の△件数合計。
     * @param  list<array{axis_key: string, axis_name: string, group: string, sub_key: string, sub_name: string, definition: string, self_matched: bool, competitor_matched: bool, self_state: string, competitor_state: string}>  $subElementComparison  BrandWheelSubElementComparisonComposer::compose()の戻り値(24項目、config順)。対比表の○△－表示の唯一の情報源。self_matched/competitor_matchedは○のみtrue(△はfalseのまま、改善提案の選定ロジックに使うため変更しない)。self_state/competitor_stateは'matched'|'label_only'|'none'。
     * @param  list<array{group: string, label: string, self_count: int, competitor_count: int, max_count: int, verdict: string}>  $groupTotals  BrandWheelSubElementComparisonComposer::groupTotals()の戻り値(2026-08-17追加)。競合が読み取れない場合は空配列。
     * @param  list<string>  $comparisonOverview  BrandWheelComparisonSummaryComposer::comparisonOverview()の戻り値(2026-08-17追加)。「○△－の対比表」ページ冒頭の比較サマリー。競合が読み取れない場合は空配列。
     * @param  ?array{selected_group: string, groups: list<array{group: string, label: string, self_count: int, competitor_count: int, max_count: int}>, items: list<array{axis_name: string, sub_name: string, definition: string, competitor_evidence: ?string}>}  $improvementFocus  BrandWheelImprovementFocusComposer::compose()の戻り値。「改善提案」ページの領域選択・3項目・比較サイトのevidence。自社・競合の両方がstatus==='success'の場合のみ非null(競合が無い/読み取れない場合は$improvementFocusSelfOnlyを使う)。
     * @param  ?array{selected_group: string, groups: list<array{group: string, label: string, self_count: int, max_count: int}>, items: list<array{axis_name: string, sub_name: string, definition: string, self_reason: string}>}  $improvementFocusSelfOnly  BrandWheelImprovementFocusComposer::composeSelfOnly()の戻り値。競合が無い/読み取れない場合の「改善提案」ページ(自社の「－」「△」項目のみで構成、比較サイトのevidenceは含まない)。自社がstatus==='success'でない、または自社24項目すべてが○の場合はnull(2026-08-10追加)。
     * @param  ?string  $improvementOnePoint  「改善提案」ページ冒頭のワンポイント(2026-08-17追加)。改善提案AI(BrandWheelImprovementSuggestion)の生成結果があればそれを使い、無ければ既存の決定的ロジック($brandWheelComparison['one_point']['text'])にフォールバックする。
     * @param  ?string  $improvementRecommendation  改善提案AIが生成した詳細提言パラグラフ(2026-08-17追加、結論→なぜ→具体的にの3〜5文)。未生成/失敗時はnull(その場合、既存のグループ差バー＋証拠カードのみで成立する)。
     */
    public function __construct(
        public string $companyDisplayName,
        public string $generatedAtLabel,
        public string $selfWebsiteUrl,
        public ?string $competitorWebsiteUrl,
        public bool $isPartial,
        public ?array $brandWheelSelf,
        public ?array $brandWheelCompetitor,
        public array $brandWheelComparison,
        public ?string $brandWheelRadarPngSelf,
        public ?string $brandWheelRadarPngCompetitor,
        public ?string $brandWheelRadarPngComparison,
        public int $selfTotalMatched,
        public int $selfTotalMax,
        public int $competitorTotalMatched,
        public int $competitorTotalMax,
        public int $selfTotalLabelOnly,
        public int $competitorTotalLabelOnly,
        public array $subElementComparison,
        public array $groupTotals,
        public array $comparisonOverview,
        public ?array $improvementFocus,
        public ?array $improvementFocusSelfOnly,
        public ?string $improvementOnePoint,
        public ?string $improvementRecommendation,
    ) {}
}
