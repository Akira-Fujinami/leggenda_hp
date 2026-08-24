<?php

namespace App\Services\BrandWheel;

use App\Models\BrandWheelAnalysisResult;

/**
 * BrandWheelAnalysisResultから、2通目メール(BrandWheelAnalysisCompletedMail)の
 * Bladeビューへ渡すデータを組み立てる。config('brand_wheel.*')のキーから
 * 日本語ラベルへの解決や文言の整形をここに集約し、Bladeビュー自体は
 * ロジックを持たない単純な表示専用にする(HTMLの表だけで内容が伝わるか、
 * という回帰テストがこの配列の内容を直接検証できるようにするため)。
 *
 * axis_state_countsは分数(N/6)で表示しない ―― 「6軸中2軸」は2÷6=33点として
 * 読まれかねず、採点しないという設計原則に反する(2026-07-30の指摘)。
 * read/partial/unreadの内訳を文章で併記する形式にする。
 */
class BrandWheelEmailContentBuilder
{
    private const string DISCLAIMER =
        '本内容はAIによる参考情報です。サイト上の記述から読み取れた範囲を示すものであり、'.
        '貴社の魅力そのものの有無を判定したものではありません。商談前に必ず内容をご確認ください。';

    /**
     * @var array<string, string>
     */
    private const STATE_LABELS = [
        'read' => '読み取れました',
        'partial' => '一部読み取れました',
        'unread' => '読み取れませんでした',
    ];

    /**
     * @var array<string, string>
     */
    private const SOURCE_PAGE_STATUS_LABELS = [
        'read' => '読み取れました',
        'absent' => 'サイト上に見つかりませんでした',
        'unreadable' => '取得できませんでした(保存データの読み込みに失敗しました)',
        // 優先度4-3(2026-08-24追加)。トップページ自身が既に採用ページである
        // 自己参照のため、トップページの内容とまとめて評価しています
        // (取得失敗ではない ―― 'unreadable'と混同されないよう別ラベルにする)。
        'self_reference' => 'トップページ自身が採用ページのため、トップページの内容とまとめて評価しました',
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(BrandWheelAnalysisResult $result, string $companyName, string $contactName, string $targetUrl): array
    {
        $sourcePages = (array) ($result->source_pages ?? []);
        $leadEmailBlockedReason = app(BrandWheelLeadEmailContentBuilder::class)->blockedReason($result);

        $base = [
            'insufficientInput' => $result->status === 'insufficient_input',
            'companyName' => $companyName,
            'contactName' => $contactName,
            'targetUrl' => $targetUrl,
            // リード企業向けメールを送っていない場合、なぜ送らなかったかを
            // 社内スタッフへ伝える(2026-07-30の要件: 送らない条件に該当したら
            // 社員向けには理由を通知する)。
            'leadEmailBlockedReason' => $leadEmailBlockedReason,
            'sourcePages' => [
                'recruit_page' => [
                    'nameJa' => '採用ページ',
                    'label' => self::SOURCE_PAGE_STATUS_LABELS[$sourcePages['recruit_page'] ?? ''] ?? '不明',
                ],
                'home_page' => [
                    'nameJa' => 'トップページ',
                    'label' => self::SOURCE_PAGE_STATUS_LABELS[$sourcePages['home_page'] ?? ''] ?? '不明',
                ],
            ],
        ];

        if ($base['insufficientInput']) {
            return $base;
        }

        $axesConfig = (array) config('brand_wheel.axes', []);
        $qualityDimensionsConfig = (array) config('brand_wheel.quality_dimensions', []);
        $axesByKey = collect((array) ($result->axes ?? []))->keyBy('axis_key');
        $qualityNotes = (array) ($result->quality_dimension_notes ?? []);

        $axes = [];
        foreach ($axesConfig as $axisKey => $axisDefinition) {
            $axisResult = $axesByKey->get($axisKey);
            $state = is_array($axisResult) && in_array($axisResult['state'] ?? null, ['read', 'partial', 'unread'], true)
                ? $axisResult['state']
                : 'unread';
            $subElementLabels = (array) ($axisDefinition['sub_elements'] ?? []);
            $matched = is_array($axisResult) ? (array) ($axisResult['matched_sub_elements'] ?? []) : [];

            $axes[] = [
                'nameJa' => (string) ($axisDefinition['name_ja'] ?? $axisKey),
                'state' => $state,
                'stateLabel' => self::STATE_LABELS[$state],
                'matchedSubElements' => array_map(fn (array $m) => [
                    'label' => (string) ($subElementLabels[$m['key']] ?? $m['key']),
                    'evidence' => (string) $m['evidence'],
                ], $matched),
            ];
        }

        $counts = (array) ($result->axis_state_counts ?? ['read' => 0, 'partial' => 0, 'unread' => 0]);

        $qualityDimensionNotes = [];
        foreach ($qualityDimensionsConfig as $key => $definition) {
            if (isset($qualityNotes[$key]) && is_string($qualityNotes[$key]) && $qualityNotes[$key] !== '') {
                $qualityDimensionNotes[] = [
                    'nameJa' => (string) ($definition['name_ja'] ?? $key),
                    'note' => $qualityNotes[$key],
                ];
            }
        }

        return $base + [
            'axisStateCounts' => $counts,
            'axisStateSummaryText' => sprintf(
                '読み取れた%d軸／一部読み取れた%d軸／読み取れなかった%d軸',
                $counts['read'] ?? 0, $counts['partial'] ?? 0, $counts['unread'] ?? 0,
            ),
            'axes' => $axes,
            'coreValue' => [
                'readable' => (bool) $result->core_value_readable,
                'label' => $result->core_value_readable ? '読み取れました' : '読み取れませんでした',
                'evidence' => $result->core_value_evidence,
            ],
            'qualityDimensionNotes' => $qualityDimensionNotes,
            'cautions' => (array) ($result->cautions ?? []),
            'disclaimer' => self::DISCLAIMER,
            'altText' => $this->altText($counts),
        ];
    }

    /**
     * 画像が読み込まれない環境で最初に(場合によっては唯一)目に入る文字列。
     * 図はあくまで補助情報であり、判定根拠は本文の表を見るよう誘導する。
     * 先頭に装飾記号は付けない(スクリーンリーダーが無意味な文字として
     * 読み上げてしまうため、2026-07-30の指摘)。色・良し悪しを示す語も使わない。
     *
     * @param  array{read: int, partial: int, unread: int}  $counts
     */
    private function altText(array $counts): string
    {
        return sprintf(
            'ブランド・ホイール6軸診断図(図は補助情報です。判定根拠は本文の表をご確認ください): '.
            '読み取れた軸%d、一部読み取れた軸%d、読み取れなかった軸%d(6軸中)。',
            $counts['read'] ?? 0, $counts['partial'] ?? 0, $counts['unread'] ?? 0,
        );
    }
}
