<?php

namespace App\Services\Semrush;

use App\Services\Semrush\Data\SeoBacklinkMetrics;
use App\Services\Semrush\Data\SeoCompetitorMetrics;
use App\Services\Semrush\Data\SeoDomainMetrics;
use App\Services\Semrush\Data\SeoKeywordMetrics;
use App\Services\Semrush\Data\SeoProviderResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Semrush Analytics API (CSVベースのレポート型API) を呼び出す実装。
 *
 * 注意: このクラスはPhase 3の作業中に実際のSemrush APIへ接続してレスポンスを
 * 検証したものではない(ユーザーの指示により実APIは呼び出していない)。
 * エンドポイント・カラム構成はSemrushの公開ドキュメントに基づく標準的な
 * レポート形式(domain_ranks/domain_organic/backlinks_overview/domain_domains)を
 * 前提にしているが、契約プランやAPIバージョンによって実際の列名・利用可能な
 * レポートが異なる場合があるため、本番投入前に実キーを用いた検証が必須。
 */
class SemrushSeoDataProvider implements SeoDataProvider
{
    public function name(): string
    {
        return 'semrush';
    }

    public function isMock(): bool
    {
        return false;
    }

    public function fetch(string $domain, string $database): SeoProviderResult
    {
        $apiKey = (string) config('services.semrush.api_key');

        if ($apiKey === '') {
            throw new SeoProviderException('SEMRUSH_NOT_CONFIGURED', 'SEMRUSH_API_KEYが設定されていません。', isRetryable: false);
        }

        $domainOverview = $this->fetchDomainOverview($domain, $database, $apiKey);
        $backlinksResult = $this->fetchBacklinksOverview($domain, $apiKey);
        $competitors = $this->fetchCompetitors($domain, $database, $apiKey);

        // authority_scoreはbacklinks_overviewのascore列(Semrushの実際の
        // Authority Score)からのみ取得する。domain_ranksのRk(ランク)から
        // 逆算した近似値は「捏造」に当たるため使わない ―― 取得できなければ
        // nullのまま返し、呼び出し側(FetchExternalSeoDataJob)でunavailableとして扱う。
        $domain0 = $domainOverview['domain'];
        if ($domain0 !== null) {
            $domain0 = new SeoDomainMetrics(
                authorityScore: $backlinksResult['authorityScore'],
                organicTrafficEstimate: $domain0->organicTrafficEstimate,
                organicKeywordsCount: $domain0->organicKeywordsCount,
                paidSearchPresent: $domain0->paidSearchPresent,
            );
        }

        return new SeoProviderResult(
            isMock: false,
            database: $database,
            domainScope: 'root_domain',
            domain: $domain0,
            keywords: $domainOverview['keywords'],
            backlinks: $backlinksResult['metrics'],
            competitors: $competitors,
            rawForStorage: [
                'domain_ranks' => $domainOverview['raw'],
                'backlinks_overview' => $backlinksResult['metrics']?->toArray(),
                'domain_domains' => $competitors?->toArray(),
            ],
        );
    }

    /**
     * @return array{domain: ?SeoDomainMetrics, keywords: ?SeoKeywordMetrics, raw: array<string, mixed>}
     */
    private function fetchDomainOverview(string $domain, string $database, string $apiKey): array
    {
        $rows = $this->request('domain_ranks', $apiKey, [
            'domain' => $domain,
            'database' => $database,
            'export_columns' => 'Db,Dn,Rk,Or,Ot,Oc,Ad,At,Ac',
        ]);

        $row = $rows[0] ?? null;

        if ($row === null) {
            return ['domain' => null, 'keywords' => null, 'raw' => []];
        }

        return [
            'domain' => new SeoDomainMetrics(
                authorityScore: null, // fetch()側でbacklinks_overviewのascoreから埋める
                organicTrafficEstimate: isset($row['Ot']) ? (int) $row['Ot'] : null,
                organicKeywordsCount: isset($row['Or']) ? (int) $row['Or'] : null,
                paidSearchPresent: isset($row['Ad']) ? ((int) $row['Ad'] > 0) : null,
            ),
            // Semrushの上位3位/10位キーワード数は、Organic Search Keywordsレポート
            // (type=domain_organic)を全件走査して順位を集計する必要があり、
            // 現在の契約プランで確実に取得できるかを実キーで未検証のため、
            // 存在しない値を捏造するよりnullのまま返す(unavailable扱いにする)。
            'keywords' => new SeoKeywordMetrics(
                top3KeywordsCount: null,
                top10KeywordsCount: null,
            ),
            'raw' => $row,
        ];
    }

    /**
     * @return array{metrics: ?SeoBacklinkMetrics, authorityScore: ?float}
     */
    private function fetchBacklinksOverview(string $domain, string $apiKey): array
    {
        $rows = $this->request('backlinks_overview', $apiKey, [
            'target' => $domain,
            'target_type' => 'root_domain',
            'export_columns' => 'total,domains_num,ascore',
        ], baseUrlOverride: (string) config('services.semrush.analytics_base_url'));

        $row = $rows[0] ?? null;

        if ($row === null) {
            return ['metrics' => null, 'authorityScore' => null];
        }

        return [
            'metrics' => new SeoBacklinkMetrics(
                backlinksCount: isset($row['total']) ? (int) $row['total'] : null,
                referringDomainsCount: isset($row['domains_num']) ? (int) $row['domains_num'] : null,
            ),
            // ascore = Semrush Authority Score(backlinks_overviewレポートの実列)。
            'authorityScore' => isset($row['ascore']) && is_numeric($row['ascore']) ? (float) $row['ascore'] : null,
        ];
    }

    private function fetchCompetitors(string $domain, string $database, string $apiKey): ?SeoCompetitorMetrics
    {
        $rows = $this->request('domain_domains', $apiKey, [
            'domain' => $domain,
            'database' => $database,
            'export_columns' => 'Dn,Cr',
            'display_limit' => '10',
        ]);

        if ($rows === []) {
            return null;
        }

        return new SeoCompetitorMetrics(
            competitorDomainsCount: count($rows),
            topCompetitorDomains: array_values(array_filter(array_column($rows, 'Dn'))),
        );
    }

    /**
     * @param  array<string, string>  $params
     * @return list<array<string, string>>
     */
    private function request(string $type, string $apiKey, array $params, ?string $baseUrlOverride = null): array
    {
        $baseUrl = $baseUrlOverride !== null && $baseUrlOverride !== ''
            ? $baseUrlOverride
            : (string) config('services.semrush.base_url');

        $timeout = (int) config('services.semrush.timeout', 30);
        $maxRetries = (int) config('services.semrush.max_retries', 1);

        $query = array_merge(['type' => $type, 'key' => $apiKey], $params);

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $response = Http::baseUrl($baseUrl)->timeout($timeout)->get('/', $query);
            } catch (ConnectionException $e) {
                $lastException = $e;

                continue;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new SeoProviderException('SEMRUSH_AUTH_FAILED', 'Semrush APIの認証に失敗しました。', isRetryable: false);
            }

            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After');

                throw new SeoProviderException(
                    'SEMRUSH_RATE_LIMITED',
                    'Semrush APIのレート制限に達しました。',
                    isRetryable: true,
                    retryAfterSeconds: $retryAfter !== null ? (int) $retryAfter : null,
                );
            }

            if (! $response->successful()) {
                // Semrushはユニット残量不足等をレスポンスボディのエラーメッセージで返すことがある。
                $body = $response->body();

                if (str_contains($body, 'ERROR 50') || str_contains(strtolower($body), 'not enough units')) {
                    throw new SeoProviderException('SEMRUSH_QUOTA_EXCEEDED', 'Semrush APIの利用可能ユニットが不足しています。', isRetryable: false);
                }

                throw new SeoProviderException('SEMRUSH_REQUEST_FAILED', 'Semrush APIの呼び出しに失敗しました。', isRetryable: true);
            }

            return $this->parseCsv($response->body());
        }

        throw new SeoProviderException('SEMRUSH_UNAVAILABLE', 'Semrush APIに接続できませんでした。', isRetryable: true, previous: $lastException);
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $body): array
    {
        $lines = array_values(array_filter(explode("\n", trim($body)), fn ($line) => trim($line) !== ''));

        if ($lines === [] || str_starts_with($lines[0], 'ERROR')) {
            return [];
        }

        $headers = array_map('trim', explode(';', $lines[0]));
        $rows = [];

        foreach (array_slice($lines, 1) as $line) {
            $values = array_map('trim', explode(';', $line));
            $rows[] = array_combine($headers, array_pad($values, count($headers), null));
        }

        return $rows;
    }
}
