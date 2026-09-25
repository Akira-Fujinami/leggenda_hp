<?php

namespace App\Services\Report;

use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
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
 */
class AdminComparisonSiteHierarchyBuilder
{
    /**
     * @return array{origin_url: string, branches: list<array{name: string, page_count: int, sample_pages: list<string>, name_is_url_segment: bool}>, other_branch_count: int, total_fetched_pages: int, pages_within_origin: int}
     */
    public function build(WebsiteAnalysis $selfWebsiteAnalysis): array
    {
        $pages = $this->fetchedPages($selfWebsiteAnalysis);
        $totalFetchedPages = $pages->count();

        $scope = $this->resolveScope($selfWebsiteAnalysis);
        if ($scope === null) {
            return [
                'origin_url' => '',
                'branches' => [],
                'other_branch_count' => 0,
                'total_fetched_pages' => $totalFetchedPages,
                'pages_within_origin' => 0,
            ];
        }

        $sampleLimit = (int) config('admin_comparison_pptx.site_hierarchy_sample_pages_per_branch');

        /** @var array<string, array{count: int, index_title: ?string, labels: list<string>}> $branches */
        $branches = [];
        $pagesWithinOrigin = 0;

        foreach ($pages as $page) {
            if (! $this->isWithinScope($page, $scope)) {
                continue;
            }
            $pagesWithinOrigin++;

            $segments = $this->pathSegmentsBelowOrigin($page, $scope);
            if ($segments === []) {
                continue;
            }

            $branchKey = $segments[0];
            $title = trim((string) $page->title);
            $branches[$branchKey]['count'] = ($branches[$branchKey]['count'] ?? 0) + 1;
            $branches[$branchKey]['index_title'] ??= null;

            // 依頼CB-3: 「その階層のインデックスページ」= この枝の中で
            // パスセグメントが1つだけ(=枝の直下そのもの)のページ。
            if (count($segments) === 1 && $title !== '') {
                $branches[$branchKey]['index_title'] = $title;
            }

            $label = $title !== '' ? $title : end($segments);
            if (count($branches[$branchKey]['labels'] ?? []) < $sampleLimit) {
                $branches[$branchKey]['labels'][] = $label;
            }
        }

        $branchList = [];
        foreach ($branches as $segment => $info) {
            $branchList[] = [
                'name' => $info['index_title'] ?? $segment,
                'page_count' => $info['count'],
                'sample_pages' => $info['labels'],
                // 依頼CC-3③: ページ名を捏造しない ―― インデックスページを
                // 巡回できておらずURLのパスセグメントのまま枝名にしている
                // 場合、その旨をGenerator側で分かる形にする(捏造しない、
                // かつ「これが正式なページ名だ」と誤解させないため)。
                'name_is_url_segment' => $info['index_title'] === null,
            ];
        }

        // 依頼CB-3: 枝の絞り方はページ数の多い順(依頼者提案、素朴で説明
        // しやすい ―― サイト内でどの区分に最も厚みがあるかがそのまま伝わる)。
        usort($branchList, fn (array $a, array $b) => $b['page_count'] <=> $a['page_count']);

        $limit = (int) config('admin_comparison_pptx.site_hierarchy_branch_limit');
        $otherBranchCount = max(0, count($branchList) - $limit);
        $branchList = array_slice($branchList, 0, $limit);

        return [
            'origin_url' => $scope['origin_url'],
            'branches' => $branchList,
            'other_branch_count' => $otherBranchCount,
            'total_fetched_pages' => $totalFetchedPages,
            'pages_within_origin' => $pagesWithinOrigin,
        ];
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

        $scope = $this->resolveScope($websiteAnalysis);
        if ($scope === null) {
            return ['origin_url' => $scope['origin_url'] ?? null, 'total_fetched' => $totalFetched, 'within_origin' => 0];
        }

        $withinOrigin = 0;
        foreach ($pages as $page) {
            if ($this->isWithinScope($page, $scope)) {
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
     * 起点URLをホスト・パスに分解する。起点URLが無い、またはホストが
     * 読み取れない場合はnull(呼び出し側は「配下0件」として扱う)。
     *
     * @return array{origin_url: string, host: string, path: string}|null
     */
    private function resolveScope(WebsiteAnalysis $websiteAnalysis): ?array
    {
        $originUrl = $this->resolveOriginUrl($websiteAnalysis);
        if ($originUrl === null || $originUrl === '') {
            return null;
        }

        $originParts = parse_url($originUrl);
        $originHost = strtolower((string) ($originParts['host'] ?? ''));
        if ($originHost === '') {
            return ['origin_url' => $originUrl, 'host' => '', 'path' => '/'];
        }

        return [
            'origin_url' => $originUrl,
            'host' => $originHost,
            'path' => $this->normalizeDirectoryPath((string) ($originParts['path'] ?? '/')),
        ];
    }

    /**
     * @param  array{host: string, path: string}  $scope
     */
    private function isWithinScope(AnalysisCrawledPage $page, array $scope): bool
    {
        if ($scope['host'] === '') {
            return false;
        }

        $parts = parse_url((string) ($page->final_url ?? $page->url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== $scope['host']) {
            return false;
        }

        return str_starts_with((string) ($parts['path'] ?? ''), $scope['path']);
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

    private function resolveOriginUrl(WebsiteAnalysis $websiteAnalysis): ?string
    {
        $recruit = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('page_type', PageType::Recruit)
            ->first();
        $recruitUrl = $recruit !== null ? ($recruit->final_url ?? $recruit->url) : null;
        if ($recruitUrl !== null && $recruitUrl !== '') {
            return $recruitUrl;
        }

        $homepage = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('page_type', PageType::Homepage)
            ->first();
        $homepageUrl = $homepage !== null ? ($homepage->final_url ?? $homepage->url) : null;
        if ($homepageUrl !== null && $homepageUrl !== '') {
            return $homepageUrl;
        }

        return $websiteAnalysis->website?->url;
    }

    /**
     * 起点URLのパスを、ディレクトリとして扱える形(末尾"/")に正規化する。
     * 末尾がファイル名らしい(最後のセグメントに"."を含む)場合は、その
     * ファイル名を取り除いた1つ上のディレクトリまでにする。
     */
    private function normalizeDirectoryPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if (str_ends_with($path, '/')) {
            return $path;
        }

        $lastSlash = strrpos($path, '/');
        $lastSegment = $lastSlash === false ? $path : substr($path, $lastSlash + 1);

        if (str_contains($lastSegment, '.')) {
            return $lastSlash === false ? '/' : substr($path, 0, $lastSlash + 1);
        }

        return $path.'/';
    }
}
