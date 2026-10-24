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
     * 依頼CSA-2: ページの<title>と同じ文字(<title>を区切りで分けた各部分と同じ文字を含む)は、候補にしない。
     *
     * 依頼DA: パネルの材料(panelMessage)と同じ整理を効かせる。「関連記事」などの見出し(config related_headings)の下(次の同じか上の見出しまで)と、
     * リンクの文字だけの文(リンクの中の文字がconfig link_text_ratio以上)は、候補にしない。外した数は dropped_related に理由別に持つ。
     *
     * @return array{lines: list<string>, total: int, dropped_title: int, dropped_related: array{related_section: int, link_text: int}} lines=選んだ候補(ページの中の出現順)、total=見つかった候補の総数(speakerを含む)、dropped_title=ページの題名と同じで外した数
     */
    public function messageCandidates(string $html, ?string $pageTitle = null): array
    {
        $xpath = $this->load($html);
        if ($xpath === null) {
            return ['lines' => [], 'total' => 0, 'dropped_title' => 0, 'dropped_related' => ['related_section' => 0, 'link_text' => 0]];
        }
        $titleKeys = $this->titleKeys($xpath, $pageTitle);
        $droppedTitle = 0;
        $droppedRelated = ['related_section' => 0, 'link_text' => 0];
        $related = array_map(fn ($w) => $this->normalize((string) $w), (array) config('top_message_draft.related_headings'));
        $linkRatio = (float) config('top_message_draft.link_text_ratio');
        $skipLevel = null;

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

            // 依頼DA: 「関連記事」などの見出しの下は、次の同じか上の見出しまで、候補にしない。
            if ($this->startsRelatedSection($skipLevel, $element, $related)) {
                continue;
            }

            // 話し手の行(1行だけ): 見出し・段落・括りの葉(子に別の括りが無い要素)の行のうち、語を含む短いもの。
            if ($speaker === null && $skipLevel === null && $this->isLeafBlock($element)) {
                foreach ($this->lines($element->textContent) as $line) {
                    if ($this->isSpeakerLine($line)) {
                        $speaker = ['text' => $line, 'order' => $order];
                        $seen[$line] = true;
                        break;
                    }
                }
            }

            $isCandidateBlock = in_array($tag, ['h1', 'h2', 'h3'], true) || (in_array($tag, self::SENTENCE_TAGS, true) && $this->isLeafBlock($element));
            if ($isCandidateBlock) {
                $blockLines = $this->lines($element->textContent);
                $count = in_array($tag, ['h1', 'h2', 'h3'], true) ? count($blockLines) : $this->countSentences($blockLines);
                if ($skipLevel !== null) {
                    $droppedRelated['related_section'] += $count;
                    $order++;

                    continue;
                }
                $ratioOfLinks = $this->linkTextRatio($element);
                if ($ratioOfLinks > 0 && $ratioOfLinks >= $linkRatio) {
                    $droppedRelated['link_text'] += $count;
                    $order++;

                    continue;
                }
            }

            if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                $text = $this->collapse($element->textContent);
                if ($text !== '' && mb_strlen($text) >= $headingMin && ! $this->isGeneric($text) && ! isset($seen[$text])) {
                    if (isset($titleKeys[$this->looseKey($text)])) {
                        $droppedTitle++;
                    } elseif (! $this->isLabelOnly($text)) {
                        $seen[$text] = true;
                        $headings[] = ['text' => $this->clip($text, $maxLen), 'order' => $order];
                    }
                }
            } elseif (in_array($tag, self::SENTENCE_TAGS, true) && $this->isLeafBlock($element)) {
                foreach ($this->lines($element->textContent) as $line) {
                    foreach ($this->sentences($line) as $sentence) {
                        if (mb_strlen($sentence) < $minLen || isset($seen[$sentence]) || $this->matchesAny($sentence, $excludePatterns)) {
                            continue;
                        }
                        if (isset($titleKeys[$this->looseKey($sentence)])) {
                            $droppedTitle++;

                            continue;
                        }
                        // 依頼DI: 見出しの語だけの文(「Top Message トップメッセージ」など)は使わない。
                        if ($this->isLabelOnly($sentence)) {
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
            'dropped_title' => $droppedTitle,
            'dropped_related' => $droppedRelated,
        ];
    }

    /**
     * 依頼CZ-1: パネル用のメッセージの材料。ページの本文を文単位に分け、ページの上の順にID(m-1, m-2…)を振る。
     * 文は、HTMLの中の文字そのまま(空白の畳み込みだけ)。言い換え・要約・つなぎ合わせ・「…」での切り詰めをしない。
     *
     * 除くもの(理由別に件数を数える):
     *  - related_section: 「関連記事」などの見出し(config related_headings)の下、次の同じか上の見出しまで
     *  - link_text: 要素の文字のうち、リンク(a)の中の文字がconfig link_text_ratio以上(記事タイトルのリンクなど)
     *  - title: ページの<title>と同じ(依頼CSA-2と同じ)
     *  - short_or_long: 短すぎる文・長すぎる文・除外の型(sentence_exclude_patterns)
     * 話し手(役職・氏名)は、素材ページ(CS)と同じ取り方で1行。
     *
     * @return array{speaker: ?string, sentences: list<array{id: string, text: string}>, dropped: array{related_section: int, link_text: int, title: int, short_or_long: int}}
     */
    public function panelMessage(string $html, ?string $pageTitle = null): array
    {
        $dropped = ['related_section' => 0, 'link_text' => 0, 'title' => 0, 'short_or_long' => 0];
        $xpath = $this->load($html);
        if ($xpath === null) {
            return ['speaker' => null, 'sentences' => [], 'dropped' => $dropped];
        }
        $titleKeys = $this->titleKeys($xpath, $pageTitle);
        $minLen = (int) config('top_message_draft.panel_sentence_min_chars');
        $maxLen = (int) config('top_message_draft.panel_sentence_max_chars');
        $ratio = (float) config('top_message_draft.link_text_ratio');
        $excludePatterns = array_values(array_filter((array) config('top_message_draft.sentence_exclude_patterns'), 'is_string'));
        $related = array_map(fn ($w) => $this->normalize((string) $w), (array) config('top_message_draft.related_headings'));

        $speaker = null;
        $skipLevel = null;
        $seen = [];
        $sentences = [];

        foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::p or self::li or self::dd or self::dt or self::td or self::th or self::blockquote or self::div or self::figcaption]') ?: [] as $element) {
            if (! $element instanceof \DOMElement || $this->isExcluded($element)) {
                continue;
            }
            $tag = strtolower($element->tagName);
            $isHeading = preg_match('/^h([1-6])$/', $tag, $m) === 1;
            $level = $isHeading ? (int) $m[1] : 0;
            $leaf = $this->isLeafBlock($element);
            $text = $leaf || $isHeading ? $this->collapse($element->textContent) : '';

            if ($this->startsRelatedSection($skipLevel, $element, $related)) {
                continue;
            }

            if ($speaker === null && $leaf && $skipLevel === null) {
                foreach ($this->lines($element->textContent) as $line) {
                    if ($this->isSpeakerLine($line)) {
                        $speaker = $line;
                        $seen[$line] = true;
                        break;
                    }
                }
            }

            $isSentenceBlock = $isHeading ? $level <= 3 : (in_array($tag, self::SENTENCE_TAGS, true) && $leaf);
            if (! $isSentenceBlock) {
                continue;
            }

            $lines = $this->lines($element->textContent);
            if ($skipLevel !== null) {
                $dropped['related_section'] += $this->countSentences($lines);

                continue;
            }
            $linkRatio = $this->linkTextRatio($element);
            if ($linkRatio > 0 && $linkRatio >= $ratio) {
                $dropped['link_text'] += $this->countSentences($lines);

                continue;
            }
            foreach ($lines as $line) {
                foreach ($isHeading ? [$line] : $this->sentences($line) as $sentence) {
                    if ($isHeading && $this->isGeneric($sentence)) {
                        continue;
                    }
                    $length = mb_strlen($sentence);
                    if ($length < $minLen || $length > $maxLen || $this->matchesAny($sentence, $excludePatterns)) {
                        $dropped['short_or_long']++;

                        continue;
                    }
                    if (isset($seen[$sentence])) {
                        continue;
                    }
                    if (isset($titleKeys[$this->looseKey($sentence)])) {
                        $dropped['title']++;

                        continue;
                    }
                    // 依頼DI: 見出しの語だけの文(「Top Message トップメッセージ」など)は使わない。
                    if ($this->isLabelOnly($sentence)) {
                        continue;
                    }
                    $seen[$sentence] = true;
                    $sentences[] = ['id' => 'm-'.(count($sentences) + 1), 'text' => $sentence];
                }
            }
        }

        return ['speaker' => $speaker, 'sentences' => $sentences, 'dropped' => $dropped];
    }

    /**
     * 「関連記事」などの見出し(config related_headings。見出しでない短い行=葉の要素で文字が全体一致も含む)に当たる要素か。
     * 当たるときは、次の「同じか上の」見出しまで候補にしない状態($skipLevel)にして true を返す(呼び出し側はその要素を飛ばす)。
     * 当たらないときは、見出しが同じか上なら状態を解く。素材ページ(既定)とパネルの材料が共通に使う(依頼DA)。
     *
     * @param  list<string>  $related  比較用に整えた語
     */
    private function startsRelatedSection(?int &$skipLevel, \DOMElement $element, array $related): bool
    {
        $tag = strtolower($element->tagName);
        $isHeading = preg_match('/^h([1-6])$/', $tag, $m) === 1;
        $level = $isHeading ? (int) $m[1] : 0;
        $leaf = $this->isLeafBlock($element);
        $text = $leaf || $isHeading ? $this->collapse($element->textContent) : '';

        if (($isHeading || $leaf) && $text !== '' && in_array($this->normalize($text), $related, true)) {
            $skipLevel = $isHeading ? $level : 6;

            return true;
        }
        if ($isHeading && $skipLevel !== null && $level <= $skipLevel) {
            $skipLevel = null;
        }

        return false;
    }
    /**
     * @param  list<string>  $lines
     */
    private function countSentences(array $lines): int
    {
        $count = 0;
        foreach ($lines as $line) {
            $count += count($this->sentences($line));
        }

        return $count;
    }

    /**
     * 要素の文字のうち、リンク(a)の中の文字の割合(0〜1)。文字が無ければ0。要素自身がリンクの内側にあるときは1。
     */
    private function linkTextRatio(\DOMElement $element): float
    {
        $total = mb_strlen((string) preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', $element->textContent));
        if ($total === 0) {
            return 0.0;
        }
        for ($node = $element->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (strtolower($node->tagName) === 'a') {
                return 1.0;
            }
        }
        $linked = 0;
        foreach ($element->getElementsByTagName('a') as $anchor) {
            $linked += mb_strlen((string) preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', $anchor->textContent));
        }

        return min(1.0, $linked / $total);
    }

    /**
     * ページの優先順に、ページごとの制度の候補(見出し h2〜h4・dt・th)と、その直後の本文(p・dd・td)の先頭を抜き出す。
     * 同じ文字の候補は最初のページのものだけ。
     *
     * 依頼CSA-1: 末尾が「：」の見出しは外す。CSA-2: ページの<title>(と、その区切りの各部分)と同じ見出しは外す。
     * CSA-4: 次の見出しが同じページの中で自分より深い見出しは、制度の名前ではなく分類の見出しなので
     * kind='category'(件数に数えない・区切りの行)にする。外した数は dropped に理由別に持つ。
     *
     * @param  list<TopMessagePage>  $pages
     * @return list<array{url: string, source_title: string, items: list<array{kind: string, level: int, name: string, excerpt: string}>, dropped: array{colon: int, title: int, category: int}}>
     */
    public function programItemsByPage(array $pages, ?int $excerptMax = null): array
    {
        $seen = [];
        $result = [];

        foreach ($pages as $page) {
            $dropped = ['colon' => 0, 'title' => 0, 'category' => 0];
            $items = [];
            foreach ($this->programsOf((string) $page->html, $page->title, $dropped, $excerptMax) as $row) {
                if ($row['kind'] === 'program') {
                    $key = $this->normalize($row['name']);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                }
                $items[] = $row;
            }
            $result[] = ['url' => $page->url, 'source_title' => $this->sourceLabel($page->url, $page->title), 'items' => $items, 'dropped' => $dropped];
        }

        return $result;
    }

    /**
     * @param  array{colon: int, title: int, category: int}  $dropped
     * @return list<array{kind: string, level: int, name: string, excerpt: string}>
     */
    private function programsOf(string $html, ?string $pageTitle, array &$dropped, ?int $excerptMax = null): array
    {
        $xpath = $this->load($html);
        if ($xpath === null) {
            return [];
        }

        $minLen = (int) config('top_message_draft.program_name_min_chars');
        $maxLen = (int) config('top_message_draft.program_name_max_chars');
        $excerptMax ??= (int) config('top_message_draft.program_excerpt_max_chars');
        $suffixes = array_values(array_filter(array_map('strval', (array) config('top_message_draft.program_name_excluded_suffixes')), fn (string $s) => $s !== ''));
        $titleKeys = $this->titleKeys($xpath, $pageTitle);

        $tokens = [];
        foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::dt or self::th or self::p or self::dd or self::td]') ?: [] as $element) {
            if ($element instanceof \DOMElement) {
                $tokens[] = $element;
            }
        }

        $rows = [];
        $consumed = [];
        foreach ($tokens as $i => $element) {
            $tag = strtolower($element->tagName);
            if (isset($consumed[$i]) || ! in_array($tag, ['h2', 'h3', 'h4', 'dt', 'th'], true) || $this->isExcluded($element)) {
                continue;
            }
            $name = $this->collapse($element->textContent);
            $length = mb_strlen($name);
            if ($length < $minLen || $length > $maxLen || $this->isGeneric($name) || preg_match('/^[\d\s\p{P}\p{S}]+$/u', $name) === 1
                || $this->matchesAny($name, array_values(array_filter((array) config('top_message_draft.program_name_excluded_patterns', []), 'is_string')))) {
                continue;
            }
            foreach ($suffixes as $suffix) {
                if (str_ends_with($name, $suffix)) {
                    $dropped['colon']++;

                    continue 2;
                }
            }
            if (isset($titleKeys[$this->looseKey($name)])) {
                $dropped['title']++;

                continue;
            }

            // 依頼CSA-4: 自分より深い見出しが、同じかより浅い見出しが来るまでに2つ以上あれば分類の見出し。
            // 1つだけなら制度の名前とし、その見出しの文字を抜粋にする(その下の本文は使わない)。
            $level = ctype_digit(substr($tag, 1)) ? (int) substr($tag, 1) : 0;
            $deeperExcerpt = null;
            if ($level > 0) {
                $deeper = $this->deeperHeadings($tokens, $i, $level);
                if (count($deeper) >= 2) {
                    $dropped['category']++;
                    $rows[] = ['kind' => 'category', 'level' => $level, 'name' => $name, 'excerpt' => ''];

                    continue;
                }
                if (count($deeper) === 1) {
                    $deeperExcerpt = $this->collapse($tokens[$deeper[0]]->textContent);
                    $consumed[$deeper[0]] = true;
                }
            }

            $excerpt = '';
            if ($deeperExcerpt !== null) {
                $excerpt = $deeperExcerpt;
            } elseif ($tag === 'dt') {
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

            $rows[] = ['kind' => 'program', 'level' => $level, 'name' => $name, 'excerpt' => $excerpt];
        }

        return $rows;
    }

    /**
     * $index番目の見出し($level)の後ろで、同じかより浅い見出しが来るまでの、より深い見出し(h1〜h6、ヘッダー等の外)の位置。
     *
     * @param  list<\DOMElement>  $tokens
     * @return list<int>
     */
    private function deeperHeadings(array $tokens, int $index, int $level): array
    {
        $found = [];
        for ($j = $index + 1; $j < count($tokens); $j++) {
            if (preg_match('/^h([1-6])$/', strtolower($tokens[$j]->tagName), $m) !== 1 || $this->isExcluded($tokens[$j])) {
                continue;
            }
            if ((int) $m[1] <= $level) {
                break;
            }
            $found[] = $j;
        }

        return $found;
    }

    /**
     * 依頼CSA-2: ページの<title>(HTMLのもの・渡されたもの)と、それを区切り(階層図と同じconfig)で分けた各部分の、
     * 比較用の文字(空白・全角半角の違いを無視)の集合。
     *
     * @return array<string, true>
     */
    private function titleKeys(\DOMXPath $xpath, ?string $fallbackTitle): array
    {
        $titles = [];
        $node = $xpath->query('//title')?->item(0);
        if ($node !== null) {
            $titles[] = $this->collapse($node->textContent);
        }
        if ($fallbackTitle !== null) {
            $titles[] = $this->collapse($fallbackTitle);
        }

        $separators = array_values(array_filter(array_map('strval', (array) config('admin_comparison_pptx.site_hierarchy_tree_title_separators', [])), fn (string $s) => $s !== ''));
        $keys = [];
        foreach ($titles as $title) {
            if ($title === '') {
                continue;
            }
            $keys[$this->looseKey($title)] = true;
            $parts = $separators === [] ? [$title] : (preg_split('/'.implode('|', array_map(fn (string $s) => preg_quote($s, '/'), $separators)).'/u', $title) ?: [$title]);
            foreach ($parts as $part) {
                $part = $this->collapse($part);
                if ($part !== '') {
                    $keys[$this->looseKey($part)] = true;
                }
            }
        }
        unset($keys['']);

        return $keys;
    }

    /**
     * 比較用(空白を除き、全角半角の違い・大文字小文字を無視する)。記号は残す。表示には使わない。
     */
    private function looseKey(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', mb_convert_kana($text, 'asKV')));
    }

    /**
     * 本文の文字のうち、サイト共通のヘッダー・フッター・ナビ・サイドバー(タグ・role・class/id)の外に、
     * 指定の語のどれかがあるか。依頼CSA-5(代表者を示す語が本文の中にあること)に使う。
     *
     * @param  list<string>  $words
     */
    public function hasWordOutsideChrome(string $html, array $words): bool
    {
        $xpath = $this->load($html);
        if ($xpath === null) {
            return false;
        }
        $words = array_values(array_filter($words, fn (string $w) => $w !== ''));

        foreach ($xpath->query('//body//text()') ?: [] as $textNode) {
            $text = $textNode->textContent;
            $hit = false;
            foreach ($words as $word) {
                if (mb_stripos($text, $word) !== false) {
                    $hit = true;
                    break;
                }
            }
            if ($hit && $textNode->parentNode instanceof \DOMElement && ! $this->isExcluded($textNode->parentNode)) {
                return true;
            }
        }

        return false;
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

    /**
     * 依頼DI: 「Top Message トップメッセージ」のように、見出しの語(config message_label_words)だけでできている文。
     * 空白・記号を除き、長い語から順に取り除いて、何も残らなければ true。
     */
    private function isLabelOnly(string $text): bool
    {
        $rest = $this->normalize($text);
        if ($rest === '') {
            return false;
        }
        $words = array_values(array_filter(array_map(fn ($w) => $this->normalize((string) $w), (array) config('top_message_draft.message_label_words', [])), fn (string $w) => $w !== ''));
        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($words as $word) {
            $rest = str_replace($word, '', $rest);
        }

        return $rest === '';
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
