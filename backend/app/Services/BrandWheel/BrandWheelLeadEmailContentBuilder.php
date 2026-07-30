<?php

namespace App\Services\BrandWheel;

use App\Models\BrandWheelAnalysisResult;

/**
 * リード企業向け2通目メールの送信可否判定とビューデータ組み立て。
 *
 * 社内スタッフ向け(BrandWheelEmailContentBuilder)とは目的・送信条件・内容が
 * すべて異なるため独立したクラスにする ―― 品質所見(quality_dimension_notes)の
 * 生の記述やスコア・分数形式は絶対に含めない。フリーテキストの新規生成は
 * 一切行わず、config('brand_wheel.axes')のstate/evidence(実在検証済み)を
 * テンプレート化するだけにとどめる ―― AIに新しい文章を書かせる箇所を作らない
 * ことが、禁止語混入という失敗モードそのものを構造的に減らす
 * (2026-07-30の指摘: 「テストは最後の防波堤であり、そこで落ちる状態を
 * 常態にしない」)。
 */
class BrandWheelLeadEmailContentBuilder
{
    /**
     * @var array<string, string>
     */
    private const STATE_LABELS = [
        'read' => '読み取れました',
        'partial' => '一部読み取れました',
        'unread' => '読み取れませんでした',
    ];

    /**
     * リード企業向けメールを送ってよいかどうか。以下のいずれかに該当する場合は
     * 送らない(2026-07-30の要件):
     * - status !== 'success'(insufficient_input/error/pending/runningを含む)
     * - source_pages.recruit_page === 'unreadable'(採用ページの取得に失敗)
     * - 6軸すべてunread(read+partialの合計が0) ――
     *   「6軸すべて読み取れませんでした」という内容を社外に送ることは
     *   いかなる場合もしない、という絶対のルール。
     */
    public function canSend(BrandWheelAnalysisResult $result): bool
    {
        return $this->blockedReason($result) === null;
    }

    /**
     * 送らない場合の理由(社内スタッフへの通知用)。送ってよい場合はnull。
     */
    public function blockedReason(BrandWheelAnalysisResult $result): ?string
    {
        if ($result->status !== 'success') {
            return match ($result->status) {
                'insufficient_input' => 'サイトから十分な情報が読み取れなかったため',
                'error' => 'ブランド・ホイール評価の処理でエラーが発生したため',
                default => 'ブランド・ホイール評価がまだ完了していないため',
            };
        }

        $sourcePages = (array) ($result->source_pages ?? []);
        if (($sourcePages['recruit_page'] ?? null) === 'unreadable') {
            return '採用ページの内容を取得できなかったため';
        }

        $counts = (array) ($result->axis_state_counts ?? []);
        $readOrPartial = (int) ($counts['read'] ?? 0) + (int) ($counts['partial'] ?? 0);
        if ($readOrPartial === 0) {
            return '6軸すべてサイトから読み取れなかったため';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \LogicException canSend()がfalseの場合は呼び出し禁止
     */
    public function build(BrandWheelAnalysisResult $result, string $targetUrl): array
    {
        if (! $this->canSend($result)) {
            throw new \LogicException('BrandWheelLeadEmailContentBuilder::build() was called despite canSend() being false.');
        }

        $axesConfig = (array) config('brand_wheel.axes', []);
        $axesByKey = collect((array) ($result->axes ?? []))->keyBy('axis_key');

        $axes = [];
        foreach ($axesConfig as $axisKey => $axisDefinition) {
            $axisResult = $axesByKey->get($axisKey);
            $state = is_array($axisResult) && in_array($axisResult['state'] ?? null, ['read', 'partial', 'unread'], true)
                ? $axisResult['state']
                : 'unread';
            $matched = is_array($axisResult) ? (array) ($axisResult['matched_sub_elements'] ?? []) : [];
            $firstEvidence = $matched[0]['evidence'] ?? null;

            $axes[] = [
                'nameJa' => (string) ($axisDefinition['name_ja'] ?? $axisKey),
                'stateLabel' => self::STATE_LABELS[$state],
                // 最小限の根拠抜粋(最初の1件のみ、社員向けのような全件列挙はしない)。
                'evidence' => is_string($firstEvidence) ? $firstEvidence : null,
            ];
        }

        $counts = (array) ($result->axis_state_counts ?? ['read' => 0, 'partial' => 0, 'unread' => 0]);

        return [
            'targetUrl' => $targetUrl,
            'axes' => $axes,
            'axisStateSummaryText' => sprintf(
                '読み取れた%d軸／一部読み取れた%d軸／読み取れなかった%d軸',
                $counts['read'] ?? 0, $counts['partial'] ?? 0, $counts['unread'] ?? 0,
            ),
            'sourceDescription' => $this->sourceDescription((array) ($result->source_pages ?? [])),
        ];
    }

    /**
     * @param  array<string, string>  $sourcePages
     */
    private function sourceDescription(array $sourcePages): string
    {
        $readPages = [];
        if (($sourcePages['home_page'] ?? null) === 'read') {
            $readPages[] = 'トップページ';
        }
        if (($sourcePages['recruit_page'] ?? null) === 'read') {
            $readPages[] = '採用ページ';
        }

        if ($readPages === []) {
            return 'サイトの記述をもとに作成しています。';
        }

        return implode('・', $readPages).'の記述から読み取りました。';
    }
}
