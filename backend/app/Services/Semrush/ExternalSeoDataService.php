<?php

namespace App\Services\Semrush;

use App\Models\ExternalDataSnapshot;
use App\Models\WebsiteAnalysis;
use Illuminate\Support\Facades\Storage;

/**
 * 外部SEOデータ取得のオーケストレーション。
 * キャッシュ確認 -> (無ければ)日次上限確認 -> Provider呼び出し -> 利用量記録 ->
 * ExternalDataSnapshot保存、までを一括して行う。
 *
 * Provider固有のレスポンス形式はSeoProviderResultの時点で正規化済みのため、
 * このクラスより上位(Job/Controller)にはSemrush固有の知識が一切漏れない。
 */
class ExternalSeoDataService
{
    private const OPERATION = 'domain_overview';

    public function __construct(
        private readonly SeoProviderFactory $providerFactory,
        private readonly SeoDomainNormalizer $domainNormalizer,
        private readonly ApiUsageLogger $usageLogger,
    ) {
    }

    /**
     * @throws SeoProviderException  取得できなかった場合(呼び出し側でunavailable等に変換すること)
     */
    public function fetchFor(WebsiteAnalysis $websiteAnalysis, int $analysisId): ExternalDataSnapshot
    {
        $provider = $this->providerFactory->make();
        $database = (string) config('services.semrush.database', 'us');
        $requestedDomain = $websiteAnalysis->website->normalized_url;
        $normalizedDomain = $this->domainNormalizer->normalize($requestedDomain);
        $lookupDomain = $normalizedDomain->domainForLookup();
        $scope = $normalizedDomain->scope();

        $cached = $this->findFreshCache($provider->name(), $provider->isMock(), $lookupDomain, $database, $scope);

        if ($cached !== null) {
            return ExternalDataSnapshot::query()->updateOrCreate(
                ['website_analysis_id' => $websiteAnalysis->id, 'provider' => $provider->name(), 'operation' => self::OPERATION],
                [
                    'requested_domain' => $requestedDomain,
                    'domain' => $lookupDomain,
                    'scope' => $scope,
                    'database' => $database,
                    'status' => $cached->status,
                    'raw_storage_path' => $cached->raw_storage_path,
                    'normalized_data' => $cached->normalized_data,
                    'is_mock' => $cached->is_mock,
                    'fetched_at' => $cached->fetched_at,
                    'expires_at' => $cached->expires_at,
                    'source_snapshot_id' => $cached->id,
                    'error_code' => null,
                    'error_message' => null,
                ],
            );
        }

        if ($this->usageLogger->hasReachedDailyLimit($provider->name())) {
            throw new SeoProviderException('SEMRUSH_DAILY_LIMIT_REACHED', '本日の外部SEO API利用上限に達しています。', isRetryable: false);
        }

        $requestHash = $this->usageLogger->requestHash($provider->name(), self::OPERATION, $lookupDomain, $database);
        $started = microtime(true);

        try {
            $result = $provider->fetch($lookupDomain, $database);
        } catch (SeoProviderException $e) {
            $this->usageLogger->log(
                provider: $provider->name(),
                operation: self::OPERATION,
                analysisId: $analysisId,
                websiteAnalysisId: $websiteAnalysis->id,
                requestHash: $requestHash,
                status: 'error',
                durationMs: (int) round((microtime(true) - $started) * 1000),
                errorCode: $e->errorCode,
            );

            throw $e;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $this->usageLogger->log(
            provider: $provider->name(),
            operation: self::OPERATION,
            analysisId: $analysisId,
            websiteAnalysisId: $websiteAnalysis->id,
            requestHash: $requestHash,
            status: 'success',
            httpStatus: 200,
            unitsUsed: 10,
            durationMs: $durationMs,
        );

        $rawStoragePath = null;
        if ($result->rawForStorage !== []) {
            $rawStoragePath = "analyses/{$analysisId}/websites/{$websiteAnalysis->id}/metadata/semrush_{$provider->name()}.json";
            Storage::disk('analysis')->put($rawStoragePath, json_encode($result->rawForStorage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $cacheHours = (int) config('analysis.external_data_cache_hours', 24);

        return ExternalDataSnapshot::query()->updateOrCreate(
            ['website_analysis_id' => $websiteAnalysis->id, 'provider' => $provider->name(), 'operation' => self::OPERATION],
            [
                'requested_domain' => $requestedDomain,
                'domain' => $lookupDomain,
                'scope' => $scope,
                'database' => $database,
                'status' => 'success',
                'raw_storage_path' => $rawStoragePath,
                'normalized_data' => $result->toNormalizedArray(),
                'is_mock' => $result->isMock,
                'fetched_at' => now(),
                'expires_at' => now()->addHours($cacheHours),
                'source_snapshot_id' => null,
                'error_code' => null,
                'error_message' => null,
            ],
        );
    }

    /**
     * providerだけでなくis_mock/scopeも明示的に絞り込むことで、
     * 「provider名とis_mockの対応が常に1:1」という前提が将来崩れた場合や、
     * サブドメインスコープが実装された場合にも、異なる種類のデータを
     * キャッシュとして誤って再利用しないようにする(防御的な二重チェック)。
     */
    private function findFreshCache(string $provider, bool $isMock, string $domain, string $database, string $scope): ?ExternalDataSnapshot
    {
        return ExternalDataSnapshot::query()
            ->where('provider', $provider)
            ->where('is_mock', $isMock)
            ->where('operation', self::OPERATION)
            ->where('domain', $domain)
            ->where('database', $database)
            ->where('scope', $scope)
            ->where('status', 'success')
            ->where('expires_at', '>', now())
            ->latest('fetched_at')
            ->first();
    }
}
