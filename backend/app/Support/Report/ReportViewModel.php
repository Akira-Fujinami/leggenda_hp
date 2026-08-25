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
     * @param  ?array{selected_group: string, groups: list<array{group: string, label: string, self_count: int, max_count: int}>, items: list<array{axis_name: string, sub_name: string, definition: string, self_reason: string}>, items_source?: string}  $improvementFocusSelfOnly  BrandWheelImprovementFocusComposer::composeSelfOnly()の戻り値をベースに、ReportViewModelBuilderが'items'・'items_source'を上書きしたもの。競合が無い/読み取れない場合の「改善提案」ページ。'groups'(棒グラフの数値)は常にcomposeSelfOnly()由来(無改修)。'items'(3枚のカード)は、改善提案AIがfocus_sub_element_keysで有効な項目を挙げていれば'items_source'=>'ai'としてAI由来の項目に差し替わり(依頼Q-2、2026-08-25追加)、AI未生成/失敗/有効な項目0件の場合は'items_source'=>'rule'のままcomposeSelfOnly()の元のitems(自社の「－」「△」項目、決定的な規則で選定)を使う。'items_source'キー自体は、ReportViewModelBuilderを経由しない古い/直接構築されたViewModel(単体テストのfixture等)には存在しないことがあり、その場合はBlade/WordReportGenerator側で'rule'扱いにフォールバックする。自社がstatus==='success'でない、または自社24項目すべてが○の場合はnull(2026-08-10追加)。
     * @param  ?string  $improvementOnePoint  「改善提案」ページ冒頭のワンポイント(2026-08-17追加)。改善提案AI(BrandWheelImprovementSuggestion)の生成結果があればそれを使い、無ければ既存の決定的ロジック($brandWheelComparison['one_point']['text'])にフォールバックする。
     * @param  ?string  $improvementRecommendation  改善提案AIが生成した旧形式の詳細提言パラグラフ(結論→なぜ→具体的にの3〜5文、後方互換用に保持)。2026-08-18以降の新レポートUIでは表示しない(reason/recommendedContents/midTermActionを個別に表示する)。
     * @param  ?string  $improvementReason  ワンポイントの理由(2026-08-18追加、2〜3文)。未生成/失敗時はnull。
     * @param  list<string>  $improvementRecommendedContents  改善提案AIが生成した「具体的に追加すべき情報」(2026-08-18追加、最大3項目)。依頼Q-2(2026-08-25)でBlade/WordReportGenerator側の表示は廃止した(3枚のカードと内容が重複するため)が、フィールド自体・AI生成・DB保存は変更していない(呼び出し側の互換性のため保持)。
     * @param  ?string  $improvementMidTermAction  中長期施策(2026-08-18追加、該当する場合のみ1〜2文)。未生成/失敗/該当なしの場合はnull。
     * @param  ?string  $selfLowContentNotice  自社の合計matched件数がconfig('brand_wheel.comparison_sufficiency_threshold')未満のときの但し書き(2026-08-25追加、修正5)。config('brand_wheel.self_low_content_notice')。閾値以上のときはnull。
     * @param  bool  $crawlSiteEnabled  依頼Q-1(2026-08-25追加)。Analysis.crawl_siteをそのまま複製したもの。前置きページの「本分析の対象範囲」の文言(config('brand_wheel.crawl_disabled_scope_notice')/config('brand_wheel.crawl_enabled_scope_notice'))の出し分けにのみ使う。
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
        public ?string $improvementReason,
        public array $improvementRecommendedContents,
        public ?string $improvementMidTermAction,
        public ?string $selfLowContentNotice,
        public bool $crawlSiteEnabled,
    ) {}
}
