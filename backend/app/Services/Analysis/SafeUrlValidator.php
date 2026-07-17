<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Exceptions\Analysis\AnalysisException;

/**
 * 分析処理が実際にURLへアクセスする直前に行う、深いSSRFチェック。
 * URL登録時のUrlNormalizer(表記の正規化・明らかに危険なホスト名の拒否)とは別に、
 * DNS解決結果の実IPまで検証することで、DNSリバインディングや
 * 登録後にDNSが変更されるケースにも対応する。
 */
class SafeUrlValidator
{
    /**
     * @var list<string>
     */
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'host.docker.internal',
        'gateway.docker.internal',
        'backend',
        'postgres',
        'redis',
        'analyzer',
        'mailpit',
        'frontend',
    ];

    /**
     * @return array{url: string, host: string, resolved_ips: list<string>}
     */
    public function assertSafe(string $rawUrl): array
    {
        $parts = parse_url($rawUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new AnalysisException(AnalysisErrorCode::InvalidUrl, "URLの形式が正しくありません: {$rawUrl}");
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new AnalysisException(AnalysisErrorCode::UnsafeUrl, "許可されていないスキームです: {$scheme}");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new AnalysisException(AnalysisErrorCode::UnsafeUrl, 'ユーザー情報を含むURLは許可されていません。');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443, 8080, 8443], true)) {
            throw new AnalysisException(AnalysisErrorCode::UnsafeUrl, "許可されていないポートです: {$port}");
        }

        $host = strtolower($parts['host']);

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            throw new AnalysisException(AnalysisErrorCode::UnsafeUrl, "アクセスが禁止されているホストです: {$host}");
        }

        $resolvedIps = $this->resolve($host);

        if ($resolvedIps === []) {
            throw new AnalysisException(AnalysisErrorCode::DnsResolutionFailed, "ホスト名を解決できません: {$host}");
        }

        foreach ($resolvedIps as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new AnalysisException(
                    AnalysisErrorCode::PrivateIpBlocked,
                    "解決先IPへのアクセスが禁止されています: {$host} -> {$ip}",
                );
            }
        }

        return ['url' => $rawUrl, 'host' => $host, 'resolved_ips' => $resolvedIps];
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        // parse_url()はIPv6リテラルを `[::1]` のように角括弧付きで返すため、
        // filter_var()/inet_pton()に渡す前に取り除く。
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP)) {
            return [$bare];
        }

        $ips = [];

        $recordsV4 = @dns_get_record($host, DNS_A);
        foreach ($recordsV4 ?: [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }

        $recordsV6 = @dns_get_record($host, DNS_AAAA);
        foreach ($recordsV6 ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    private function isBlockedIp(string $ip): bool
    {
        // ループバック・プライベート・リンクローカル・未指定アドレス等はPHP標準フィルタで判定できる。
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // マルチキャストはNO_RES_RANGEでは弾かれないため個別に判定する。
        return $this->isMulticast($ip);
    }

    private function isMulticast(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return true; // パースできないIPは安全側に倒して拒否する。
        }

        if (strlen($binary) === 4) {
            // IPv4マルチキャスト: 224.0.0.0/4 (先頭4bitが1110)
            return (ord($binary[0]) & 0xF0) === 0xE0;
        }

        // IPv6マルチキャスト: ff00::/8
        return ord($binary[0]) === 0xFF;
    }
}
