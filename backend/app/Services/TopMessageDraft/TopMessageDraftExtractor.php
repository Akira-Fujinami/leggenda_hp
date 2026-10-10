<?php

namespace App\Services\TopMessageDraft;

use App\Services\TopMessageInsight\TopMessagePage;

/**
 * 依頼CS-2/CS-3(AIは使わない・通信しない): 保存済みのHTMLから、メッセージの候補の文と
 * 制度の候補の一覧を機械的に抜き出す。同じ入力なら毎回同じ結果になる。
 *
 * 抜き出した文字は、HTMLの中の文字そのまま(空白の畳み込みだけ)。言い換え・要約・つなぎ合わせをしない。
 * 長すぎるときだけ、末尾に「…」を付けて切る(途中を省かない)。
 * 語・長さ・上限はすべて config('top_message_draft')。
 */
class TopMessageDraftExtractor
{
    /** 「文」の単位にする要素 / 見出し(メッセージ)の要素 */
    private const BLOCK_TAGS = ['p', 'li', 'dd', 'dt', 'td', 'th', 'blockquote', 'div', 'figcaption', 'ul', 'ol', 'table', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const SENTENCE_TAGS = ['p', 'li', 'dd', 'td', 'blockquote', 'div', 'figcaption'];

    /**
     * @return array{lines: list<string>, total: int} lines=選んだ候補(ページの中の出現順)、total=見つかった候補の総数(speakerを含む)
     */
    public function messageCandidates(string $html): array
    {
        $xpath = $this->load($html);
        if ($xpath === null) {
            return ['lines' => [], 'total' => 0];
        }

        $minLen = (int) config('top_message_draft.message_sentence_min_chars');
        $maxLen = (int) config('top_message_draft.message_sentence_max_chars');
        $headingMin = (int) config('top_message_draft.message_heading_min_chars');
        $excludePatterns = array_values(array_filter((array) config('top_message_draft.sentence_exclude_patterns'), 'is_string'));

        $order = 0;
        $speaker = null;
        $headings = [];
        $sentences = [];
        $seen = [];

        foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::p or self::li or self::dd or self::dt or self::td or self::th or self::blockquote or self::div or self::figcaption]') ?: [] as $element) {
            if (! $element instanceof \DOMElement || $this->isExcluded($element)) {
                continue;
            }
            $tag = strtolower($element->tagName);

            // 話し手の行(1行だけ): 見出し・段落・括りの葉(子に別の括りが無い要素)の行のうち、語を含む短いもの。
            if ($speaker === null && $this->isLeafBlock($element)) {
                foreach ($this->lines($element->textContent) as $line) {
                    if ($this->isSpeakerLine($line)) {
                        $speaker = ['text' => $line, 'order' => $order];
                        $seen[$line] = true;
                        break;
                    }
                }
            }

            if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                $text = $this->collapse($element->textContent);
                if ($text !== '' && mb_strlen($text) >= $headingMin && ! $this->isGeneric($text) && ! isset($seen[$text])) {
                    $seen[$text] = true;
                    $headings[] = ['text' => $this->clip($text, $maxLen), 'order' => $order];
                }
            } elseif (in_array($tag, self::SENTENCE_TAGS, true) && $this->isLeafBlock($element)) {
                foreach ($this->lines($element->textContent) as $line) {
                    foreach ($this->sentences($line) as $sentence) {
                        if (mb_strlen($sentence) < $minLen || isset($seen[$sentence]) || $this->matchesAny($sentence, $excludePatterns)) {
                            continue;
                        }
                        $seen[$sentence] = true;
                        $inRange = mb_strlen($sentence) <= $maxLen;
                        $sentences[] = [
                            'text' => $inRange ? $sentence : $this->clip($sentence, $maxLen),
                            'order' => $order,
                            // 範囲内 → 「」で始まる → 。で終わる の順に優先
                            'rank' => [$inRange ? 0 : 1, preg_match('/^[「『]/u', $sentence) ? 0 : 1, str_ends_with($sentence, '。') ? 0 : 1],
                        ];
                    }
                }
            }
            $order++;
        }

        $total = (int) config('top_message_draft.message_total_max');
        $picked = [];
        if ($speaker !== null && $total > 0) {
            $picked[] = $speaker;
        }
        foreach (array_slice($headings, 0, max(0, min((int) config('top_message_draft.message_headings_max'), $total - count($picked)))) as $heading) {
            $picked[] = $heading;
        }
        usort($sentences, fn (array $a, array $b) => ($a['rank'] <=> $b['rank']) ?: ($a['order'] <=> $b['order']));
        foreach (array_slice($sentences, 0, max(0, $total - count($picked))) as $sentence) {
            $picked[] = $sentence;
        }
        usort($picked, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return [
            'lines' => array_map(fn (array $row) => $row['text'], $picked),
            'total' => ($speaker !== null ? 1 : 0) + count($headings) + count($sentences),
        ];
    }

    /**
     * ページの優先順に、制度の候補(見出し h2〜h4・dt・th)と、その直後の本文(p・dd・td)の先頭を抜き出す。
     * 同じ文字の候補は最初のものだけ。
     *
     * @param  list<TopMessagePage>  $pages
     * @return list<array{name: string, excerpt: string, source_title: string, source_url: string}>
     */
    public function programCandidates(array $pages): array
    {
        $candidates = [];
        $seen = [];

        foreach ($pages as $page) {
            foreach ($this->programsOf((string) $page->html) as $row) {
                $key = $this->normalize($row['name']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $candidates[] = ['name' => $row['name'], 'excerpt' => $row['excerpt'], 'source_title' => $this->sourceLabel($page->url, $page->title), 'source_url' => $page->url];
            }
        }

        return $candidates;
    }

    /**
     * @return list<array{name: string, excerpt: string}>
     */
    private function programsOf(string $html): array
    {
        $xpath = $this->load($html);
        if ($xpath === null) {
            return [];
        }

        $minLen = (int) config('top_message_draft.program_name_min_chars');
        $maxLen = (int) config('top_message_draft.program_name_max_chars');
        $excerptMax = (int) config('top_message_draft.program_excerpt_max_chars');

        $tokens = [];
        foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::dt or self::th or self::p or self::dd or self::td]') ?: [] as $element) {
            if ($element instanceof \DOMElement) {
                $tokens[] = $element;
            }
        }

        $rows = [];
        foreach ($tokens as $i => $element) {
            $tag = strtolower($element->tagName);
            if (! in_array($tag, ['h2', 'h3', 'h4', 'dt', 'th'], true) || $this->isExcluded($element)) {
                continue;
            }
            $name = $this->collapse($element->textContent);
            $length = mb_strlen($name);
            if ($length < $minLen || $length > $maxLen || $this->isGeneric($name) || preg_match('/^[\d\s\p{P}\p{S}]+$/u', $name) === 1) {
                continue;
            }

            $excerpt = '';
            if ($tag === 'dt') {
                $excerpt = $this->siblingText($element, 'dd');
            } elseif ($tag === 'th') {
                $excerpt = $this->siblingText($element, 'td');
            } else {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    $next = $tokens[$j];
                    $nextTag = strtolower($next->tagName);
                    if (in_array($nextTag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'dt', 'th'], true)) {
                        break;
                    }
                    if ($this->isExcluded($next)) {
                        continue;
                    }
                    $text = $this->collapse($next->textContent);
                    if ($text !== '') {
                        $excerpt = $text;
                        break;
                    }
                }
            }
            $excerpt = $excerpt === $name ? '' : $this->clip($excerpt, $excerptMax);

            $rows[] = ['name' => $name, 'excerpt' => $excerpt];
        }

        return $rows;
    }

    private function siblingText(\DOMElement $element, string $tag): string
    {
        for ($node = $element->nextSibling; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof \DOMElement && strtolower($node->tagName) === $tag) {
                return $this->collapse($node->textContent);
            }
        }

        return '';
    }

    private function load(string $html): ?\DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOENT | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // <br>は改行にする(行の境目を保つ)。
        foreach (iterator_to_array($dom->getElementsByTagName('br')) as $br) {
            $br->parentNode?->replaceChild($dom->createTextNode("\n"), $br);
        }

        return new \DOMXPath($dom);
    }

    /**
     * サイト共通のヘッダー・フッター・ナビ・サイドバーなどの内側か。
     */
    private function isExcluded(\DOMElement $element): bool
    {
        $tags = array_map('strtolower', (array) config('top_message_draft.excluded_ancestor_tags'));
        $roles = array_map('strtolower', (array) config('top_message_draft.excluded_ancestor_roles'));
        $pattern = (string) config('top_message_draft.excluded_ancestor_class_pattern');

        for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (in_array(strtolower($node->tagName), $tags, true) || in_array(strtolower($node->getAttribute('role')), $roles, true)) {
                return true;
            }
            if ($pattern !== '' && $node->tagName !== 'html' && $node->tagName !== 'body'
                && preg_match($pattern, $node->getAttribute('class').' '.$node->getAttribute('id')) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 中に、別の括り(段落・リスト・見出し・div等)を持たない要素。
     */
    private function isLeafBlock(\DOMElement $element): bool
    {
        foreach (self::BLOCK_TAGS as $tag) {
            if ($element->getElementsByTagName($tag)->length > 0) {
                return false;
            }
        }

        return true;
    }

    private function isSpeakerLine(string $line): bool
    {
        if ($line === '' || mb_strlen($line) > (int) config('top_message_draft.speaker_max_chars')) {
            return false;
        }
        foreach ((array) config('top_message_draft.speaker_exclude_words') as $word) {
            if ((string) $word !== '' && mb_stripos($line, (string) $word) !== false) {
                return false;
            }
        }
        foreach ((array) config('top_message_draft.speaker_words') as $word) {
            if ((string) $word !== '' && mb_stripos($line, (string) $word) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isGeneric(string $text): bool
    {
        $key = $this->normalize($text);
        foreach ((array) config('top_message_draft.generic_headings') as $generic) {
            if ($key === $this->normalize((string) $generic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 比較用(空白・記号を除き、大文字小文字を区別しない)。表示には使わない。
     */
    private function normalize(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $text));
    }

    /**
     * @return list<string> 空でない行(空白を畳んだもの)
     */
    private function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = $this->collapse($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * 「」『』の外にある「。」で区切る(区切りの「。」は文に残す)。
     *
     * @return list<string>
     */
    private function sentences(string $line): array
    {
        $sentences = [];
        $current = '';
        $depth = 0;
        foreach (mb_str_split($line) as $char) {
            $current .= $char;
            if ($char === '「' || $char === '『') {
                $depth++;
            } elseif (($char === '」' || $char === '』') && $depth > 0) {
                $depth--;
            } elseif ($char === '。' && $depth === 0) {
                $sentences[] = trim($current);
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $sentences[] = trim($current);
        }

        return $sentences;
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{3000}\x{00A0}]+/u', ' ', $text));
    }

    /**
     * 上限を超えるときだけ、上限の文字数で切って末尾に「…」を付ける(途中を省かない)。
     */
    private function clip(string $text, int $max): string
    {
        return $max > 0 && mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)).'…' : $text;
    }

    /**
     * 出典: ページのタイトルの、区切り(階層図と同じconfig)より前の部分。タイトルが無ければURLのパス。
     */
    public function sourceLabel(string $url, ?string $title): string
    {
        $title = trim((string) $title);
        if ($title !== '') {
            foreach ((array) config('admin_comparison_pptx.site_hierarchy_tree_title_separators', []) as $separator) {
                $pos = mb_strpos($title, (string) $separator);
                if ($pos !== false && $pos > 0) {
                    $title = trim(mb_substr($title, 0, $pos));
                }
            }
            if ($title !== '') {
                return $title;
            }
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path !== '' ? $path : $url;
    }
}
