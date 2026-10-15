<?php

namespace App\Services\BranchSurveyItems;

use App\Services\Analysis\HtmlSeoAnalyzer;

/**
 * 依頼CT: 枝(またはTOPページ)ごとの、AIに渡す本文を保存済みのHTMLから作る(通信しない)。
 *
 * 各ページの本文(ナビゲーションを除く)を段落に分け、短い段落(メニューの語など)と重複を除き、
 * 上限(config('branch_survey_items.input_max_chars'))に収まるまで、ページごとに順番に1段落ずつ取る
 * (依頼CH-3と同じ考え方。1ページが予算を独占しない。ページの中は文字数の多い段落から)。
 * 渡した段落をそのままつないだものが「その枝の入力の本文」で、サーバー側の確認はこれと突き合わせる。
 */
class BranchSurveyItemsInputBuilder
{
    public function __construct(private readonly HtmlSeoAnalyzer $htmlAnalyzer = new HtmlSeoAnalyzer) {}

    /**
     * @param  list<string>  $htmls  ページの順
     */
    public function build(array $htmls): string
    {
        $limit = (int) config('branch_survey_items.input_max_chars', 12000);
        $minChars = (int) config('branch_survey_items.paragraph_min_chars', 4);

        $seen = [];
        $queues = [];
        foreach ($htmls as $html) {
            $paragraphs = [];
            foreach (preg_split('/\R/u', $this->htmlAnalyzer->extractBodyText($html, excludeNavigation: true)) ?: [] as $line) {
                $line = trim($line);
                if (mb_strlen($line) < $minChars || isset($seen[$line])) {
                    continue;
                }
                $seen[$line] = true;
                $paragraphs[] = $line;
            }
            // 文字数の多い段落から(同じ長さは出現順 ―― 安定ソート)。
            $indexed = array_map(null, array_keys($paragraphs), $paragraphs);
            usort($indexed, fn (array $a, array $b) => [mb_strlen($b[1]), $a[0]] <=> [mb_strlen($a[1]), $b[0]]);
            $queues[] = array_map(fn (array $pair) => $pair[1], $indexed);
        }

        $chosen = [];
        $used = 0;
        for ($round = 0; ; $round++) {
            $any = false;
            foreach ($queues as $queue) {
                if (! isset($queue[$round])) {
                    continue;
                }
                $any = true;
                $length = mb_strlen($queue[$round]) + 1;
                if ($used + $length > $limit) {
                    continue;
                }
                $chosen[] = $queue[$round];
                $used += $length;
            }
            if (! $any) {
                break;
            }
        }

        return implode("\n", $chosen);
    }
}
