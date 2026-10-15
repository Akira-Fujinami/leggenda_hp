<?php

namespace App\Services\BranchSurveyItems;

use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use App\Services\Report\CandidateSurveyCatalog;

/**
 * 依頼CT: 保存済みの判定結果(BranchSurveyItemsStore)を、階層図の木($tree)に合わせてスライド用の形にする。
 * 保存済みのファイルを読んだ結果を受け取るだけで、AIも外部通信も無い(PPTXのダウンロード時にここを通る)。
 *
 * 結果のファイルが無い($resultがnull)ときは$treeを変えない ―― 階層図は従来の描き方のまま。
 * ファイルがあるときは$tree['survey']を足す:
 *  branches: $tree['branches']と同じ並びで、{state: 'ok'|'failed', items: 表示する項目名(割合の高い順・上限まで), more: 上限を超えた件数, count: 確認できた件数}
 *  top: ?{state, items, more, count}(TOPページが無ければnull)
 *  headline: ?string(枝ごとの件数に偏りがあるときだけ)
 *  footnote: string(スライド下の注記に足す1行)
 */
class BranchSurveyItemsPresenter
{
    public function __construct(private readonly CandidateSurveyCatalog $catalog = new CandidateSurveyCatalog) {}

    /**
     * @param  array<string, mixed>  $tree
     * @param  ?array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function apply(array $tree, ?array $result): array
    {
        if ($result === null) {
            return $tree;
        }

        $names = [];
        foreach ($this->catalog->options() as $option) {
            $names[$option['key']] = $option['name'];
        }

        $stored = [];
        foreach ((array) ($result['branches'] ?? []) as $row) {
            if (is_array($row) && isset($row['key'])) {
                $stored[(string) $row['key']] = $row;
            }
        }

        $jobFailed = isset($result['job_failed']);
        $branches = [];
        foreach ((array) ($tree['branches'] ?? []) as $branch) {
            $branches[] = $this->cell($stored[AdminComparisonSiteHierarchyBuilder::surveyBranchKey($branch)] ?? null, $names);
        }

        $top = null;
        if (is_array($result['top'] ?? null)) {
            $top = $this->cell($result['top'], $names);
        } elseif ($jobFailed) {
            $top = $this->cell(null, $names);
        }

        $tree['survey'] = [
            'branches' => $branches,
            'top' => $top,
            'headline' => $this->headline((array) ($tree['branches'] ?? []), $branches),
            'footnote' => (string) config('branch_survey_items.footnote'),
        ];

        return $tree;
    }

    /**
     * @param  ?array<string, mixed>  $row  保存済みの1対象(無ければ判定できず)
     * @param  array<string, string>  $names  キー => 調査の項目名(割合の高い順)
     * @return array{state: string, items: list<string>, more: int, count: int}
     */
    private function cell(?array $row, array $names): array
    {
        if ($row === null || ($row['status'] ?? null) !== 'ok') {
            return ['state' => 'failed', 'items' => [], 'more' => 0, 'count' => 0];
        }

        $found = array_column((array) ($row['items'] ?? []), 'key');
        $items = [];
        foreach ($names as $key => $name) {
            if (in_array($key, $found, true)) {
                $items[] = $name;
            }
        }

        $max = max(1, (int) config('branch_survey_items.items_per_branch_max', 4));

        return ['state' => 'ok', 'items' => array_slice($items, 0, $max), 'more' => max(0, count($items) - $max), 'count' => count($items)];
    }

    /**
     * 枝が2つ以上あり、すべての枝の判定ができていて、最も多い枝の件数が、他のすべての枝の件数の
     * config('branch_survey_items.skew_ratio')倍以上のときだけ一文を返す(評価・推測の言葉は使わない)。
     *
     * @param  list<array<string, mixed>>  $treeBranches
     * @param  list<array{state: string, items: list<string>, more: int, count: int}>  $cells
     */
    private function headline(array $treeBranches, array $cells): ?string
    {
        if (count($cells) < 2) {
            return null;
        }
        foreach ($cells as $cell) {
            if ($cell['state'] !== 'ok') {
                return null;
            }
        }

        $counts = array_column($cells, 'count');
        $maxCount = max($counts);
        if ($maxCount <= 0) {
            return null;
        }

        $topIndex = array_search($maxCount, $counts, true);
        $ratio = (float) config('branch_survey_items.skew_ratio', 2);
        $others = [];
        foreach ($counts as $i => $count) {
            if ($i === $topIndex) {
                continue;
            }
            if ($maxCount < $ratio * $count) {
                return null;
            }
            $others[] = (string) $treeBranches[$i]['name'];
        }

        $limit = (int) config('branch_survey_items.skew_other_names_max', 4);
        $text = implode((string) config('branch_survey_items.skew_other_names_separator', '・'), array_slice($others, 0, $limit));
        if (count($others) > $limit) {
            $text .= (string) config('branch_survey_items.skew_other_names_more', 'ほか');
        }

        return sprintf((string) config('branch_survey_items.skew_template'), (string) $treeBranches[$topIndex]['name'], $text);
    }
}
