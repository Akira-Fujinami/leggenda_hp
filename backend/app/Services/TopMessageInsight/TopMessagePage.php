<?php

namespace App\Services\TopMessageInsight;

/**
 * 依頼CQ: トップメッセージ/制度のページ1枚ぶん。$textはAIへ渡した本文
 * (上限で切った後のもの)で、サーバー側の確認(TopMessageInsightVerifier)は
 * この文字列だけと突き合わせる ―― AIが見ていない部分を根拠にしない。
 */
final class TopMessagePage
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $title,
        public readonly string $text,
        public readonly bool $inScope = true,
        public readonly int $fullTextLength = 0,
        public readonly ?string $html = null,
    ) {}

    /**
     * 同じページを別の書き方(末尾のスラッシュ・#以降・ホスト名の大文字小文字)で
     * 二重に数えない、またAIが返したsource_urlを入力のページと照合するための比較用の形。
     */
    public static function keyOf(string $url): string
    {
        $url = preg_replace('/#.*$/', '', trim($url)) ?? $url;
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return rtrim($url, '/');
        }

        return strtolower((string) ($parts['scheme'] ?? 'https')).'://'.strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .rtrim((string) ($parts['path'] ?? ''), '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    public function withText(string $text): self
    {
        return new self($this->url, $this->title, $text, $this->inScope, $this->fullTextLength, $this->html);
    }
}
