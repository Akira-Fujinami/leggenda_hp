<?php

namespace App\Services\Analysis;

/**
 * 依頼BB-2/依頼BC-1〜3/依頼BD-1〜2: 新卒／キャリア採用の区別が選ばれている診断で、
 * 巡回候補URLが「反対側」の語を含むかどうかを判定する。除外語一覧は
 * config('brand_wheel.recruitment_track_keywords')(コードに直書きしない)。
 *
 * 【最重要】ホスト名・起点URL自身のパスは、ページを除外する根拠には使わない
 * ―― career.mercari.comのように、ホスト名や採用トップのパス自体にキャリア
 * 側の語を含む実例があるため(依頼BB「最重要」の指定)。除外の判定対象は
 * 「起点URLより下で新しく現れたパスセグメント」とクエリ文字列のみ。
 * (依頼BC-3で、ホスト名を「区分を適用するかどうか」の判定にのみ使う経路を
 * 追加したが、これはページを除外する方向には一切働かない ―― 適用そのものを
 * 見送るか決めるだけであり、既存の禁止事項に反しない。)
 *
 * 【依頼BC-1】語の照合はパスセグメントの境界を見る。半角英数の語は
 * セグメント全体との完全一致、日本語(非ASCII)の語のみセグメント内の
 * 部分一致とする ―― `career`が`career-path`(共通ページ)や`careerplan`に
 * 誤爆する実害が確認されたため。クエリ文字列は常にパラメータの値との
 * 完全一致とする。
 *
 * 【依頼BC-2】「反対側」の語を含んでいても、同じURLに「選んだ側」の語も
 * 含まれている場合は除外しない(残す) ―― 依頼BBの「誤判定で材料が消える
 * より、混ざっているほうがまだマシ」という方針を、両側の語が同居する
 * ケースにも一貫して適用する。
 */
class RecruitmentTrackPageFilter
{
    /**
     * @return bool  trueなら除外すべき(「反対側」の語のみを含み、「選んだ側」の語を含まない)
     */
    public function shouldExclude(string $candidateUrl, string $originUrl, string $recruitmentTrack): bool
    {
        [$oppositeKeywords, $selectedKeywords] = match ($recruitmentTrack) {
            'new_graduate' => [
                (array) config('brand_wheel.recruitment_track_keywords.career', []),
                (array) config('brand_wheel.recruitment_track_keywords.new_graduate', []),
            ],
            'career' => [
                (array) config('brand_wheel.recruitment_track_keywords.new_graduate', []),
                (array) config('brand_wheel.recruitment_track_keywords.career', []),
            ],
            default => [[], []],
        };

        if ($oppositeKeywords === []) {
            return false;
        }

        $segments = $this->newPathSegments($candidateUrl, $originUrl);
        $queryValues = $this->queryValues($candidateUrl);

        if ($segments === [] && $queryValues === []) {
            return false;
        }

        if (! $this->matchesAny($segments, $queryValues, $oppositeKeywords)) {
            return false;
        }

        // 依頼BC-2: 選んだ側の語も同居していれば、除外せず残す。
        return ! $this->matchesAny($segments, $queryValues, $selectedKeywords);
    }

    /**
     * 依頼BC-3/依頼BD-2: 起点URLが「すでに採用サイトの内側」にあるかどうかを
     * 判定する。ホスト名のラベル(依頼BD-1、`.`と`-`区切り)、または起点URL
     * 自身のパスセグメントのいずれかに採用系の語が実際に見つかった場合のみ
     * 「内側」とみなす ―― 見つからない場合は、採用区分による除外の適用
     * そのものを見送る対象とみなす(呼び出し側 ―― CrawlWebsiteJob/
     * CrawlWebsitePageJob/ReportViewModelBuilder ―― が、この結果に応じて
     * 適用有無を決める)。
     *
     * 依頼BD-2: 「ルートパスでなければ内側」という旧ロジック(依頼BC-3)は、
     * `/ja/`・`/jp/`・`/index.html`のような、採用系の語を一切含まない
     * 言語プレフィックス付きコーポレートトップを「内側」と誤判定して
     * しまっていた(この起点で区分を選ぶと、実際には採用セクション全体が
     * 丸ごと巡回除外の対象になり得る ―― 依頼BC-3が防ごうとした事故が
     * 別の形で残っていた)。パス・ホストいずれについても、語が実際に
     * 見つかったときだけ「内側」と判定するよう変更した。判断に迷う場合
     * (＝語が見つからない場合)は、必ず適用しない側に倒す(依頼者指定)。
     *
     * ここでホスト名・パスを見るのは、依頼BBが禁止した「ホスト名・起点
     * パスの語を根拠にページを除外すること」ではない ―― 逆に「区分の
     * 適用自体を弱める(見送る)」方向にのみ働く(careers.mercari.comの
     * ように、ホストに採用語があれば従来どおり除外が効く)。
     */
    public function isOriginInsideRecruitSection(string $originUrl): bool
    {
        $hostLabels = $this->hostLabels($originUrl);
        $hostnameKeywords = (array) config('brand_wheel.recruitment_track_recruit_section_hostname_keywords', []);
        if ($this->matchesAny($hostLabels, [], $hostnameKeywords)) {
            return true;
        }

        $pathSegments = $this->allPathSegments($originUrl);
        $pathKeywords = (array) config('brand_wheel.recruitment_track_recruit_section_path_keywords', []);

        return $this->matchesAny($pathSegments, [], $pathKeywords);
    }

    /**
     * 依頼BC-1: 半角英数の語はセグメント全体との完全一致、日本語(非ASCII)の
     * 語はセグメント内の部分一致で判定する。クエリのパラメータ値は
     * (語の種類によらず)常に完全一致で判定する。
     *
     * shouldExclude()(パスセグメント)だけでなく、依頼BD-1/BD-2の
     * isOriginInsideRecruitSection()(ホスト名のラベル・起点パスの
     * セグメント)からも同じ照合ルールとして共用する ―― 一致方式を
     * 用途ごとにばらけさせないため。
     *
     * @param  list<string>  $segments
     * @param  list<string>  $queryValues
     * @param  list<string>  $keywords
     */
    private function matchesAny(array $segments, array $queryValues, array $keywords): bool
    {
        foreach ($keywords as $rawKeyword) {
            $keyword = mb_strtolower(trim($rawKeyword));
            if ($keyword === '') {
                continue;
            }

            $exactOnly = $this->isAscii($keyword);

            foreach ($segments as $segment) {
                if ($exactOnly ? $segment === $keyword : str_contains($segment, $keyword)) {
                    return true;
                }
            }

            foreach ($queryValues as $value) {
                if ($value === $keyword) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isAscii(string $value): bool
    {
        return (bool) preg_match('/^[\x00-\x7F]+$/', $value);
    }

    /**
     * 候補URLのパスのうち、起点URLのパスより「新しい」部分だけを、`/`区切りの
     * セグメント配列として返す(小文字・URLデコード済み、空セグメントは除く)。
     * 起点URLと同じホストであること自体は呼び出し側(既存のallowedHosts判定)が
     * 保証する前提のため、ここではホストを一切見ない。
     *
     * @return list<string>
     */
    private function newPathSegments(string $candidateUrl, string $originUrl): array
    {
        $candidatePath = $this->decodedLowerPath($candidateUrl);
        $originPath = $this->decodedLowerPath($originUrl);

        $originPrefix = rtrim($originPath, '/');

        if ($originPrefix !== '' && str_starts_with($candidatePath, $originPrefix)) {
            // パス区切り境界で一致した場合のみ「起点の内側」とみなす。
            // 例: 起点/careerに対し候補/career-portal/...は前方一致するが
            // 次の文字が'/'でも終端でもないため別ブランチ(除外しない)と
            // 判定し、"career-portal"ごと新しい部分として扱う。
            $charAfterPrefix = substr($candidatePath, strlen($originPrefix), 1);
            $remainderPath = ($charAfterPrefix === '' || $charAfterPrefix === '/')
                ? substr($candidatePath, strlen($originPrefix))
                : $candidatePath;
        } else {
            $remainderPath = $candidatePath;
        }

        return array_values(array_filter(explode('/', $remainderPath), fn (string $s) => $s !== ''));
    }

    /**
     * 候補URLのクエリ文字列を key=value に分解し、値だけを小文字化して返す
     * (依頼BC-1: パラメータの値との完全一致で判定するため、部分一致用の
     * 結合文字列は作らない)。parse_str()がURLデコードを行うため、ここでは
     * 追加のurldecode()は行わない(二重デコードを避ける)。
     *
     * @return list<string>
     */
    private function queryValues(string $url): array
    {
        $rawQuery = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if ($rawQuery === '') {
            return [];
        }

        parse_str($rawQuery, $parsed);

        $values = [];
        array_walk_recursive($parsed, function ($value) use (&$values) {
            $values[] = mb_strtolower((string) $value);
        });

        return $values;
    }

    private function decodedLowerPath(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');

        return mb_strtolower(urldecode($path));
    }

    /**
     * 依頼BD-1: ホスト名を`.`と`-`で分割したラベル配列を返す(小文字化済み)。
     * `str_contains()`による部分一致(`threebond.co.jp`が`hr`にヒットする
     * バグ)を避けるため、ラベル単位の完全一致(matchesAny()のASCII分岐)で
     * 判定できる形にする。
     *
     * @return list<string>
     */
    private function hostLabels(string $url): array
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/[.\-]/', $host) ?: [], fn (string $s) => $s !== ''));
    }

    /**
     * 依頼BD-2: 起点URL自身のパスを`/`区切りのセグメント配列として返す
     * (newPathSegments()と異なり、起点URLとの相対位置ではなく起点自身の
     * パスをそのまま分割する)。
     *
     * @return list<string>
     */
    private function allPathSegments(string $url): array
    {
        $path = $this->decodedLowerPath($url);

        return array_values(array_filter(explode('/', $path), fn (string $s) => $s !== ''));
    }
}
