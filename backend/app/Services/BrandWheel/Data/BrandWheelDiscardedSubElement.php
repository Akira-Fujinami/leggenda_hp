<?php

namespace App\Services\BrandWheel\Data;

/**
 * AIが該当すると申告したが、パーサ側の検証で破棄された下位要素の記録。
 * 「無言での切り捨ては禁止」の要件により、なぜ破棄されたかの理由を残す。
 * reason: 'unknown_sub_element'(その軸に存在しないキー。2026-08-05の
 *         sub_elementsフラット24キー形式への変更で、AIはconfigの24キー
 *         以外を申告できなくなったため新規には生成されないが、本番DBの
 *         既存行(v3以前生成分)のaxes JSONにこの値が残っているため
 *         列挙・バリデーション・DTOからは削除しない) |
 *         'empty_evidence'(抜粋が空文字/非文字列) |
 *         'evidence_not_found'(抜粋が正規化後の原文に実在しない) |
 *         'duplicate_evidence'(同一evidenceが他の下位要素の根拠と重複しており、
 *         config('brand_wheel.axes')の並び順で後にくる側が破棄された。
 *         2026-08-04追加) |
 *         'label_only_evidence'(evidenceが原文には実在するが、見出し・
 *         リンクラベル文字列そのものと完全一致 ―― 「その単語がページにある」を
 *         「それについて書かれている」証拠にすり替える循環論法を防ぐ。
 *         リンクラベルは定義上ナビゲーションのため、完全一致すれば長さに
 *         関わらず破棄する。見出しは20文字以上の完全一致なら本物の文章の
 *         ことがあるため、20文字未満の場合のみ破棄する(本文中の短い正当な
 *         一文は対象外)。2026-08-05追加、prompt_version v5〜。20文字閾値を
 *         リンクラベルにも適用していた設計を、種類ごとの条件へ分離
 *         (2026-08-05、v6) |
 *         'definition_echo'(evidenceが【下位要素チェックリスト】の
 *         sub_element_definitions=定義文そのものと完全一致。AIが「該当なし」と
 *         判断しづらい状況で、実際のサイト本文の代わりにプロンプト自体の
 *         定義文を返す実行単位のモード崩壊が実測で確認された。定義文は通常
 *         サイト本文には存在しないためこの判定が無くてもevidence_not_foundとして
 *         最終的には破棄されており誤ったmatched=trueには至らないが、
 *         evidence_not_foundと区別することでこの現象の発生頻度を観測できる
 *         ようにする。2026-08-24追加、prompt_version v10〜)。
 */
readonly class BrandWheelDiscardedSubElement
{
    public function __construct(
        public string $key,
        public ?string $evidence,
        public string $reason,
    ) {}

    /**
     * @return array{key: string, evidence: string|null, reason: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'evidence' => $this->evidence, 'reason' => $this->reason];
    }
}
