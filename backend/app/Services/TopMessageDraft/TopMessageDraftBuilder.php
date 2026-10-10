<?php

namespace App\Services\TopMessageDraft;

use App\Models\WebsiteAnalysis;
use App\Services\TopMessageInsight\TopMessagePageFinder;

/**
 * 依頼CS: 1社ぶんの素材ページのデータ。ページの選び方は依頼CQ追補のTopMessagePageFinder
 * (CQA-1・CQA-2)をそのまま使い、抜き出しは保存済みのHTMLだけから行う(AIも外部通信も無い)。
 *
 * メッセージの候補も制度の候補も1件も無いときはnull(ページを作らない)。
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
     *     programs: array{rows: list<array{name: string, excerpt: string, source: string}>, hidden: int, page_urls: list<string>},
     * }
     */
    public function build(WebsiteAnalysis $websiteAnalysis, string $companyName): ?array
    {
        $selection = $this->finder->find($websiteAnalysis);

        $message = null;
        if ($selection->message !== null) {
            $found = $this->extractor->messageCandidates((string) $selection->message->html);
            $message = [
                'title' => $this->extractor->sourceLabel($selection->message->url, $selection->message->title),
                'url' => $selection->message->url,
                'lines' => $found['lines'],
                'hidden' => max(0, $found['total'] - count($found['lines'])),
            ];
        }

        $programPages = array_slice($selection->programPages, 0, (int) config('top_message_draft.program_pages_max'));
        $candidates = $this->extractor->programCandidates($programPages);
        $max = (int) config('top_message_draft.program_total_max');
        $rows = array_map(
            fn (array $c) => ['name' => $c['name'], 'excerpt' => $c['excerpt'], 'source' => $c['source_title']],
            array_slice($candidates, 0, $max),
        );

        if (($message['lines'] ?? []) === [] && $rows === []) {
            return null;
        }

        return [
            'company_name' => $companyName,
            'message' => $message,
            'programs' => [
                'rows' => $rows,
                'hidden' => max(0, count($candidates) - count($rows)),
                'page_urls' => array_map(fn ($p) => $p->url, $programPages),
            ],
        ];
    }
}
