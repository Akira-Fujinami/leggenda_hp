<?php

namespace App\Services\Report;

use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;

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
 */
class AdminComparisonSiteHierarchyBuilder
{
    /**
     * @return array{origin_url: string, branches: list<array{name: string, page_count: int, sample_pages: list<string>}>, other_branch_count: int}
     */
    public function build(WebsiteAnalysis $selfWebsiteAnalysis): array
    {
        $originUrl = $this->resolveOriginUrl($selfWebsiteAnalysis);
        if ($originUrl === null || $originUrl === '') {
            return ['origin_url' => '', 'branches' => [], 'other_branch_count' => 0];
        }

        $originParts = parse_url($originUrl);
        $originHost = strtolower((string) ($originParts['host'] ?? ''));
        $originPath = $this->normalizeDirectoryPath((string) ($originParts['path'] ?? '/'));

        if ($originHost === '') {
            return ['origin_url' => $originUrl, 'branches' => [], 'other_branch_count' => 0];
        }

        $sampleLimit = (int) config('admin_comparison_pptx.site_hierarchy_sample_pages_per_branch');

        $pages = AnalysisCrawledPage::query()
            ->where('website_analysis_id', $selfWebsiteAnalysis->id)
            ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
            ->get(['url', 'final_url', 'title']);

        /** @var array<string, array{count: int, index_title: ?string, labels: list<string>}> $branches */
        $branches = [];

        foreach ($pages as $page) {
            $effectiveUrl = (string) ($page->final_url ?? $page->url);
            $parts = parse_url($effectiveUrl);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($host !== $originHost) {
                continue;
            }

            $path = (string) ($parts['path'] ?? '');
            if (! str_starts_with($path, $originPath)) {
                continue;
            }

            $remainder = substr($path, strlen($originPath));
            $segments = array_values(array_filter(explode('/', $remainder), fn (string $s) => $s !== ''));
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
            ];
        }

        // 依頼CB-3: 枝の絞り方はページ数の多い順(依頼者提案、素朴で説明
        // しやすい ―― サイト内でどの区分に最も厚みがあるかがそのまま伝わる)。
        usort($branchList, fn (array $a, array $b) => $b['page_count'] <=> $a['page_count']);

        $limit = (int) config('admin_comparison_pptx.site_hierarchy_branch_limit');
        $otherBranchCount = max(0, count($branchList) - $limit);
        $branchList = array_slice($branchList, 0, $limit);

        return [
            'origin_url' => $originUrl,
            'branches' => $branchList,
            'other_branch_count' => $otherBranchCount,
        ];
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
