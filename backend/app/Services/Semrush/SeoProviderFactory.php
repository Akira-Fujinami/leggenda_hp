<?php

namespace App\Services\Semrush;

/**
 * SEO_PROVIDER設定からSeoDataProvider実装を解決する。
 * 'semrush'が指定されているのにAPIキーが無い場合は、モックへ自動的に
 * フォールバックせず、明確な設定エラーとして例外を投げる
 * (「キーが無いのでこっそりモックが本物のふりをする」事態を防ぐため)。
 */
class SeoProviderFactory
{
    public function make(): SeoDataProvider
    {
        $provider = (string) config('analysis.seo_provider', 'mock');

        return match ($provider) {
            'mock' => new MockSeoDataProvider,
            'semrush' => $this->makeSemrush(),
            default => throw new SeoProviderException(
                'SEO_PROVIDER_INVALID',
                "SEO_PROVIDERに不明な値が設定されています: {$provider}",
                isRetryable: false,
            ),
        };
    }

    private function makeSemrush(): SeoDataProvider
    {
        $apiKey = (string) config('services.semrush.api_key');

        if ($apiKey === '') {
            throw new SeoProviderException(
                'SEMRUSH_NOT_CONFIGURED',
                'SEO_PROVIDER=semrushが指定されていますが、SEMRUSH_API_KEYが設定されていません。',
                isRetryable: false,
            );
        }

        return new SemrushSeoDataProvider;
    }
}
