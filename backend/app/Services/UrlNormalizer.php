<?php

namespace App\Services;

use App\Exceptions\InvalidUrlException;

class UrlNormalizer
{
    /**
     * Docker内部サービス名やループバックアドレスなど、明らかに危険なホスト名。
     * 分析実行時にはanalyzer側でより厳密なSSRFチェックを別途行う。
     *
     * @var list<string>
     */
    private const BLOCKED_HOSTS = [
        'localhost',
        '127.0.0.1',
        '::1',
        '[::1]',
        'host.docker.internal',
        'gateway.docker.internal',
        'backend',
        'postgres',
        'redis',
        'analyzer',
        'mailpit',
    ];

    /**
     * URLを正規化する。不正・危険と判断した場合はInvalidUrlExceptionを投げる。
     */
    public function normalize(string $rawUrl): string
    {
        $value = trim($rawUrl);

        if ($value === '') {
            throw new InvalidUrlException('URLを入力してください。');
        }

        if (! preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $value)) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidUrlException('URLの形式が正しくありません。');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidUrlException('http または https のURLを指定してください。');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidUrlException('ユーザー名・パスワードを含むURLは指定できません。');
        }

        $host = strtolower($parts['host']);

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new InvalidUrlException('このホストへのURLは登録できません。');
        }

        $port = $parts['port'] ?? null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        if ($port === $defaultPort) {
            $port = null;
        }

        $path = $parts['path'] ?? '';
        $path = rtrim($path, '/');

        $normalized = "{$scheme}://{$host}";

        if ($port !== null) {
            $normalized .= ":{$port}";
        }

        $normalized .= $path;

        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?'.$parts['query'];
        }

        // fragment (#...) は意図的に破棄する。

        return $normalized;
    }
}
