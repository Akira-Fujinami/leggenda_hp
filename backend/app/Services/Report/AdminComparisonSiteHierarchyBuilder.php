<?php

namespace App\Services\Report;

use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\CrawlLinkExtractor;
use App\Services\Analysis\CrawlOriginScopeResolver;
use App\Services\Analysis\HtmlSeoAnalyzer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

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
 *
 * 依頼CL-3(2026-10-05): 階層図を「TOP → 第1階層 → 第2階層」の木の形に
 * 作り直した(buildTree())。discovered_viaは親子関係を持たないままで
 * (スキーマは変更しない)、親子は保存済みのHTMLを読み直して求める ――
 * 第1階層はTOPのメニュー(extractMenuLinks())、第2階層はその項目の
 * ページから実際にリンクされているページ(CrawlLinkExtractorで読み直す)。
 * メニューが読めないサイトでは、従来のURLの階層(build()と共通の
 * groupUrlBranches())を同じ木の形で描く。AIは使わず、同じ入力から
 * 同じ結果になる。build()の出力形(CL以前の枝の一覧)は変えていない。
 */
class AdminComparisonSiteHierarchyBuilder
{
    public function __construct(
        private readonly CrawlOriginScopeResolver $scopeResolver,
        private readonly HtmlSeoAnalyzer $htmlAnalyzer = new HtmlSeoAnalyzer,
        private readonly CrawlLinkExtractor $linkExtractor = new CrawlLinkExtractor,
    ) {}

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

        [$branches, $flatPageLabels, $pagesWithinOrigin, $outsideCounts] = $this->groupUrlBranches($pages, $scope);

        $branchList = [];
        foreach ($branches as $segment => $info) {
            $branchList[] = [
                'name' => $info['index_title'] ?? $this->decodeSegmentForDisplay($segment),
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
     * 依頼CL-3: build()とbuildTree()(メニューが読めないサイトの代替)で
     * 共有する、URLのパス階層による枝のグルーピング。依頼CL以前のbuild()の
     * ループ本体をそのまま切り出したもの(挙動は変えていない)。
     *
     * @param  Collection<int, AnalysisCrawledPage>  $pages
     * @param  array{origin_url: string, host: string, path: string}  $scope
     * @return array{0: array<string, array{count: int, index_title: ?string, labels: list<array{label: string, deprioritized: bool}>}>, 1: list<array{label: string, deprioritized: bool}>, 2: int, 3: array<string, int>}
     */
    private function groupUrlBranches(Collection $pages, array $scope): array
    {
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
                $label = $title !== '' ? $title : $this->decodeSegmentForDisplay($segments[0]);
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

            $label = $title !== '' ? $title : $this->decodeSegmentForDisplay((string) end($segments));
            // 依頼CF-2: sampleLimitでの打ち切りはここでは行わない
            // (deprioritizeSamplePages()で優先度順に並べ替えたあとに
            // 打ち切る ―― そうしないと、たまたま先に見つかった
            // privacypolicy等が優先枠を占有し、あとから見つかった
            // 判断材料になるページが弾かれてしまう)。
            $branches[$branchKey]['labels'][] = ['label' => $label, 'deprioritized' => $this->isDeprioritizedSampleLabel($label)];
        }

        return [$branches, $flatPageLabels, $pagesWithinOrigin, $outsideCounts];
    }

    /**
     * 依頼CH-4(2026-10-01): URLのパスセグメントは通常パーセントエンコー
     * ディングされたまま保存されている(parse_url()はデコードしない)ため、
     * 表示直前にここでデコードする。デコード後が不正なUTF-8になる場合
     * (壊れたバイト列・偶然%記号を含む通常の文字列等)は、文字化けを資料に
     * 出さないため元のまま返す(依頼者指定)。
     *
     * 分類キー(枝名のキー・起点外内訳の集計キー)には使わない ――
     * あくまで最終的な表示名を組み立てる直前にのみ適用し、巡回・集計
     * ロジック(どのページがどの枝/内訳に属するか)自体は一切変えない。
     */
    private function decodeSegmentForDisplay(string $segment): string
    {
        $decoded = rawurldecode($segment);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $segment;
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
            $rows[] = ['name' => $this->decodeSegmentForDisplay($segment), 'page_count' => $count];
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
     * 依頼CL-3(2026-10-05): 階層図の木(TOP → 第1階層 → 第2階層)。
     *
     * mode='menu': 第1階層=TOPのメニュー(ヘッダー・ナビゲーション、フッター
     * は含めない)の項目、第2階層=その項目のページから実際にリンクされて
     * いるページ(全ページ共通のメニュー・フッターのリンクは除く)。実際の
     * リンクが求められない項目だけ、URLがその項目の配下にあるページで代用する
     * (second_level_sourceにどちらで作ったかを件数で返す)。
     * mode='url': メニューの項目が config 'site_hierarchy_tree_menu_min_items'
     * 未満しか取れないサイトでは、従来のURLの階層(build()と共通の
     * groupUrlBranches())を同じ木の形で返す。
     *
     * 対象は巡回で取得できたページ(status=fetched)のうち、起点URL配下
     * (CrawlOriginScopeResolver)のものだけ。HTMLが読めない・起点が解決
     * できない場合も例外にせず、枝が空の木を返す。
     *
     * @return array{
     *     mode: string,
     *     origin_url: string,
     *     top: array{url: string, title: ?string, headings: list<string>, menu_item_count: int},
     *     branches: list<array{name: string, url: ?string, page_count: int, pages: list<string>, other_page_count: int}>,
     *     other_branch_count: int,
     *     total_fetched_pages: int,
     *     pages_within_origin: int,
     *     outside_origin_count: int,
     *     second_level_source: array{links: int, url: int},
     *     menu_source: ?string,
     *     origin_widened: bool,
     * }
     */
    public function buildTree(WebsiteAnalysis $websiteAnalysis): array
    {
        $pages = $this->fetchedPages($websiteAnalysis);
        $totalFetched = $pages->count();

        $sourceScope = $this->scopeResolver->resolveScope($websiteAnalysis);
        if ($sourceScope === null) {
            return $this->treeResult('url', '', ['url' => '', 'title' => null, 'headings' => [], 'menu_item_count' => 0], [], 0, $totalFetched, 0, ['links' => 0, 'url' => 0], null);
        }

        // 依頼CM-2(2026-10-06): 起点の配下で取得できたページが基準未満なら、
        // 階層図の表示だけ、起点をそのホストの一番上まで広げて描く。巡回・判定に
        // 使う起点(CrawlOriginScopeResolver)は変えない ―― ここで広げるのは
        // この木の描画範囲だけ。
        $scope = $sourceScope;
        $widened = false;
        $withinSource = $pages->filter(fn (AnalysisCrawledPage $page) => $this->scopeResolver->isWithinScope($page->url, $page->final_url, $sourceScope))->count();
        if ($withinSource < (int) config('admin_comparison_pptx.site_hierarchy_tree_widen_min_pages')) {
            $hostTop = $this->hostTopScope($sourceScope);
            if ($hostTop !== null) {
                $scope = $hostTop;
                $widened = true;
            }
        }

        $within = $pages->filter(fn (AnalysisCrawledPage $page) => $this->scopeResolver->isWithinScope($page->url, $page->final_url, $scope))->values();
        $pagesWithinOrigin = $within->count();

        $topPage = $this->readTopPage($websiteAnalysis, $sourceScope, $pages);
        $minItems = (int) config('admin_comparison_pptx.site_hierarchy_tree_menu_min_items');

        // レンダリング済みHTMLを優先する(メニューがJavaScriptで描かれるサイトが
        // ある)。ただし項目が足りなければ静的HTMLも試し、多い方を採る。
        $menuItems = [];
        $menuSource = null;
        foreach ($topPage['htmls'] as $candidate) {
            $items = $this->extractMenuItems($candidate['html'], $topPage['base_url'], $scope);
            if ($menuSource === null || count($items) > count($menuItems)) {
                $menuItems = $items;
                $menuSource = $candidate['source'];
            }
            if (count($items) >= $minItems) {
                $menuItems = $items;
                $menuSource = $candidate['source'];
                break;
            }
        }

        // 広げたときは、保存済みのページ(広げる前の起点のページ)のページ名・見出しを
        // 広げた先の「サイトの一番上」のものとして出さない(別のページのため)。
        $top = [
            'url' => $scope['origin_url'],
            'title' => $widened ? null : $topPage['title'],
            'headings' => $widened ? [] : $topPage['headings'],
            'menu_item_count' => count($menuItems),
        ];

        if (count($menuItems) < $minItems) {
            [$branches, $otherBranchCount] = $this->buildUrlModeBranches($within, $scope);
            $result = $this->treeResult('url', $scope['origin_url'], $top, $branches, $otherBranchCount, $totalFetched, $pagesWithinOrigin, ['links' => 0, 'url' => 0], $menuSource);
        } else {
            [$branches, $otherBranchCount, $source] = $this->buildMenuModeBranches($menuItems, $within, $scope);
            $result = $this->treeResult('menu', $scope['origin_url'], $top, $branches, $otherBranchCount, $totalFetched, $pagesWithinOrigin, $source, $menuSource);
        }
        $result['origin_widened'] = $widened;

        return $result;
    }

    /**
     * 起点をそのホストの一番上(/)まで広げた範囲。すでに一番上、またはホストが
     * 読み取れないときはnull(広げる意味が無い)。
     *
     * @param  array{origin_url: string, host: string, path: string}  $scope
     * @return array{origin_url: string, host: string, path: string}|null
     */
    private function hostTopScope(array $scope): ?array
    {
        if ($scope['host'] === '' || $scope['path'] === '/') {
            return null;
        }

        $parts = parse_url($scope['origin_url']);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return [
            'origin_url' => $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/',
            'host' => $scope['host'],
            'path' => '/',
        ];
    }

    /**
     * 自社のWebsiteAnalysisが見つからないとき(想定外)の、枝が空の木。
     *
     * @return array<string, mixed>
     */
    public function emptyTree(): array
    {
        return $this->treeResult('url', '', ['url' => '', 'title' => null, 'headings' => [], 'menu_item_count' => 0], [], 0, 0, 0, ['links' => 0, 'url' => 0], null);
    }

    /**
     * @param  array{url: string, title: ?string, headings: list<string>, menu_item_count: int}  $top
     * @param  list<array{name: string, url: ?string, page_count: int, pages: list<string>, other_page_count: int}>  $branches
     * @param  array{links: int, url: int}  $source
     * @return array<string, mixed>
     */
    private function treeResult(string $mode, string $originUrl, array $top, array $branches, int $otherBranchCount, int $totalFetched, int $pagesWithinOrigin, array $source, ?string $menuSource): array
    {
        return [
            'mode' => $mode,
            'origin_url' => $originUrl,
            'top' => $top,
            'branches' => $branches,
            'other_branch_count' => $otherBranchCount,
            'total_fetched_pages' => $totalFetched,
            'pages_within_origin' => $pagesWithinOrigin,
            'outside_origin_count' => $totalFetched - $pagesWithinOrigin,
            'second_level_source' => $source,
            'menu_source' => $menuSource,
            'origin_widened' => false,
        ];
    }

    /**
     * 代替(mode='url')の枝。URLの階層のまま、枝の名前にはそのインデックス
     * ページのtitleを優先する(取れていなければURLのセグメントをデコード
     * したもの)。第1階層・第2階層の上限はメニュー版と同じconfig。
     *
     * @param  Collection<int, AnalysisCrawledPage>  $within  起点URL配下のページ
     * @param  array{origin_url: string, host: string, path: string}  $scope
     * @return array{0: list<array{name: string, url: ?string, page_count: int, pages: list<string>, other_page_count: int}>, 1: int}
     */
    private function buildUrlModeBranches(Collection $within, array $scope): array
    {
        $secondLimit = (int) config('admin_comparison_pptx.site_hierarchy_tree_second_level_limit');
        [$groups, $flatLabels] = $this->groupUrlBranches($within, $scope);

        $branches = [];
        foreach ($groups as $segment => $info) {
            $labels = $this->deprioritizeSamplePages($info['labels'], PHP_INT_MAX);
            // 枝の名前にしたインデックスページのtitleは、第2階層にも重ねて出さない
            // (ページ数には数えたまま)。
            if ($info['index_title'] !== null) {
                $position = array_search($info['index_title'], $labels, true);
                if ($position !== false) {
                    unset($labels[$position]);
                    $labels = array_values($labels);
                }
            }
            $branches[] = [
                'name' => $info['index_title'] ?? $this->decodeSegmentForDisplay((string) $segment),
                'url' => null,
                'page_count' => $info['count'],
                'pages' => array_slice($labels, 0, $secondLimit),
                'other_page_count' => max(0, count($labels) - $secondLimit),
            ];
        }

        if ($flatLabels !== []) {
            $labels = $this->deprioritizeSamplePages($flatLabels, PHP_INT_MAX);
            $branches[] = [
                'name' => (string) config('admin_comparison_pptx.site_hierarchy_flat_pages_heading'),
                'url' => null,
                'page_count' => count($flatLabels),
                'pages' => array_slice($labels, 0, $secondLimit),
                'other_page_count' => max(0, count($labels) - $secondLimit),
            ];
        }

        return $this->limitBranches($branches);
    }

    /**
     * メニュー版の枝。第2階層は、その項目のページから実際にリンクされている
     * 巡回済みページを優先し、求められない項目だけURLの配下で代用する。
     *
     * @param  list<array{label: string, url: string, key: string}>  $menuItems
     * @param  Collection<int, AnalysisCrawledPage>  $within
     * @param  array{origin_url: string, host: string, path: string}  $scope
     * @return array{0: list<array{name: string, url: ?string, page_count: int, pages: list<string>, other_page_count: int}>, 1: int, 2: array{links: int, url: int}}
     */
    private function buildMenuModeBranches(array $menuItems, Collection $within, array $scope): array
    {
        $secondLimit = (int) config('admin_comparison_pptx.site_hierarchy_tree_second_level_limit');

        /** @var array<string, AnalysisCrawledPage> $byKey */
        $byKey = [];
        foreach ($within as $page) {
            $byKey[$this->urlKey((string) $page->url)] ??= $page;
            if ($page->final_url !== null) {
                $byKey[$this->urlKey((string) $page->final_url)] ??= $page;
            }
        }

        $topKey = $this->urlKey($scope['origin_url']);
        $menuKeys = array_fill_keys(array_column($menuItems, 'key'), true);

        $assigned = [];
        $source = ['links' => 0, 'url' => 0];
        $branches = [];

        foreach ($menuItems as $item) {
            $itemPage = $byKey[$item['key']] ?? null;
            if ($itemPage !== null) {
                $assigned[$itemPage->id] = true;
            }

            $children = [];

            if ($itemPage !== null) {
                foreach ($this->linkedFetchedPages($itemPage, $byKey) as $linked) {
                    $linkedKey = $this->urlKey((string) ($linked->final_url ?? $linked->url));
                    if (isset($assigned[$linked->id]) || $linkedKey === $topKey || isset($menuKeys[$linkedKey])) {
                        continue;
                    }
                    $children[] = $linked;
                    $assigned[$linked->id] = true;
                }
            }

            $usedSource = 'links';
            if ($children === []) {
                $usedSource = 'url';
                $prefix = $this->directoryKeyOf($item['key']);
                foreach ($within as $page) {
                    $pageKey = $this->urlKey((string) ($page->final_url ?? $page->url));
                    if (isset($assigned[$page->id]) || $pageKey === $topKey || isset($menuKeys[$pageKey])
                        || ! str_starts_with($pageKey, $prefix)) {
                        continue;
                    }
                    $children[] = $page;
                    $assigned[$page->id] = true;
                }
            }
            if ($children !== []) {
                $source[$usedSource]++;
            }

            $labels = array_map(fn (AnalysisCrawledPage $page) => $this->pageLabel($page), $children);
            $branches[] = [
                'name' => $item['label'],
                'url' => $item['url'],
                'page_count' => count($children) + ($itemPage !== null ? 1 : 0),
                'pages' => array_slice($labels, 0, $secondLimit),
                'other_page_count' => max(0, count($labels) - $secondLimit),
            ];
        }

        [$limited, $otherBranchCount] = $this->limitBranches($branches);

        return [$limited, $otherBranchCount, $source];
    }

    /**
     * 第1階層の上限を適用する。ページ数の多い順(同数はメニューの並び順)に
     * 上限件数を選び、表示順はもとの並び順に戻す。
     *
     * @param  list<array{name: string, url: ?string, page_count: int, pages: list<string>, other_page_count: int}>  $branches
     * @return array{0: list<array{name: string, url: ?string, page_count: int, pages: list<string>, other_page_count: int}>, 1: int}
     */
    private function limitBranches(array $branches): array
    {
        $limit = (int) config('admin_comparison_pptx.site_hierarchy_tree_first_level_limit');
        if (count($branches) <= $limit) {
            return [$branches, 0];
        }

        $indexed = array_map(null, array_keys($branches), $branches);
        usort($indexed, fn (array $a, array $b) => [$b[1]['page_count'], $a[0]] <=> [$a[1]['page_count'], $b[0]]);
        $chosen = array_slice($indexed, 0, $limit);
        usort($chosen, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return [array_map(fn (array $pair) => $pair[1], $chosen), count($branches) - $limit];
    }

    /**
     * そのページの保存済みHTML(レンダリング済み優先)に含まれる、本文側の
     * (<header>/<nav>/<footer>の外の)リンクのうち、巡回済みのページ。
     * リンクの出現順。読めない場合は空。
     *
     * @param  array<string, AnalysisCrawledPage>  $byKey
     * @return list<AnalysisCrawledPage>
     */
    private function linkedFetchedPages(AnalysisCrawledPage $page, array $byKey): array
    {
        $html = $this->readStoredHtml($page->rendered_html_path) ?? $this->readStoredHtml($page->raw_html_path);
        if ($html === null) {
            return [];
        }

        $pageUrl = (string) ($page->final_url ?? $page->url);
        $result = [];
        foreach ($this->linkExtractor->extractAbsoluteLinks($html, $pageUrl, true) as $link) {
            $target = $byKey[$this->urlKey($link)] ?? null;
            if ($target !== null && $target->id !== $page->id) {
                $result[$target->id] = $target;
            }
        }

        return array_values($result);
    }

    /**
     * TOP(起点URL)のページ名・主な見出し・保存済みHTML(レンダリング済み
     * →静的の順)を読む。起点が採用ページ/トップページ由来ならそのAnalysisPage、
     * なければ巡回済みページから同じURLのものを探す。
     *
     * 依頼CM-1/CM-2: 起点が「転送先ホストの一番上」へ広がった場合(入力が一番上で、
     * 転送で奥のページへ着いた場合)、起点のURLと保存済みのページのURLが一致しない。
     * そのときは、同じホストの保存済みページ(採用ページ→トップページの順)の
     * HTMLを使う ―― 実際に取得したそのページのメニューが一番上のメニューを含む
     * ため。リンクの解決はそのページ自身のURLを基準にし、ページ名・見出しは
     * (起点そのもののページではないため)出さない。読めなければHTMLは空。
     *
     * @param  array{origin_url: string, host: string, path: string}  $scope
     * @param  Collection<int, AnalysisCrawledPage>  $crawled
     * @return array{title: ?string, headings: list<string>, base_url: string, htmls: list<array{source: string, html: string}>}
     */
    private function readTopPage(WebsiteAnalysis $websiteAnalysis, array $scope, Collection $crawled): array
    {
        $originKey = $this->urlKey($scope['origin_url']);
        $title = null;
        $paths = [];
        $baseUrl = $scope['origin_url'];
        $matched = false;
        $fallback = null;

        foreach ([PageType::Recruit, PageType::Homepage] as $type) {
            $analysisPage = AnalysisPage::query()
                ->where('website_analysis_id', $websiteAnalysis->id)
                ->where('page_type', $type)
                ->first();
            if ($analysisPage === null) {
                continue;
            }

            $pageUrl = (string) ($analysisPage->final_url ?? $analysisPage->url);
            if ($this->urlKey($pageUrl) === $originKey) {
                $title = $this->nullIfBlank($analysisPage->title);
                $paths = ['rendered' => $analysisPage->rendered_html_path, 'raw' => $analysisPage->raw_html_path];
                $matched = true;
                break;
            }

            if ($fallback === null
                && strtolower((string) parse_url($pageUrl, PHP_URL_HOST)) === $scope['host']
                && ($analysisPage->rendered_html_path !== null || $analysisPage->raw_html_path !== null)) {
                $fallback = [$analysisPage, $pageUrl];
            }
        }

        if (! $matched) {
            $crawledTop = $crawled->first(fn (AnalysisCrawledPage $page) => $this->urlKey((string) ($page->final_url ?? $page->url)) === $originKey);
            if ($crawledTop !== null) {
                $title = $this->nullIfBlank($crawledTop->title);
                $paths = ['rendered' => $crawledTop->rendered_html_path, 'raw' => $crawledTop->raw_html_path];
                $matched = true;
            } elseif ($fallback !== null) {
                $paths = ['rendered' => $fallback[0]->rendered_html_path, 'raw' => $fallback[0]->raw_html_path];
                $baseUrl = $fallback[1];
            }
        }

        $htmls = [];
        foreach (['rendered', 'raw'] as $kind) {
            $html = $this->readStoredHtml($paths[$kind] ?? null);
            if ($html !== null) {
                $htmls[] = ['source' => $kind, 'html' => $html];
            }
        }

        return [
            'title' => $title,
            'headings' => ($matched && $htmls !== []) ? $this->topHeadings($htmls[0]['html']) : [],
            'base_url' => $baseUrl,
            'htmls' => $htmls,
        ];
    }

    /**
     * @return list<string>
     */
    private function topHeadings(string $html): array
    {
        $limit = (int) config('admin_comparison_pptx.site_hierarchy_tree_top_heading_limit');
        $maxChars = (int) config('admin_comparison_pptx.site_hierarchy_tree_label_max_chars');

        $headings = $this->htmlAnalyzer->extractHeadingTexts($html);
        $h1 = array_values(array_filter($headings, fn (array $h) => $h['level'] === 1));
        $h2 = array_values(array_filter($headings, fn (array $h) => $h['level'] === 2));

        $result = [];
        foreach ([...$h1, ...$h2] as $heading) {
            $text = mb_strlen($heading['text']) > $maxChars ? mb_substr($heading['text'], 0, $maxChars - 1).'…' : $heading['text'];
            if (! in_array($text, $result, true)) {
                $result[] = $text;
            }
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * TOPのメニューの項目(文字＋リンク先)。起点URL配下のものだけを、メニューの
     * 並び順のまま、リンク先URLの重複を除いて返す。他の項目の配下にある項目
     * (ドロップダウンの子など)は第1階層に含めず、その項目の枝に含まれる。
     *
     * @param  array{origin_url: string, host: string, path: string}  $scope
     * @return list<array{label: string, url: string, key: string}>
     */
    private function extractMenuItems(string $html, string $baseUrl, array $scope): array
    {
        $originKey = $this->urlKey($scope['origin_url']);
        $items = [];
        $seenKeys = [];

        foreach ($this->htmlAnalyzer->extractMenuLinks($html) as $link) {
            $url = $this->linkExtractor->resolveHref($baseUrl, $link['href']);
            if ($url === null || ! $this->scopeResolver->isWithinScope($url, null, $scope)) {
                continue;
            }

            $key = $this->urlKey($url);
            if ($key === $originKey || isset($seenKeys[$key])) {
                continue;
            }

            $label = $this->cleanMenuLabel($link['label']);
            if ($label === '') {
                continue;
            }

            $seenKeys[$key] = true;
            $items[] = ['label' => $label, 'url' => $url, 'key' => $key];
        }

        // 他の項目の配下にある項目(ディレクトリ形式のURLの下)は、その項目の枝に
        // 含まれるため第1階層からは外す。
        $keys = array_column($items, 'key');

        return array_values(array_filter($items, function (array $item) use ($keys) {
            foreach ($keys as $other) {
                if ($other !== $item['key'] && $this->isDirectoryLikeKey($other) && str_starts_with($item['key'], $other.'/')) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * メニューの文字から、アイコンフォントの文字列(例: "keyboard_arrow_right"
     * がカルチャーの前に付く)を除く。小文字の英単語がアンダースコアで2つ
     * 以上つながったものだけを対象にする(ほかの文字は変えない)。
     */
    private function cleanMenuLabel(string $label): string
    {
        $cleaned = preg_replace('/(?<![A-Za-z0-9])[a-z]+(?:_[a-z]+)+/u', '', $label) ?? $label;

        return trim(preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned);
    }

    /**
     * URLの比較用キー: ホスト(小文字)＋パス(末尾の"/"と"index.html"等を
     * 除く)＋クエリ。fragmentは含めない。
     */
    private function urlKey(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $path = preg_replace('#/index\.(?:html?|php)$#i', '/', $path) ?? $path;
        $path = rtrim($path, '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $host.$path.$query;
    }

    /** 最後のセグメントに"."を含まない(=ディレクトリ形式の)キーか。ホストだけ(ルート)は含めない。 */
    private function isDirectoryLikeKey(string $key): bool
    {
        if (str_contains($key, '?')) {
            return false;
        }
        $slash = strpos($key, '/');
        if ($slash === false) {
            return false;
        }
        $lastSegment = substr($key, (int) strrpos($key, '/') + 1);

        return $lastSegment !== '' && ! str_contains($lastSegment, '.');
    }

    /**
     * その項目の配下とみなすURLキーの接頭辞(末尾"/"つき)。ディレクトリ形式は
     * そのまま、ファイル形式(.htmlなど)は拡張子を除いたものを配下の接頭辞とする。
     */
    private function directoryKeyOf(string $key): string
    {
        $withoutQuery = explode('?', $key)[0];
        if ($this->isDirectoryLikeKey($withoutQuery)) {
            return $withoutQuery.'/';
        }

        return (preg_replace('/\.[A-Za-z0-9]+$/', '', $withoutQuery) ?? $withoutQuery).'/';
    }

    private function pageLabel(AnalysisCrawledPage $page): string
    {
        $title = trim((string) $page->title);
        if ($title !== '') {
            return $title;
        }

        $parts = parse_url((string) ($page->final_url ?? $page->url));
        $path = $this->decodeSegmentForDisplay((string) ($parts['path'] ?? '/'));

        return $path === '' ? '/' : $path;
    }

    private function nullIfBlank(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function readStoredHtml(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk('analysis');
        if (! $disk->exists($path)) {
            return null;
        }

        $html = $disk->get($path);

        return is_string($html) && $html !== '' ? $html : null;
    }

    /**
     * @return Collection<int, AnalysisCrawledPage>
     */
    private function fetchedPages(WebsiteAnalysis $websiteAnalysis): Collection
    {
        return AnalysisCrawledPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
            ->orderBy('id')
            ->get(['id', 'url', 'final_url', 'title', 'raw_html_path', 'rendered_html_path']);
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
