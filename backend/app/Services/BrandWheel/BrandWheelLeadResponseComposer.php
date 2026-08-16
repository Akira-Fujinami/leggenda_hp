<?php

namespace App\Services\BrandWheel;

use App\Models\BrandWheelAnalysisResult;
use App\Models\Website;
use Illuminate\Support\Collection;

/**
 * リード向け画面(LeadAnalysisController::results()の`brand_wheel`)・
 * Word/PDFレポートの両方が参照する唯一の定義元(2026-08-03)。
 *
 * statusはフロントエンドの分岐用の閉じた集合(success/pending/
 * insufficient_input/recruit_page_unreadable/no_matched_content/error)。
 * status_messageは画面・レポートに出す文言そのもので、フロント側は独自の
 * 文言を持たない(config('brand_wheel.status_messages')が唯一の定義元)。
 *
 * 判定の優先順位は固定(上から順に該当した最初のものを採用、
 * BrandWheelLeadResponseComposerTestで固定):
 * error → pending → recruit_page_unreadable → insufficient_input →
 * no_matched_content → success
 *
 * evidence(原文の抜粋)は一切含めない ―― 社員向けメールには必要だが、
 * 画面に出す必要はなく、含めればそれだけ他社サイトの本文が外部に出る
 * (2026-08-03のユーザー指摘)。
 *
 * 2026-08-08: レポートの○△－対比表(3値化)対応で、axes各要素に
 * label_only_sub_elements(見出し・リンクラベルのみを根拠に破棄された
 * ―― BrandWheelDiscardedSubElement::reason==='label_only_evidence')を
 * 追加した。evidence文字列自体は従来どおり含めない(key/nameのみ、
 * matched_sub_elementsと同じ形)ため、画面側の非開示方針は崩していない。
 * 既存フィールドは一切変更していないため、frontend/src/features/lead/は
 * 無改修のまま動く(追加フィールドは画面側が単に参照しないだけ)。
 *
 * impressionは配列化した(OpenAiBrandWheelAnalysisProvider v7〜、
 * 候補者が受け取る印象を2〜4件の列挙にする変更)。画面(frontend)は
 * 依然としてimpressionを1つの文字列として表示する設計のため、既存の
 * 'impression'キーは(件数・文字数を切り詰めない)完全な配列を読点で連結した
 * 文字列のまま返す(画面は無改修)。
 *
 * 2026-08-08追記: レポート専用の'impression_items'は、'impression'とは別に
 * 最大IMPRESSION_ITEM_MAX_COUNT件・1件あたり最大IMPRESSION_ITEM_MAX_CHARS文字
 * まで切り詰める。実データ検証(自社ページの分析結果ページ)で、印象を1文の
 * 地の文から2〜4件の箇条書きへ変更したことで紺帯(darkband)の高さが伸び、
 * ページ下部の余白(実測で残り約2mm)を超えて紺帯全体が丸ごと次ページへ
 * あふれる不具合が実PDF確認で見つかった(ユーザー指摘)。件数・文字数の
 * 上限を設けることで、紺帯の最大高さを事前に見積もれる状態にする
 * (ユーザー承認: 「上限付き」案)。画面(frontend)向けの'impression'は
 * この上限の対象外(紙面制約が無いため、AIの出力をそのまま見せる)。
 */
class BrandWheelLeadResponseComposer
{
    private const IMPRESSION_ITEM_MAX_COUNT = 3;

    private const IMPRESSION_ITEM_MAX_CHARS = 45;

    // 2026-08-17追加: positive_impression/negative_impression(各1〜2文)の
    // レポート向け文字数上限。impression_itemsと同じ理由(紙面の高さ予算を
    // 事前に見積もれるようにするため)。実PDF確認(worst-case: 自社/競合とも
    // 24項目中複数該当・長い企業名の実データ)で90字は紺帯(darkband)が
    // ページに収まりきらず丸ごと次ページへあふれることが判明したため、
    // EVIDENCE_MAX_CHARSと同じ経緯(110→70→60)で65字まで縮小した。
    private const IMPRESSION_TEXT_MAX_CHARS = 65;

    /**
     * @return array{status: string, status_message: ?string, analyzed_url: string, axes: list<array<string, mixed>>, key_message: ?string, impression: ?string, impression_items: list<string>, positive_impression: ?string, negative_impression: ?string, source_pages: array<string, mixed>}
     */
    public function compose(?BrandWheelAnalysisResult $record, Website $website): array
    {
        $status = $this->resolveStatus($record);
        $isSuccess = $status === 'success';
        $impressionItems = $isSuccess ? array_values((array) ($record?->impression ?? [])) : [];

        return [
            'status' => $status,
            'status_message' => $isSuccess ? null : (string) config("brand_wheel.status_messages.{$status}"),
            'analyzed_url' => (string) $website->normalized_url,
            'axes' => $isSuccess && $record !== null ? $this->buildAxes($record) : [],
            'key_message' => $isSuccess ? $record?->key_message : null,
            'impression' => $impressionItems !== [] ? implode('、', $impressionItems) : null,
            'impression_items' => $this->capImpressionItemsForReport($impressionItems),
            // 2026-08-17追加: レポート「候補者に与える印象」の表示はこちらへ
            // 切り替える(依頼者指定 ―― ポジティブ/ネガティブを分けて示す)。
            // 画面/JSON APIの後方互換のためimpression/impression_itemsは維持する。
            'positive_impression' => $isSuccess ? $this->capImpressionTextForReport($record?->positive_impression) : null,
            'negative_impression' => $isSuccess ? $this->capImpressionTextForReport($record?->negative_impression) : null,
            'source_pages' => (array) ($record?->source_pages ?? ['recruit_page' => null, 'home_page' => null]),
        ];
    }

    private function capImpressionTextForReport(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return mb_strlen($text) > self::IMPRESSION_TEXT_MAX_CHARS
            ? mb_substr($text, 0, self::IMPRESSION_TEXT_MAX_CHARS).'…'
            : $text;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function capImpressionItemsForReport(array $items): array
    {
        return array_map(
            fn (string $item) => mb_strlen($item) > self::IMPRESSION_ITEM_MAX_CHARS
                ? mb_substr($item, 0, self::IMPRESSION_ITEM_MAX_CHARS).'…'
                : $item,
            array_slice($items, 0, self::IMPRESSION_ITEM_MAX_COUNT),
        );
    }

    /**
     * 判定の優先順位は固定(上から順に該当した最初のものを採用)。
     */
    private function resolveStatus(?BrandWheelAnalysisResult $record): string
    {
        if ($record === null || $record->status === 'error') {
            return 'error';
        }

        if (! in_array($record->status, ['success', 'insufficient_input'], true)) {
            // 'pending'/'running'(まだ生成中)。
            return 'pending';
        }

        $sourcePages = (array) ($record->source_pages ?? []);
        if (($sourcePages['recruit_page'] ?? null) === 'unreadable') {
            // こちら側の取得失敗(相手のサイトに問題があるかのような書き方に
            // しない、2026-08-03のユーザー指摘)。insufficient_inputより先に
            // 判定する ―― insufficient_inputでもsource_pagesは記録されるため。
            return 'recruit_page_unreadable';
        }

        if ($record->status === 'insufficient_input') {
            return 'insufficient_input';
        }

        // ここまで来ればstatus==='success'。全6軸が0件の場合は
        // 「魅力のない会社」に見えてしまう図を出さない(既存方針と同じ:
        // 「読み取れなかった」を「魅力が無い」と混同しない)。
        $totalMatched = collect((array) ($record->axes ?? []))
            ->sum(fn (array $axis) => count($axis['matched_sub_elements'] ?? []));

        if ($totalMatched === 0) {
            return 'no_matched_content';
        }

        return 'success';
    }

    /**
     * @return list<array{key: string, group: string, name: string, matched_count: int, max_count: int, matched_sub_elements: list<array{key: string, name: string}>, label_only_sub_elements: list<array{key: string, name: string}>}>
     */
    private function buildAxes(BrandWheelAnalysisResult $record): array
    {
        $axesConfig = (array) config('brand_wheel.axes', []);
        /** @var Collection<string, array<string, mixed>> $resultsByKey */
        $resultsByKey = collect((array) ($record->axes ?? []))->keyBy('axis_key');

        $axes = [];
        foreach ($axesConfig as $axisKey => $definition) {
            $subElementNames = (array) ($definition['sub_elements'] ?? []);
            $axisResult = $resultsByKey->get($axisKey);
            $matched = is_array($axisResult) ? (array) ($axisResult['matched_sub_elements'] ?? []) : [];
            $discarded = is_array($axisResult) ? (array) ($axisResult['discarded_sub_elements'] ?? []) : [];
            $labelOnly = array_values(array_filter($discarded, fn (array $d) => ($d['reason'] ?? null) === 'label_only_evidence'));

            $axes[] = [
                'key' => $axisKey,
                'group' => (string) ($definition['group'] ?? ''),
                'name' => (string) ($definition['name_ja'] ?? $axisKey),
                // 満点は下位要素の件数から動的に算出する(ハードコードしない
                // ―― config('brand_wheel.axes.*.sub_elements')の件数が変われば
                // コード変更無しに追従する、2026-08-03のユーザー指摘)。
                'matched_count' => count($matched),
                'max_count' => count($subElementNames),
                'matched_sub_elements' => array_values(array_map(fn (array $m) => [
                    'key' => $m['key'],
                    'name' => $subElementNames[$m['key']] ?? $m['key'],
                ], $matched)),
                // ○△－対比表の△用(2026-08-08)。evidence文字列は含めない
                // (matched_sub_elementsと同じ非開示方針)。
                'label_only_sub_elements' => array_values(array_map(fn (array $d) => [
                    'key' => $d['key'],
                    'name' => $subElementNames[$d['key']] ?? $d['key'],
                ], $labelOnly)),
            ];
        }

        return $axes;
    }
}
