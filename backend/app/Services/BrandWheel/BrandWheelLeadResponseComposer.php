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
 */
class BrandWheelLeadResponseComposer
{
    /**
     * @return array{status: string, status_message: ?string, analyzed_url: string, axes: list<array<string, mixed>>, key_message: ?string, impression: ?string, source_pages: array<string, mixed>}
     */
    public function compose(?BrandWheelAnalysisResult $record, Website $website): array
    {
        $status = $this->resolveStatus($record);
        $isSuccess = $status === 'success';

        return [
            'status' => $status,
            'status_message' => $isSuccess ? null : (string) config("brand_wheel.status_messages.{$status}"),
            'analyzed_url' => (string) $website->normalized_url,
            'axes' => $isSuccess && $record !== null ? $this->buildAxes($record) : [],
            'key_message' => $isSuccess ? $record?->key_message : null,
            'impression' => $isSuccess ? $record?->impression : null,
            'source_pages' => (array) ($record?->source_pages ?? ['recruit_page' => null, 'home_page' => null]),
        ];
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
     * @return list<array{key: string, group: string, name: string, matched_count: int, max_count: int, matched_sub_elements: list<array{key: string, name: string}>}>
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
            ];
        }

        return $axes;
    }
}
