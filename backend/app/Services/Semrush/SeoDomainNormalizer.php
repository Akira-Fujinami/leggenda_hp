<?php

namespace App\Services\Semrush;

/**
 * SemrushへはURLではなくドメイン単位で渡す必要があるため、
 * Website.normalized_urlからルートドメインを導出する。
 *
 * 簡略化: 完全なPublic Suffix List実装ではなく、日本でよく使われる
 * 複合サフィックス(co.jp等)のみを個別に扱う軽量な実装。未知の複合TLDは
 * 「末尾2ラベル」をルートドメインとみなす(一般的な.com/.net等では正しく
 * 動作するが、稀な複合TLDでは誤る可能性がある)。
 */
class SeoDomainNormalizer
{
    private const COMPOUND_SUFFIXES = [
        'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp', 'ad.jp', 'ed.jp', 'gr.jp', 'lg.jp',
        'co.uk', 'org.uk', 'me.uk', 'gov.uk', 'ac.uk',
        'co.kr', 'com.au', 'net.au', 'org.au', 'co.nz', 'com.br', 'com.cn',
    ];

    private const BLOCKED_HOSTS = ['localhost', 'backend', 'postgres', 'redis', 'analyzer', 'mailpit', 'frontend'];

    /**
     * @throws SeoProviderException  ホストが不正・IP・localhost等の場合
     */
    public function normalize(string $urlOrHost): SeoNormalizedDomain
    {
        $host = $this->extractHost($urlOrHost);

        if ($host === null || $host === '') {
            throw new SeoProviderException('SEMRUSH_INVALID_DOMAIN', "ドメインを判定できません: {$urlOrHost}", isRetryable: false);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new SeoProviderException('SEMRUSH_INVALID_DOMAIN', 'IPアドレスはSemrushへ渡せません。', isRetryable: false);
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new SeoProviderException('SEMRUSH_INVALID_DOMAIN', "許可されていないホストです: {$host}", isRetryable: false);
        }

        // IDN(国際化ドメイン名) -> punycode変換。
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = $ascii;
            }
        }

        $fullHost = strtolower(preg_replace('/^www\./', '', $host));
        $rootDomain = $this->extractRootDomain($fullHost);

        return new SeoNormalizedDomain(
            fullHost: $fullHost,
            rootDomain: $rootDomain,
            isSubdomain: $fullHost !== $rootDomain,
        );
    }

    private function extractHost(string $urlOrHost): ?string
    {
        if (! str_contains($urlOrHost, '://')) {
            $urlOrHost = 'https://'.$urlOrHost;
        }

        $host = parse_url($urlOrHost, PHP_URL_HOST);

        return $host !== null ? strtolower(trim($host, '.')) : null;
    }

    private function extractRootDomain(string $host): string
    {
        $labels = explode('.', $host);

        if (count($labels) <= 2) {
            return $host;
        }

        $lastTwo = implode('.', array_slice($labels, -2));

        foreach (self::COMPOUND_SUFFIXES as $suffix) {
            if (str_ends_with($host, '.'.$suffix) || $host === $suffix) {
                $suffixLabelCount = count(explode('.', $suffix));

                return implode('.', array_slice($labels, -($suffixLabelCount + 1)));
            }
        }

        return $lastTwo;
    }
}
