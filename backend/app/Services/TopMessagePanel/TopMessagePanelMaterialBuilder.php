<?php

namespace App\Services\TopMessagePanel;

use App\Models\WebsiteAnalysis;
use App\Services\TopMessageDraft\TopMessageDraftExtractor;
use App\Services\TopMessageDraft\TopMessageProgramFilter;
use App\Services\TopMessageInsight\TopMessagePageFinder;

/**
 * 依頼CZ-1: 1社ぶんの、パネルの材料(AIを使わない・通信しない。保存済みのHTMLだけから、同じ入力なら同じ結果)。
 * ページの選び方は依頼CS・CQ追補のTopMessagePageFinderのまま。
 *
 * メッセージ: 本文を文単位に分け、ID(m-1…)を振る。「関連記事」などの見出しの下・リンクの文字だけの文を除く
 *             (TopMessageDraftExtractor::panelMessage())。
 * 制度の候補: ID(p-1…)・制度名・抜粋(全文、上限config)・出典のページ名。除くもの:
 *   - no_excerpt: 抜粋が無い(名前だけの行)
 *   - generic_excerpt: 抜粋が「関連記事」などの汎用の見出しの言葉だけ
 *   - job_page: 募集要項・求人一覧のページ(ページ名・パスの語はconfig)から取った候補(制度ではなく求人のため)
 *   - name_separator: 名前に区切り記号(「｜」「|」。config)を含む候補(一覧・職種のリンクの形で、制度の名前ではないため。依頼CZ追補)
 *   - recruit_category_name: 名前が採用区分の言葉(「新卒」「中途」など。config)と一致する候補(依頼CZ追補2)
 *   - short_excerpt: 抜粋がconfigの文字数に満たない候補(職種名・ツール名だけの抜粋。依頼CZ追補2)
 * 語・割合・上限はすべて config('top_message_draft')(制度の整理は素材ページと共通のTopMessageProgramFilter、依頼DA)と config('top_message_panel')。
 */
class TopMessagePanelMaterialBuilder
{
    public function __construct(
        private readonly TopMessagePageFinder $finder,
        private readonly TopMessageDraftExtractor $extractor,
        private readonly TopMessageProgramFilter $filter,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     reason: ?string,
     *     speaker: ?string,
     *     message_title: ?string,
     *     message_url: ?string,
     *     sentences: list<array{id: string, text: string}>,
     *     programs: list<array{id: string, name: string, excerpt: string, source: string}>,
     *     dropped: array{message: array<string, int>, programs: array<string, int>},
     * }
     */
    public function build(WebsiteAnalysis $websiteAnalysis): array
    {
        $selection = $this->finder->find($websiteAnalysis);

        $material = [
            'status' => 'ok',
            'reason' => null,
            'speaker' => null,
            'message_title' => null,
            'message_url' => null,
            'sentences' => [],
            'programs' => [],
            'dropped' => [
                'message' => ['related_section' => 0, 'link_text' => 0, 'title' => 0, 'short_or_long' => 0],
                'programs' => ['no_excerpt' => 0, 'generic_excerpt' => 0, 'job_page' => 0, 'name_separator' => 0, 'recruit_category_name' => 0, 'short_excerpt' => 0],
            ],
        ];

        if ($selection->message === null) {
            return ['status' => 'skipped', 'reason' => 'no_message_page'] + $material;
        }

        $message = $this->extractor->panelMessage((string) $selection->message->html, $selection->message->title);
        $material['speaker'] = $message['speaker'];
        $material['message_title'] = $this->extractor->sourceLabel($selection->message->url, $selection->message->title);
        $material['message_url'] = $selection->message->url;
        $material['dropped']['message'] = $message['dropped'];
        $material['sentences'] = array_slice($message['sentences'], 0, (int) config('top_message_panel.prompt_sentences_max'));

        $material['programs'] = $this->programs($selection, $material['dropped']['programs']);

        if ($material['sentences'] === []) {
            $material['status'] = 'skipped';
            $material['reason'] = 'no_sentences';
        } elseif ($material['programs'] === []) {
            $material['status'] = 'skipped';
            $material['reason'] = 'no_programs';
        }

        return $material;
    }

    /**
     * @param  array<string, int>  $dropped  理由別の件数(参照で足す)
     * @return list<array{id: string, name: string, excerpt: string, source: string}>
     */
    private function programs(object $selection, array &$dropped): array
    {
        $pages = array_slice($selection->programPages, 0, (int) config('top_message_draft.program_pages_max'));
        $perPage = $this->extractor->programItemsByPage($pages, (int) config('top_message_draft.panel_program_excerpt_max_chars'));

        // 制度でない行の除外は、素材ページ(既定)と共通の1か所(TopMessageProgramFilter)。
        $perPage = $this->filter->filter($perPage, $pages, $dropped);

        $queues = [];
        foreach ($perPage as $p => $page) {
            $queues[$p] = [];
            foreach ($page['items'] as $item) {
                if ($item['kind'] === 'program') {
                    $queues[$p][] = ['name' => $item['name'], 'excerpt' => trim($item['excerpt']), 'source' => $page['source_title']];
                }
            }
        }
        // 上限があるときは、各ページから1件ずつ順番に取る(1ページで枠が埋まらない)。取ったものは、ページの順・ページの中の出現順に並べ直してIDを振る。
        $max = (int) config('top_message_panel.prompt_programs_max');
        $chosen = [];
        $count = 0;
        for ($round = 0; $count < $max; $round++) {
            $any = false;
            foreach ($queues as $p => $queue) {
                if (isset($queue[$round])) {
                    $any = true;
                    if ($count < $max) {
                        $chosen[$p][$round] = $queue[$round];
                        $count++;
                    }
                }
            }
            if (! $any) {
                break;
            }
        }

        $programs = [];
        ksort($chosen);
        foreach ($chosen as $rows) {
            ksort($rows);
            foreach ($rows as $row) {
                $programs[] = ['id' => 'p-'.(count($programs) + 1)] + $row;
            }
        }

        return $programs;
    }
}
