<?php

namespace App\Services\BrandWheel\Data;

/**
 * 改善提案AIの最終結果。文字数上限はBrandWheelImprovementSuggestionResponseParser
 * 側で適用済み(PDF/Wordのレイアウト崩れ防止、docs/lead-report-layout/README.md
 * の方針)。focusSubElementKeys/gapClosing/differentiationOpportunitiesは
 * 実在する24キーのみに絞り込み済み(捏造防止の検証結果)。
 *
 * 2026-08-18(クライアントレビュー対応): 「情報が不足しているので追加して
 * ください」という一般論から脱却させるため、onePoint/recommendation(単一
 * 段落)に加えて構造化フィールドを追加した。recommendationは後方互換のため
 * 引き続き生成・保持するが、新しいレポートUIでは表示せず、reason/
 * recommendedContents/midTermActionを個別に表示する(依頼者指定の構成:
 * ワンポイント→理由→自社と競合の差(既存)→具体的に追加すべき情報→中長期施策)。
 */
readonly class BrandWheelImprovementSuggestionResult
{
    /**
     * @param  list<string>  $focusSubElementKeys  AIが提言の根拠として言及した下位要素キー(実在検証済み、UI表示には使わない)
     * @param  list<string>  $recommendedContents  具体的に追加すべき情報(最大3項目)
     * @param  ?string  $implementationDifficulty  'low'|'medium'|'high'|null
     * @param  ?string  $candidateImpact  'low'|'medium'|'high'|null
     * @param  list<string>  $gapClosing  内部分類: 競合にあり自社に無い情報を補う対象の下位要素名(UIラベルとしては出さない)
     * @param  list<string>  $differentiationOpportunities  内部分類: 自社・競合とも手薄で自社が先に充実させれば差別化になる対象の下位要素名(UIラベルとしては出さない)
     */
    public function __construct(
        public ?string $onePoint,
        public ?string $recommendation,
        public array $focusSubElementKeys,
        public ?string $reason,
        public array $recommendedContents,
        public ?string $midTermAction,
        public ?bool $quickWin,
        public ?string $implementationDifficulty,
        public ?string $candidateImpact,
        public array $gapClosing,
        public array $differentiationOpportunities,
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
            'reason' => $this->reason,
            'recommended_contents' => $this->recommendedContents,
            'mid_term_action' => $this->midTermAction,
            'quick_win' => $this->quickWin,
            'implementation_difficulty' => $this->implementationDifficulty,
            'candidate_impact' => $this->candidateImpact,
            'gap_closing' => $this->gapClosing,
            'differentiation_opportunities' => $this->differentiationOpportunities,
            'provider' => $this->provider,
            'model' => $this->model,
            'is_mock' => $this->isMock,
            'prompt_version' => $this->promptVersion,
        ];
    }
}
