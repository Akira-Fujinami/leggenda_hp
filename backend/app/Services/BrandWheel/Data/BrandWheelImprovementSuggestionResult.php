<?php

namespace App\Services\BrandWheel\Data;

/**
 * 改善提案AIの最終結果。onePoint/recommendationは既存のEVIDENCE_MAX_CHARS等と
 * 同様の文字数上限がBrandWheelImprovementSuggestionResponseParser側で適用済み
 * (PDF/Wordのレイアウト崩れ防止、docs/lead-report-layout/README.mdの方針)。
 * focusSubElementKeysは実在する24キーのみに絞り込み済み(捏造防止の検証結果)。
 */
readonly class BrandWheelImprovementSuggestionResult
{
    /**
     * @param  list<string>  $focusSubElementKeys  AIが提言の根拠として言及した下位要素キー(実在検証済み、UI表示には使わない)
     */
    public function __construct(
        public ?string $onePoint,
        public ?string $recommendation,
        public array $focusSubElementKeys,
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
            'one_point' => $this->onePoint,
            'recommendation' => $this->recommendation,
            'focus_sub_element_keys' => $this->focusSubElementKeys,
            'provider' => $this->provider,
            'model' => $this->model,
            'is_mock' => $this->isMock,
            'prompt_version' => $this->promptVersion,
        ];
    }
}
