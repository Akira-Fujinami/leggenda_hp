<?php

namespace App\Services\Semrush;

/**
 * SEO_PROVIDER設定からSeoDataProvider実装を解決する。
 * 'semrush'が指定されているのにAPIキーが無い場合は、モックへ自動的に
 * フォールバックせず、明確な設定エラーとして例外を投げる
 * (「キーが無いのでこっそりモックが本物のふりをする」事態を防ぐため)。
 *
 * mockの利用は二重にガードする:
 * 1. production環境では常に拒否(ALLOW_MOCK_PROVIDERSの値に関わらず)。
 * 2. production以外でも、ALLOW_MOCK_PROVIDERS=trueを明示しない限り拒否する
 *    (通常のdevelopment起動で意図せずモックが使われることを防ぐため)。
 */
class SeoProviderFactory
{
    public function make(): SeoDataProvider
    {
        $provider = (string) config('analysis.seo_provider', 'mock');

        return match ($provider) {
            'mock' => $this->makeMock(),
            'semrush' => $this->makeSemrush(),
            default => throw new SeoProviderException(
                'SEO_PROVIDER_INVALID',
                "SEO_PROVIDERに不明な値が設定されています: {$provider}",
                isRetryable: false,
            ),
        };
    }

    private function makeMock(): SeoDataProvider
    {
        if (app()->environment('production')) {
            throw new SeoProviderException(
                'MOCK_PROVIDER_NOT_ALLOWED_IN_PRODUCTION',
                'production環境ではSEO_PROVIDER=mockを使用できません。意図せずモックデータが本物の分析結果として'.
                '表示されるのを防ぐため、実際のSEO Provider(semrush等)を設定してください。',
                isRetryable: false,
            );
        }

        if (! (bool) config('analysis.allow_mock_providers')) {
            throw new SeoProviderException(
                'MOCK_PROVIDER_NOT_ALLOWED',
                'SEO_PROVIDER=mockを使用するにはALLOW_MOCK_PROVIDERS=trueの設定が必要です。',
                isRetryable: false,
            );
        }

        return new MockSeoDataProvider;
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
