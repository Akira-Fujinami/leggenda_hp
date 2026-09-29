<?php

namespace App\Services\Analysis;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;

/**
 * 依頼CB-3(2026-09-24)由来、依頼CF-1(2026-09-29)で共通化: 「起点URL」
 * (採用ページのfinal_url/urlを優先し、無ければトップページ、それも無ければ
 * Website.url ―― 既存の定義、この依頼でも変えない)と、あるURLがその配下に
 * あるかどうかの判定を1箇所に集約する。
 *
 * 背景: AdminComparisonSiteHierarchyBuilder(App\Services\Report、階層図
 * スライドの組み立て)がresolveScope()/isWithinScope()として私有していた
 * ロジックと全く同じ判定を、依頼CF-1でCrawlWebsitePageJob(App\Services\
 * Analysis、巡回の取得順序)も必要とするようになった。Reportレイヤーの
 * クラスにAnalysisレイヤーのジョブを依存させると層の向きが逆になるため、
 * Analysisレイヤーのこのクラスへ抜き出し、AdminComparisonSiteHierarchy
 * Builder側がこのクラスを注入して使う形に直した(同じ判定を2箇所に
 * 持たない、依頼者指定)。
 *
 * isWithinScope()はAnalysisCrawledPageモデルではなくurl/finalUrlの生文字列
 * を受け取る ―― 巡回中(pending、final_urlがまだ無いページ)にも使える
 * ようにするため(final_urlがnullならurlへfallbackする、既存の挙動を
 * そのまま踏襲)。
 */
class CrawlOriginScopeResolver
{
    /**
     * 起点URLをホスト・パスに分解する。起点URLが無い、またはホストが
     * 読み取れない場合はnull(呼び出し側は「配下0件」として扱う)。
     *
     * @return array{origin_url: string, host: string, path: string}|null
     */
    public function resolveScope(WebsiteAnalysis $websiteAnalysis): ?array
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
    public function isWithinScope(string $url, ?string $finalUrl, array $scope): bool
    {
        if ($scope['host'] === '') {
            return false;
        }

        $parts = parse_url($finalUrl ?? $url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== $scope['host']) {
            return false;
        }

        return str_starts_with((string) ($parts['path'] ?? ''), $scope['path']);
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
