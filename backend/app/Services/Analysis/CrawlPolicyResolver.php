<?php

namespace App\Services\Analysis;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use Illuminate\Support\Facades\Storage;

/**
 * サイト全ページ巡回(依頼C・D)の判定(robots.txt遵守・ホスト許可リスト)を
 * 1箇所に集約する。CrawlWebsiteJob(seed)とCrawlWebsitePageJob(1ページずつ)の
 * 両方から呼ばれる ―― 1ジョブ=1ページに分割した各ジョブは独立プロセスで
 * 状態を共有できないため、robots.txtの再パース・許可ホストの再計算は
 * 「ステートレスに毎回再計算する」設計にする(いずれも保存済みDBの内容を
 * 読むだけの軽い処理で、HTTPは発生しない)。
 */
class CrawlPolicyResolver
{
    /**
     * @return ?array{disallow: list<string>, allow: list<string>, sitemaps: list<string>, parse_error: bool}
     *         nullはrobots.txtが取得できていない(200でも404でもない=Unavailable、
     *         またはFetchRobotsJob自体が未完了/行が無い)場合。この場合クロールは
     *         中止する(依頼C-3)。
     */
    public function resolveRobotsPolicy(int $websiteAnalysisId, RobotsTxtParser $robotsTxtParser): ?array
    {
        $robotsPage = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysisId)
            ->where('page_type', PageType::Robots)
            ->first();

        if ($robotsPage === null) {
            return null;
        }

        if ($robotsPage->http_status === 404) {
            return ['disallow' => [], 'allow' => [], 'sitemaps' => [], 'parse_error' => false];
        }

        if ($robotsPage->http_status !== 200 || $robotsPage->raw_html_path === null) {
            return null;
        }

        $content = $this->readStoredFile($robotsPage->raw_html_path);

        if ($content === null) {
            return null;
        }

        return $robotsTxtParser->parse($content);
    }

    /**
     * @return list<string>
     */
    public function allowedHosts(int $websiteAnalysisId): array
    {
        $homepage = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysisId)
            ->where('page_type', PageType::Homepage)
            ->first();

        $recruit = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysisId)
            ->where('page_type', PageType::Recruit)
            ->first();

        $hosts = [];
        foreach ([$homepage, $recruit] as $page) {
            if ($page === null) {
                continue;
            }
            $host = strtolower((string) parse_url($page->final_url ?? $page->url, PHP_URL_HOST));
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    public function readStoredFile(string $path): ?string
    {
        if (! Storage::disk('analysis')->exists($path)) {
            return null;
        }

        return Storage::disk('analysis')->get($path);
    }
}
