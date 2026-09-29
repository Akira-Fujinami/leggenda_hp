<?php

namespace App\Services\Report;

use App\Models\AnalysisCrawledPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\CrawlOriginScopeResolver;
use Illuminate\Support\Collection;

/**
 * 依頼CB-3(2026-09-24): 営業資料へ差し込む「自社サイトの階層図」スライドの
 * データを組み立てる。
 *
 * 【最重要】analysis_crawled_pages.discovered_via は 'link'|'sitemap' の
 * 文字列だけで、どのページから見つかったかは記録していない(依頼者指定、
 * スキーマは変更しない)。そのため、リンク構造から木(親子関係)を組み立てる
 * ことはできない ―― 代わりに、巡回できたページのURLのパス階層(起点URLより
 * 下のパスセグメント)から、1階層目の枝だけを組み立てる。depth列
 * (seedからのBFSホップ数)はサイトの階層と一致しないため使わない
 * (依頼者指定)。
 *
 * 起点URL: CrawlWebsiteJobが巡回のseedとして使う採用ページ
 * (AnalysisPage::PageType::Recruit)のfinal_url(無ければurl)を優先する
 * ―― この機能が「採用ブランド」の営業資料であり、CB-3の依頼書の例
 * (「起点 .../recruit/」)も採用ページを起点にしているため。採用ページの
 * 行が無い場合はトップページ、それも無ければWebsite.urlへ順にfallbackする。
 *
 * 依頼CC-3(2026-09-25): 本番の実例(NTTデータ)で、巡回50件中47件が起点URL
 * (採用ページ)の外(許可ホストがホスト単位のため、同じホストのIR・
 * ニュース等へ自由に漏れ出していた)だったことが判明した。「階層図が薄い」
 * のではなく「起点の外の材料しか無かった」ことがスライド上のどこにも
 * 書かれていなかったため、countWithinOrigin()を追加した ―― build()と
 * 同じ起点解決・ホスト/パス一致判定を共有し(resolveScope()/isWithinScope())、
 * 二重に実装しない。巡回の範囲(許可ホストの解決・起点パスでの絞り込み)
 * 自体はこの依頼の対象外(依頼者指定、別途相談) ―― ここではあくまで
 * 「気づけるようにする」ための集計のみ行う。
 *
 * 依頼CF-1(2026-09-29): 起点URLの解決・配下判定(旧resolveScope()/
 * isWithinScope())はApp\Services\Analysis\CrawlOriginScopeResolverへ
 * 抜き出した ―― CrawlWebsitePageJobの取得順序も同じ判定を必要と
 * するようになったため(同じ判定を2箇所に持たない、依頼者指定)。
 *
 * 依頼CF-2(2026-09-29): 枝が少ないサイト(実例: 信金中央金庫、起点URL配下
 * 5件で枝1本)で、階層図スライドの下半分が空白のまま残る不具合を実機で
 * 確認した(依頼者指摘)。数字・ページ名を捏造せず、実データから出せる
 * 材料だけで埋める ―― (1)代表ページの件数を増やす
 * (site_hierarchy_sample_pages_per_branch、3→5)、(2)起点URL配下の
 * 「外」にあった実データを「参考」として要約する(buildOutsideOriginBreakdown()、
 * 枝の組み立て(build()本体)とは別枠 ―― 階層図そのものではない、と明確に
 * 分けるため)。プライバシーポリシー等、枝の中身の判断材料にならない
 * ページは巡回対象からは外さず(crawl_excluded_path_patternsは変更しない)、
 * 代表ページとして選ぶ優先順位だけを下げる(deprioritizeSamplePages())。
 */
class AdminComparisonSiteHierarchyBuilder
{
    public function __construct(private readonly CrawlOriginScopeResolver $scopeResolver) {}

    /**
     * @return array{origin_url: string, branches: list<array{name: string, page_count: int, sample_pages: list<string>, name_is_url_segment: bool}>, other_branch_count: int, total_fetched_pages: int, pages_within_origin: int, outside_origin_breakdown: list<array{name: string, page_count: int}>, outside_origin_other_count: int}
     */
    public function build(WebsiteAnalysis $selfWebsiteAnalysis): array
    {
        $pages = $this->fetchedPages($selfWebsiteAnalysis);
        $totalFetchedPages = $pages->count();

        $scope = $this->scopeResolver->resolveScope($selfWebsiteAnalysis);
        if ($scope === null) {
            return [
                'origin_url' => '',
                'branches' => [],
                'other_branch_count' => 0,
                'total_fetched_pages' => $totalFetchedPages,
                'pages_within_origin' => 0,
                'outside_origin_breakdown' => [],
                'outside_origin_other_count' => 0,
            ];
        }

        $sampleLimit = (int) config('admin_comparison_pptx.site_hierarchy_sample_pages_per_branch');

        /** @var array<string, array{count: int, index_title: ?string, labels: list<array{label: string, deprioritized: bool}>}> $branches */
        $branches = [];
        /** @var list<array{label: string, deprioritized: bool}> $flatPageLabels 依頼CD-5参照 */
        $flatPageLabels = [];
        $pagesWithinOrigin = 0;
        /** @var array<string, int> $outsideCounts 依頼CF-2: 起点URL配下の外にあったページの、パス第1セグメントごとの件数(参考用)。 */
        $outsideCounts = [];

        foreach ($pages as $page) {
            if (! $this->scopeResolver->isWithinScope($page->url, $page->final_url, $scope)) {
                $outsideKey = $this->outsideTopSegment($page);
                if ($outsideKey !== null) {
                    $outsideCounts[$outsideKey] = ($outsideCounts[$outsideKey] ?? 0) + 1;
                }

                continue;
            }
            $pagesWithinOrigin++;

            $segments = $this->pathSegmentsBelowOrigin($page, $scope);
            if ($segments === []) {
                continue;
            }

            $title = trim((string) $page->title);

            // 依頼CD-5: 起点URLの直下にディレクトリを介さずファイルが
            // 直接置かれているサイト(例: .../recruit/qa.html、
            // .../recruit/flow.html)では、count($segments)===1のページが
            // 「そのディレクトリの代表ページ」ではなく、それ自体が独立した
            // 1ページに過ぎない。この2つを区別しないと、ファイル名の
            // セグメント(拡張子付き、例: "qa.html")がそのまま枝キーになり、
            // ページ1件だけの「枝」が乱立する(タイトルが取れていない場合は
            // 全てが「(ページ名未取得)」の壁になる、依頼者指摘の不具合)。
            //
            // 見分け方: セグメントが1つだけ、かつそのセグメントが
            // ファイル名らしい("."を含む ―― normalizeDirectoryPath()と
            // 同じ既存の判定基準、新しい規則を増やさない)場合だけを
            // 「起点直下の単独ページ」として扱い、枝には積まない。
            // セグメントが1つでも"."を含まない場合(例: "culture" のような
            // ディレクトリ形式のURL)は、従来どおりその枝自身のインデックス
            // ページとして扱う(依頼CB-3由来の既存動作、変更しない)。
            if (count($segments) === 1 && str_contains($segments[0], '.')) {
                $label = $title !== '' ? $title : $segments[0];
                $flatPageLabels[] = ['label' => $label, 'deprioritized' => $this->isDeprioritizedSampleLabel($label)];

                continue;
            }

            $branchKey = $segments[0];
            $branches[$branchKey]['count'] = ($branches[$branchKey]['count'] ?? 0) + 1;
            $branches[$branchKey]['index_title'] ??= null;

            // 依頼CB-3: 「その階層のインデックスページ」= この枝の中で
            // パスセグメントが1つだけ(=枝の直下そのもの)のページ。
            if (count($segments) === 1 && $title !== '') {
                $branches[$branchKey]['index_title'] = $title;
            }

            $label = $title !== '' ? $title : end($segments);
            // 依頼CF-2: sampleLimitでの打ち切りはここでは行わない
            // (deprioritizeSamplePages()で優先度順に並べ替えたあとに
            // 打ち切る ―― そうしないと、たまたま先に見つかった
            // privacypolicy等が優先枠を占有し、あとから見つかった
            // 判断材料になるページが弾かれてしまう)。
            $branches[$branchKey]['labels'][] = ['label' => $label, 'deprioritized' => $this->isDeprioritizedSampleLabel($label)];
        }

        $branchList = [];
        foreach ($branches as $segment => $info) {
            $branchList[] = [
                'name' => $info['index_title'] ?? $segment,
                'page_count' => $info['count'],
                'sample_pages' => $this->deprioritizeSamplePages($info['labels'], $sampleLimit),
                // 依頼CC-3③: ページ名を捏造しない ―― インデックスページを
                // 巡回できておらずURLのパスセグメントのまま枝名にしている
                // 場合、その旨をGenerator側で分かる形にする(捏造しない、
                // かつ「これが正式なページ名だ」と誤解させないため)。
                'name_is_url_segment' => $info['index_title'] === null,
            ];
        }

        // 依頼CD-5: 起点直下の単独ページ(上記で枝から外したもの)を、1件の
        // 集計行として枝の一覧に加える。見出し(name)はページ名ではなく
        // 「起点直下のページ」という区分自体の説明文言(捏造ではない、
        // config('admin_comparison_pptx.site_hierarchy_flat_pages_heading'))。
        // 各ページの実名はsample_pages側にのみ出す(タイトルがあればタイトル、
        // 無ければファイル名 ―― 日本語名を捏造しない、依頼者指定)。
        if ($flatPageLabels !== []) {
            $branchList[] = [
                'name' => (string) config('admin_comparison_pptx.site_hierarchy_flat_pages_heading'),
                'page_count' => count($flatPageLabels),
                'sample_pages' => $this->deprioritizeSamplePages($flatPageLabels, $sampleLimit),
                'name_is_url_segment' => false,
            ];
        }

        // 依頼CB-3: 枝の絞り方はページ数の多い順(依頼者提案、素朴で説明
        // しやすい ―― サイト内でどの区分に最も厚みがあるかがそのまま伝わる)。
        usort($branchList, fn (array $a, array $b) => $b['page_count'] <=> $a['page_count']);

        $limit = (int) config('admin_comparison_pptx.site_hierarchy_branch_limit');
        $otherBranchCount = max(0, count($branchList) - $limit);
        $branchList = array_slice($branchList, 0, $limit);

        [$outsideBreakdown, $outsideOtherCount] = $this->summarizeOutsideOriginBreakdown($outsideCounts);

        return [
            'origin_url' => $scope['origin_url'],
            'branches' => $branchList,
            'other_branch_count' => $otherBranchCount,
            'total_fetched_pages' => $totalFetchedPages,
            'pages_within_origin' => $pagesWithinOrigin,
            'outside_origin_breakdown' => $outsideBreakdown,
            'outside_origin_other_count' => $outsideOtherCount,
        ];
    }

    /**
     * 依頼CF-2: 代表ページの優先度を下げる語(config
     * ('admin_comparison_pptx.site_hierarchy_deprioritized_sample_keywords'))を
     * 含むラベル(タイトルまたはファイル名)かどうか。巡回対象からは外さない
     * (crawl_excluded_path_patternsは変更しない)―― あくまで代表ページとして
     * 選ぶ優先順位だけを下げる。
     */
    private function isDeprioritizedSampleLabel(string $label): bool
    {
        foreach ((array) config('admin_comparison_pptx.site_hierarchy_deprioritized_sample_keywords', []) as $keyword) {
            if ($keyword !== '' && mb_stripos($label, (string) $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 依頼CF-2: 優先度が高い(deprioritized=false)ラベルを先に、その中では
     * 発見順を保ったまま(stable)$sampleLimit件まで選ぶ。優先度の高いものが
     * $sampleLimit件に満たない場合は、優先度が低いものから順に埋める ――
     * 「除外した結果その枝の代表ページが0件になるなら、外さずに出す」
     * (依頼者指定)をそのまま満たす(そもそも除外していないため)。
     *
     * @param  list<array{label: string, deprioritized: bool}>  $labels
     * @return list<string>
     */
    private function deprioritizeSamplePages(array $labels, int $sampleLimit): array
    {
        $prioritized = array_values(array_filter($labels, fn (array $l) => ! $l['deprioritized']));
        $deprioritized = array_values(array_filter($labels, fn (array $l) => $l['deprioritized']));

        $ordered = [...$prioritized, ...$deprioritized];

        return array_column(array_slice($ordered, 0, $sampleLimit), 'label');
    }

    /**
     * 依頼CF-2: 起点URL配下の「外」にあったページ(host一致、pathが
     * scope外)を、パスの第1セグメントで集計する(参考用、枝の一覧
     * (branches)とは別枠 ―― 「これは階層図そのものではない」ことを
     * Generator側でも明確に分けて出す)。ホストが異なる(そもそも許可
     * ホストの外)ページは対象外(nullを返す) ―― 巡回の範囲自体は
     * この依頼で変更しないため、許可ホストの外のページを集計に混ぜない。
     */
    private function outsideTopSegment(AnalysisCrawledPage $page): ?string
    {
        $parts = parse_url((string) ($page->final_url ?? $page->url));
        $path = (string) ($parts['path'] ?? '');
        $segments = array_values(array_filter(explode('/', $path), fn (string $s) => $s !== ''));

        return $segments[0] ?? null;
    }

    /**
     * @param  array<string, int>  $outsideCounts
     * @return array{0: list<array{name: string, page_count: int}>, 1: int}
     */
    private function summarizeOutsideOriginBreakdown(array $outsideCounts): array
    {
        if ($outsideCounts === []) {
            return [[], 0];
        }

        $rows = [];
        foreach ($outsideCounts as $segment => $count) {
            $rows[] = ['name' => $segment, 'page_count' => $count];
        }
        usort($rows, fn (array $a, array $b) => $b['page_count'] <=> $a['page_count']);

        $limit = (int) config('admin_comparison_pptx.site_hierarchy_outside_breakdown_limit');
        $otherCount = 0;
        if (count($rows) > $limit) {
            $otherCount = array_sum(array_column(array_slice($rows, $limit), 'page_count'));
            $rows = array_slice($rows, 0, $limit);
        }

        return [$rows, $otherCount];
    }

    /**
     * 依頼CC-3②(2026-09-25): 管理画面「巡回の実績」(CrawlDiagnosticsService)
     * が、営業資料を出す前に「起点URL配下の取得件数」を出せるようにする
     * ための、build()より軽い集計だけの入口。枝の組み立ては行わない。
     *
     * @return array{origin_url: ?string, total_fetched: int, within_origin: int}
     */
    public function countWithinOrigin(WebsiteAnalysis $websiteAnalysis): array
    {
        $pages = $this->fetchedPages($websiteAnalysis);
        $totalFetched = $pages->count();

        $scope = $this->scopeResolver->resolveScope($websiteAnalysis);
        if ($scope === null) {
            return ['origin_url' => null, 'total_fetched' => $totalFetched, 'within_origin' => 0];
        }

        $withinOrigin = 0;
        foreach ($pages as $page) {
            if ($this->scopeResolver->isWithinScope($page->url, $page->final_url, $scope)) {
                $withinOrigin++;
            }
        }

        return ['origin_url' => $scope['origin_url'], 'total_fetched' => $totalFetched, 'within_origin' => $withinOrigin];
    }

    /**
     * @return Collection<int, AnalysisCrawledPage>
     */
    private function fetchedPages(WebsiteAnalysis $websiteAnalysis): Collection
    {
        return AnalysisCrawledPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
            ->get(['url', 'final_url', 'title']);
    }

    /**
     * @param  array{host: string, path: string}  $scope
     * @return list<string>
     */
    private function pathSegmentsBelowOrigin(AnalysisCrawledPage $page, array $scope): array
    {
        $path = (string) (parse_url((string) ($page->final_url ?? $page->url))['path'] ?? '');
        $remainder = substr($path, strlen($scope['path']));

        return array_values(array_filter(explode('/', $remainder), fn (string $s) => $s !== ''));
    }
}
