<?php

namespace App\Services\BrandWheel\Data;

/**
 * ブランド・ホイール(6軸)分析の最終結果。数値スコア・ランキング・
 * パーセンテージ・5段階評価は一切持たない ―― axisStateCountsは
 * read/partial/unreadの内訳をそのまま保持する事実のカウントであり
 * (2026-07-29の指摘により、read+partialの合算フィールドは廃止した ――
 * 3状態を区別するために設けた状態が、合算によって再び失われてしまうため。
 * ヘキサゴンも3状態を塗り分けて描画するため、合算値は図と数字を食い違わせる)、
 * 100点満点への換算やランキングに使ってはならない(このDTOの型として
 * それ以外の数値フィールドを持たせないことで、既存の採点系(7カテゴリ
 * 100点)への混入を構造的に防ぐ)。
 *
 * 2026-08-03: リード向け画面の主役がブランド・ホイールへ変わったため、
 * axes/quality_dimension_notes等は社内向け画面・レポートに加え、リード向け
 * 画面(LeadAnalysisController::results()の`brand_wheel`)にも表示される
 * (ただしevidence原文はリード向けAPIには含めない ―― BrandWheelLeadResponse
 * Composer参照)。keyMessage/impressionはリード向け画面下部の紺帯専用に
 * 追加した2項目で、下位要素のような「原文にこの語句があるか」という個別の
 * 主張ではなく、サイト全体から読み取れる要約・印象のためevidence実在検証の
 * 対象外(BrandWheelAnalysisResponseParser参照)。impressionは社外に出る
 * 文章のため、config('brand_wheel.forbidden_phrases')を含む項目はparserが
 * 個別に除外する。
 *
 * 2026-08-08(prompt_version v7〜): impressionをstringから
 * list<string>(2〜4件)へ変更した。旧版は「〜という情報は読み取れません
 * でした」のように診断結果(下位要素の該当有無)と重複した提言調になって
 * いたため、候補者が受け取る印象だけを評価・提言を含めず列挙する形にする
 * (レポート「自社/競合サイトの分析結果」ページで箇条書き表示、ユーザー
 * 指摘)。
 */
readonly class BrandWheelAnalysisResult
{
    /**
     * @param  list<BrandWheelAxisResult>  $axes  6軸分、config('brand_wheel.axes')と同じキー順
     * @param  list<string>  $impression  候補者が受け取る印象の列挙(2〜4件、評価・提言を含まない)。1件も生き残らなかった場合は空配列。
     * @param  array<string, string>  $qualityDimensionNotes  quality_dimensionsキー => AIの所見(自由記述、evidence検証はしない)
     * @param  list<string>  $cautions
     * @param  array{read: int, partial: int, unread: int}  $axisStateCounts  合計は常に6
     */
    public function __construct(
        public array $axes,
        public BrandWheelCoreValueResult $coreValue,
        public ?string $keyMessage,
        public array $impression,
        public ?string $positiveImpression,
        public ?string $negativeImpression,
        public array $qualityDimensionNotes,
        public array $cautions,
        public array $axisStateCounts,
        public string $provider,
        public ?string $model,
        public bool $isMock,
        public ?string $promptVersion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'axes' => array_map(fn (BrandWheelAxisResult $a) => $a->toArray(), $this->axes),
            'core_value' => $this->coreValue->toArray(),
            'key_message' => $this->keyMessage,
            'impression' => $this->impression,
            'positive_impression' => $this->positiveImpression,
            'negative_impression' => $this->negativeImpression,
            'quality_dimension_notes' => $this->qualityDimensionNotes,
            'cautions' => $this->cautions,
            'axis_state_counts' => $this->axisStateCounts,
            'provider' => $this->provider,
            'model' => $this->model,
            'is_mock' => $this->isMock,
            'prompt_version' => $this->promptVersion,
        ];
    }
}
