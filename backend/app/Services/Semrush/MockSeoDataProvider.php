<?php

namespace App\Services\Semrush;

use App\Services\Semrush\Data\SeoBacklinkMetrics;
use App\Services\Semrush\Data\SeoCompetitorMetrics;
use App\Services\Semrush\Data\SeoDomainMetrics;
use App\Services\Semrush\Data\SeoKeywordMetrics;
use App\Services\Semrush\Data\SeoProviderResult;

/**
 * 開発環境向けのモックProvider。ドメイン名から決定論的な擬似データを
 * 生成する(同じドメインなら毎回同じ値になり、開発時の表示確認がしやすい)。
 * is_mock=trueを必ず設定し、本物のデータとして表示させない。
 */
class MockSeoDataProvider implements SeoDataProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function fetch(string $domain, string $database): SeoProviderResult
    {
        $seed = crc32($domain);

        $authorityScore = $seed % 71; // 0-70の範囲で擬似生成
        $organicTraffic = ($seed % 5000) + ($seed % 97) * 10;
        $organicKeywords = ($seed % 800) + 5;
        $top10 = (int) round($organicKeywords * 0.12);
        $top3 = (int) round($top10 * 0.25);
        $backlinks = ($seed % 3000) + 20;
        $referringDomains = (int) round($backlinks * 0.08) + 3;
        $competitorCount = ($seed % 15) + 1;

        return new SeoProviderResult(
            isMock: true,
            database: $database,
            domainScope: 'root_domain',
            domain: new SeoDomainMetrics(
                authorityScore: (float) $authorityScore,
                organicTrafficEstimate: $organicTraffic,
                organicKeywordsCount: $organicKeywords,
                paidSearchPresent: $seed % 2 === 0,
            ),
            keywords: new SeoKeywordMetrics(
                top3KeywordsCount: $top3,
                top10KeywordsCount: $top10,
            ),
            backlinks: new SeoBacklinkMetrics(
                backlinksCount: $backlinks,
                referringDomainsCount: $referringDomains,
            ),
            competitors: new SeoCompetitorMetrics(
                competitorDomainsCount: $competitorCount,
                topCompetitorDomains: [],
            ),
            rawForStorage: ['note' => 'mock provider - deterministic pseudo data', 'domain' => $domain],
        );
    }
}
