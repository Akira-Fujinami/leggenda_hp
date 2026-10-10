<?php

namespace App\Services\TopMessageDraft;

use App\Models\WebsiteAnalysis;
use App\Services\TopMessageInsight\TopMessagePageFinder;

/**
 * 依頼CS: 1社ぶんの素材ページのデータ。ページの選び方は依頼CQ追補のTopMessagePageFinder
 * (CQA-1・CQA-2、依頼CSA-5)をそのまま使い、抜き出しは保存済みのHTMLだけから行う(AIも外部通信も無い)。
 *
 * メッセージの候補も制度の候補も1件も無いときはnull(ページを作らない)。
 *
 * 依頼CSA-3: 制度の候補は、ページの優先順に「各ページから1件ずつ」順番に取る(1ページで枠が埋まらない)。
 * スライドに並べるときは、出典のページごとにまとめる(ページは優先順、ページの中は出現順)。
 * 依頼CSA-4: 分類の見出しは件数に数えず、その下の候補が1件でも残るときだけ、区切りの行として並べる。
 */
class TopMessageDraftBuilder
{
    public function __construct(
        private readonly TopMessagePageFinder $finder,
        private readonly TopMessageDraftExtractor $extractor,
    ) {}

    /**
     * @return ?array{
     *     company_name: string,
     *     message: array{title: ?string, url: string, lines: list<string>, hidden: int}|null,
     *     programs: array{rows: list<array{kind: string, name: string, excerpt: string, source: string, order?: int, level?: int}>, hidden: int, page_urls: list<string>, candidate_total: int, dropped: array{colon: int, title: int, category: int}},
     * }
     */
    public function build(WebsiteAnalysis $websiteAnalysis, string $companyName): ?array
    {
        $selection = $this->finder->find($websiteAnalysis);

        $message = null;
        $droppedTitleInMessage = 0;
        if ($selection->message !== null) {
            $found = $this->extractor->messageCandidates((string) $selection->message->html, $selection->message->title);
            $droppedTitleInMessage = $found['dropped_title'];
            $message = [
                'title' => $this->extractor->sourceLabel($selection->message->url, $selection->message->title),
                'url' => $selection->message->url,
                'lines' => $found['lines'],
                'hidden' => max(0, $found['total'] - count($found['lines'])),
            ];
        }

        $programPages = array_slice($selection->programPages, 0, (int) config('top_message_draft.program_pages_max'));
        $perPage = $this->extractor->programItemsByPage($programPages);
        [$rows, $candidateTotal, $shownCount] = $this->arrange($perPage, (int) config('top_message_draft.program_total_max'));

        $dropped = ['colon' => 0, 'title' => 0, 'category' => 0];
        foreach ($perPage as $page) {
            foreach ($dropped as $reason => $count) {
                $dropped[$reason] = $count + $page['dropped'][$reason];
            }
        }
        $dropped['title_in_message'] = $droppedTitleInMessage;

        if (($message['lines'] ?? []) === [] && $rows === []) {
            return null;
        }

        return [
            'company_name' => $companyName,
            'message' => $message,
            'programs' => [
                'rows' => $rows,
                'hidden' => max(0, $candidateTotal - $shownCount),
                'page_urls' => array_map(fn ($p) => $p->url, $programPages),
                'candidate_total' => $candidateTotal,
                'dropped' => $dropped,
            ],
        ];
    }

    /**
     * 各ページから1件ずつ順番に$max件まで選び、出典のページごとにまとめた表の行にする。
     *
     * @param  list<array{url: string, source_title: string, items: list<array{kind: string, level: int, name: string, excerpt: string}>, dropped: array{colon: int, title: int, category: int}}>  $perPage
     * @return array{0: list<array{kind: string, name: string, excerpt: string, source: string, order?: int, level?: int}>, 1: int, 2: int} [行, 候補の総数(分類の見出しを除く), 選んだ候補の数]
     */
    private function arrange(array $perPage, int $max): array
    {
        // ページごとの、制度の候補(分類の見出しを除く)の項目番号を出現順に。
        $queues = [];
        $candidateTotal = 0;
        foreach ($perPage as $p => $page) {
            $queues[$p] = [];
            foreach ($page['items'] as $i => $item) {
                if ($item['kind'] === 'program') {
                    $queues[$p][] = $i;
                    $candidateTotal++;
                }
            }
        }

        // 1ページ目の1件目、2ページ目の1件目、…、1ページ目の2件目、…
        $chosen = [];
        $count = 0;
        for ($round = 0; $count < $max; $round++) {
            $any = false;
            foreach ($queues as $p => $queue) {
                if (isset($queue[$round])) {
                    $any = true;
                    if ($count < $max) {
                        $chosen[$p][$queue[$round]] = $count;
                        $count++;
                    }
                }
            }
            if (! $any) {
                break;
            }
        }

        $rows = [];
        foreach ($perPage as $p => $page) {
            $items = $page['items'];
            foreach ($items as $i => $item) {
                if ($item['kind'] === 'program') {
                    if (isset($chosen[$p][$i])) {
                        // order: 選んだ順(1ページ目の1件目=0、2ページ目の1件目=1、…)。スライドに収まらないときは、この順の後ろから外す。
                        $rows[] = ['kind' => 'program', 'name' => $item['name'], 'excerpt' => $item['excerpt'], 'source' => $page['source_title'], 'order' => $chosen[$p][$i]];
                    }

                    continue;
                }
                // 分類の見出し: 次の「同じか浅い分類の見出し」までの間に、選んだ候補が1件でもあれば区切りの行として出す。
                $hasChosen = false;
                for ($j = $i + 1; $j < count($items); $j++) {
                    if ($items[$j]['kind'] === 'category' && $items[$j]['level'] <= $item['level']) {
                        break;
                    }
                    if ($items[$j]['kind'] === 'program' && isset($chosen[$p][$j])) {
                        $hasChosen = true;
                        break;
                    }
                }
                if ($hasChosen) {
                    $rows[] = ['kind' => 'category', 'name' => $item['name'], 'excerpt' => '', 'source' => $page['source_title'], 'level' => $item['level']];
                }
            }
        }

        return [$rows, $candidateTotal, $count];
    }
}
