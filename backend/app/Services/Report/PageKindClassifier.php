<?php

namespace App\Services\Report;

use App\Models\BrandWheelAnalysisResult;

/**
 * 依頼CY-2: 階層図の第3階層(ページ)を「情報の種類」(求職者調査の14項目)で示すために、ページに種類を付ける(AIを使わない・通信しない。
 * 同じ入力から必ず同じ結果)。次の2つのどちらかに当てはまれば、その種類を付ける(1ページに複数付いてよい)。
 *
 *  1. ページ名(title、または最初のh1)に、種類ごとの言葉(config('admin_comparison_pptx.page_kind_title_words'))を含む。
 *     ページ名から種類がはっきり分かる一般的な言葉だけを入れてある(会社ごとの調整はしない)。種類によっては、ページ名にこの言葉が
 *     あるときは付けない(page_kind_title_exclude_words。例: 社員インタビューは、代表・経営層のページには付けない)。
 *  2. ブランド・ホイールの判定で、自社が○の項目の根拠の文が、そのページの本文にそのまま含まれる(空白・全角半角の違いだけ無視、
 *     判定の根拠の突き合わせと同じ正規化)。その項目の対応表(brand_wheel_candidate_survey.mapping の survey_option)の種類を付ける。
 *     根拠の文がどのページのものかは、保存済みの判定結果には残っていない(判定の入力を作るときだけの情報)ため、各ページの本文に
 *     含まれるかで見る。判定(v9)は変えない。
 *
 * 2の注意: 同じ根拠の文が、図に出るページのうちconfigの件数(page_kind_evidence_max_pages)を超えるページに含まれるときは、
 * サイト共通の文章(どのページにもある案内など)とみなして、その根拠の文は使わない(特定のページの種類を示さないため)。
 *
 * どちらにも当てはまらないページは「その他」(種類を付けない。推測で付けない)。
 * 閾値・言葉・短い名前はすべて config('admin_comparison_pptx.page_kind_*')。
 */
class PageKindClassifier
{
    public function __construct(private readonly CandidateSurveyCatalog $survey = new CandidateSurveyCatalog) {}

    /**
     * 自社の判定結果から、種類ごとの根拠の文(正規化済み)を作る。○の項目のうち、対応する調査項目があるものだけ。
     *
     * @return list<array{kind: string, text: string}>
     */
    public function evidenceFor(?BrandWheelAnalysisResult $record): array
    {
        if ($record === null || $record->status !== 'success') {
            return [];
        }

        $min = (int) config('admin_comparison_pptx.page_kind_evidence_min_chars', 12);
        $rows = [];
        foreach ((array) ($record->axes ?? []) as $axis) {
            foreach ((array) ($axis['matched_sub_elements'] ?? []) as $sub) {
                $option = $this->survey->optionForSubElement((string) ($axis['axis_key'] ?? ''), (string) ($sub['key'] ?? ''));
                $text = $this->normalize((string) ($sub['evidence'] ?? ''));
                if ($option !== null && mb_strlen($text) >= $min) {
                    $rows[] = ['kind' => $option['key'], 'text' => $text];
                }
            }
        }

        return $rows;
    }

    /**
     * 1ページの種類(根拠の文の「サイト共通の文章」の除外は、呼び出し側が全ページを見て行うとき、analyze() と combine() を使う)。
     *
     * @param  list<array{kind: string, text: string}>  $evidence  evidenceFor()の戻り値
     * @return array<string, string> 種類のキー => 付いた理由('title' / 'evidence' / 'both')
     */
    public function classify(?string $title, ?string $html, array $evidence): array
    {
        $analysis = $this->analyze($title, $html, $evidence);

        return $this->combine($analysis, $evidence, array_keys($evidence));
    }

    /**
     * 1ページを読む: ページ名(title・最初のh1)の言葉で付く種類と、本文に含まれる根拠の文(evidenceの添字)。
     *
     * @param  list<array{kind: string, text: string}>  $evidence
     * @return array{title_kinds: list<string>, hits: list<int>}
     */
    public function analyze(?string $title, ?string $html, array $evidence): array
    {
        $titleKinds = [];

        $names = [$this->normalize((string) $title)];
        $bodyText = null;
        if ($html !== null && $html !== '') {
            [$h1, $bodyText] = $this->readHtml($html);
            $names[] = $this->normalize($h1);
        }
        $names = array_values(array_filter($names, fn (string $n) => $n !== ''));

        foreach ((array) config('admin_comparison_pptx.page_kind_title_words', []) as $kind => $words) {
            $excluded = (array) (config('admin_comparison_pptx.page_kind_title_exclude_words', [])[$kind] ?? []);
            foreach ($names as $name) {
                if ($this->containsAny($name, $excluded)) {
                    continue;
                }
                if ($this->containsAny($name, (array) $words)) {
                    $titleKinds[] = (string) $kind;

                    break;
                }
            }
        }

        $hits = [];
        if ($bodyText !== null && $bodyText !== '') {
            foreach ($evidence as $index => $row) {
                if (str_contains($bodyText, $row['text'])) {
                    $hits[] = $index;
                }
            }
        }

        return ['title_kinds' => $titleKinds, 'hits' => $hits];
    }

    /**
     * analyze() の結果から、種類と付いた理由を作る。根拠の文は、$allowedEvidence(使ってよい添字)のものだけ。
     *
     * @param  array{title_kinds: list<string>, hits: list<int>}  $analysis
     * @param  list<array{kind: string, text: string}>  $evidence
     * @param  list<int>  $allowedEvidence
     * @return array<string, string>
     */
    public function combine(array $analysis, array $evidence, array $allowedEvidence): array
    {
        $kinds = [];
        foreach ($analysis['title_kinds'] as $kind) {
            $kinds[$kind] = 'title';
        }
        foreach ($analysis['hits'] as $index) {
            if (! in_array($index, $allowedEvidence, true)) {
                continue;
            }
            $kind = $evidence[$index]['kind'];
            $kinds[$kind] = isset($kinds[$kind]) ? 'both' : 'evidence';
        }

        return $kinds;
    }

    /**
     * 枝の各ページの種類から、第3階層の行を作る。種類は調査の割合の高い順(同率は調査の並び順)。行数の上限まで出し、
     * 残りの種類と「その他」のページは「ほかNページ」(rest)にまとめる。種類が1つも付かなかったときは classified=false
     * (呼び出し側が、従来どおりページ名を出す)。
     *
     * @param  list<array{kinds: array<string, string>}>  $pages
     * @return array{classified: bool, lines: list<string>, rest: int, counts: array<string, int>}
     */
    public function lines(array $pages, int $maxLines): array
    {
        $counts = [];
        foreach ($pages as $page) {
            foreach (array_keys($page['kinds']) as $kind) {
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return ['classified' => false, 'lines' => [], 'rest' => 0, 'counts' => []];
        }

        $ordered = [];
        foreach ($this->survey->options() as $option) {
            if (isset($counts[$option['key']])) {
                $ordered[] = $option['key'];
            }
        }
        $shown = array_slice($ordered, 0, max(1, $maxLines));

        $template = (string) config('admin_comparison_pptx.page_kind_line_template');
        $lines = array_map(fn (string $kind) => sprintf($template, $this->shortName($kind), $counts[$kind]), $shown);

        $rest = 0;
        foreach ($pages as $page) {
            if (array_intersect(array_keys($page['kinds']), $shown) === []) {
                $rest++;
            }
        }

        return ['classified' => true, 'lines' => $lines, 'rest' => $rest, 'counts' => $counts];
    }

    /** スライドに出す短い名前(config)。無ければ調査の選択肢の名前。 */
    public function shortName(string $kind): string
    {
        $short = config("admin_comparison_pptx.page_kind_short_names.{$kind}");

        return is_string($short) && $short !== '' ? $short : (string) config("brand_wheel_candidate_survey.options.{$kind}.name", $kind);
    }

    /**
     * @param  list<string>  $words
     */
    private function containsAny(string $normalized, array $words): bool
    {
        foreach ($words as $word) {
            $word = mb_strtolower($this->normalize((string) $word));
            if ($word !== '' && str_contains(mb_strtolower($normalized), $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 最初のh1の文字と、本文(script等を除いた全文字)の正規化した文字。
     *
     * @return array{0: string, 1: string}
     */
    private function readHtml(string $html): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript|//template') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        $h1 = $xpath->query('//h1')->item(0)?->textContent ?? '';
        $body = $xpath->query('//body')->item(0)?->textContent ?? $dom->textContent;

        return [(string) $h1, $this->normalize((string) $body)];
    }

    /** 判定の根拠の突き合わせと同じ正規化(半角カナ→全角・全角英数→半角、空白の除去、引用符・ダッシュの統一)。 */
    private function normalize(string $text): string
    {
        $text = mb_convert_kana($text, 'KVa', 'UTF-8');
        $text = (string) preg_replace('/\s+/u', '', $text);

        return str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2212}", "\u{2015}"], ["'", "'", '"', '"', '-', '-', '-', '-'], $text);
    }
}
