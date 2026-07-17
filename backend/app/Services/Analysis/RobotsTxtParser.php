<?php

namespace App\Services\Analysis;

/**
 * robots.txtを解析する。User-agent: * のグループのみをMVP対象とする
 * (当方のクローラーは固有のUser-agent向けルールを想定しないため)。
 */
class RobotsTxtParser
{
    /**
     * @return array{disallow: list<string>, allow: list<string>, sitemaps: list<string>, parse_error: bool}
     */
    public function parse(string $content): array
    {
        $disallow = [];
        $allow = [];
        $sitemaps = [];
        $inRelevantGroup = false;
        $sawAnyUserAgent = false;
        $parseError = false;

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        foreach ($lines as $line) {
            $line = preg_replace('/#.*/', '', $line) ?? '';
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! str_contains($line, ':')) {
                $parseError = true;

                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $fieldLower = strtolower($field);

            if ($fieldLower === 'sitemap') {
                if ($value !== '') {
                    $sitemaps[] = $value;
                }

                continue;
            }

            if ($fieldLower === 'user-agent') {
                $sawAnyUserAgent = true;
                $inRelevantGroup = $value === '*';

                continue;
            }

            if (! $inRelevantGroup) {
                continue;
            }

            if ($fieldLower === 'disallow' && $value !== '') {
                $disallow[] = $value;
            } elseif ($fieldLower === 'allow' && $value !== '') {
                $allow[] = $value;
            }
        }

        return [
            'disallow' => $disallow,
            'allow' => $allow,
            'sitemaps' => array_values(array_unique($sitemaps)),
            'parse_error' => $parseError && ! $sawAnyUserAgent,
        ];
    }

    /**
     * 標準の「最長一致優先、同じ長さならAllow優先」ルールで判定する。
     *
     * @param  array{disallow: list<string>, allow: list<string>}  $parsed
     */
    public function isPathAllowed(array $parsed, string $path): bool
    {
        $bestLength = -1;
        $bestIsAllow = true;

        foreach ($parsed['disallow'] as $rule) {
            if ($rule === '') {
                continue;
            }
            if (str_starts_with($path, $rule) && strlen($rule) > $bestLength) {
                $bestLength = strlen($rule);
                $bestIsAllow = false;
            }
        }

        foreach ($parsed['allow'] as $rule) {
            if (str_starts_with($path, $rule) && strlen($rule) >= $bestLength) {
                $bestLength = strlen($rule);
                $bestIsAllow = true;
            }
        }

        return $bestIsAllow;
    }
}
