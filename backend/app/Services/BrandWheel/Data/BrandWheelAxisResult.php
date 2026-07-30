<?php

namespace App\Services\BrandWheel\Data;

/**
 * 6軸のうち1軸分の判定結果。stateは必ずパーサが matchedSubElements の
 * 検証後件数から config('brand_wheel.state_thresholds') に従って再計算した
 * ものであり、AIの自己申告を一切信用しない。
 *
 * claimedSubElementCountは「検証前にAIが該当ありと申告した件数」(軸内で
 * キー重複除去後)を保持する。state='unread'なのにclaimedSubElementCountが
 * 閾値を満たす値だった場合、「AIはread/partialを自己申告したが検証で
 * 全滅した」という事実がこの2つの値の比較から再構成できる ―― AIに
 * 別途"state"ラベルを自己申告させて信用しない、という設計より一段階
 * 厳格(状態ラベルという集約結果そのものを一切受け取らない)。
 */
readonly class BrandWheelAxisResult
{
    /**
     * @param  list<BrandWheelSubElementMatch>  $matchedSubElements  検証を通過し生存したもの
     * @param  list<BrandWheelDiscardedSubElement>  $discardedSubElements  検証で破棄されたもの
     */
    public function __construct(
        public string $axisKey,
        public array $matchedSubElements,
        public array $discardedSubElements,
        public int $claimedSubElementCount,
        public string $state,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'axis_key' => $this->axisKey,
            'matched_sub_elements' => array_map(fn (BrandWheelSubElementMatch $m) => $m->toArray(), $this->matchedSubElements),
            'discarded_sub_elements' => array_map(fn (BrandWheelDiscardedSubElement $d) => $d->toArray(), $this->discardedSubElements),
            'claimed_sub_element_count' => $this->claimedSubElementCount,
            'state' => $this->state,
        ];
    }
}
