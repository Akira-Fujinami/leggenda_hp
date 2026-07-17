<?php

namespace App\Services\Analysis;

/**
 * 静的HTML/レンダリング後HTMLを解析し、基本的なSEO・コンテンツ指標を抽出する。
 * 装飾画像の判定などの高度な処理はMVPでは行わない。
 */
class HtmlSeoAnalyzer
{
    private const SNS_HOSTS = [
        'twitter.com', 'x.com', 'facebook.com', 'instagram.com', 'youtube.com',
        'linkedin.com', 'tiktok.com',
    ];

    private const CONTACT_KEYWORDS = ['contact', 'inquiry', 'お問い合わせ', 'お問合せ', '問い合わせ'];

    private const RESERVATION_KEYWORDS = ['reserve', 'reservation', 'booking', '予約'];

    private const DOCUMENT_REQUEST_KEYWORDS = ['資料請求', 'catalog', 'download', '資料ダウンロード'];

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $html, string $pageUrl): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // DOMDocumentはcharset宣言が無い/検出できないHTMLをISO-8859-1として
        // 解釈し文字化けするため、先頭にXMLエンコーディング宣言を付与して
        // 常にUTF-8として読ませる (このPI自体はDOM構造には残らない)。
        // XXE対策: 外部エンティティ・DTDを読み込まない。
        $dom->loadHTML(
            '<?xml encoding="utf-8"?>'.$html,
            LIBXML_NOENT | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $pageHost = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));

        return [
            'title' => $this->analyzeTitle($xpath),
            'meta_description' => $this->analyzeMetaDescription($xpath),
            'h1' => $this->analyzeH1($xpath),
            'canonical' => $this->analyzeCanonical($xpath, $pageUrl),
            'robots_meta' => $this->analyzeRobotsMeta($xpath),
            'ogp' => $this->analyzeOgp($xpath),
            'structured_data' => $this->analyzeStructuredData($xpath),
            'images' => $this->analyzeImages($xpath),
            'links' => $this->analyzeLinks($xpath, $pageHost),
            'content' => $this->analyzeContent($dom, $xpath),
            'forms' => $this->analyzeForms($xpath),
        ];
    }

    private function analyzeTitle(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//title');
        $count = $nodes?->length ?? 0;
        $text = $count > 0 ? trim($nodes->item(0)->textContent) : null;

        return [
            'present' => $count > 0 && $text !== '',
            'text' => $text,
            'length' => $text !== null ? mb_strlen($text) : null,
            'count' => $count,
            'within_recommended_length' => $text !== null ? (mb_strlen($text) >= 10 && mb_strlen($text) <= 65) : null,
        ];
    }

    private function analyzeMetaDescription(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//meta[translate(@name, "DESCRIPTION", "description")="description"]');
        $count = $nodes?->length ?? 0;
        $text = $count > 0 ? trim((string) $nodes->item(0)->getAttribute('content')) : null;

        return [
            'present' => $count > 0 && $text !== '',
            'text' => $text,
            'length' => $text !== null ? mb_strlen($text) : null,
            'count' => $count,
        ];
    }

    private function analyzeH1(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//h1');
        $texts = [];
        foreach ($nodes ?? [] as $node) {
            $texts[] = trim($node->textContent);
        }

        return [
            'count' => $nodes?->length ?? 0,
            'texts' => $texts,
        ];
    }

    private function analyzeCanonical(\DOMXPath $xpath, string $pageUrl): array
    {
        $nodes = $xpath->query('//link[translate(@rel, "CANONICAL", "canonical")="canonical"]');

        if (($nodes?->length ?? 0) === 0) {
            return ['present' => false, 'url' => null, 'is_self_referencing' => null, 'is_valid_url' => null];
        }

        $href = trim((string) $nodes->item(0)->getAttribute('href'));
        $isValid = filter_var($href, FILTER_VALIDATE_URL) !== false;

        return [
            'present' => true,
            'url' => $href,
            'is_valid_url' => $isValid,
            'is_self_referencing' => $isValid ? rtrim($href, '/') === rtrim($pageUrl, '/') : null,
        ];
    }

    private function analyzeRobotsMeta(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//meta[translate(@name, "ROBOTS", "robots")="robots"]');

        if (($nodes?->length ?? 0) === 0) {
            return ['present' => false, 'index' => true, 'follow' => true, 'raw' => null];
        }

        $raw = strtolower(trim((string) $nodes->item(0)->getAttribute('content')));

        return [
            'present' => true,
            'index' => ! str_contains($raw, 'noindex'),
            'follow' => ! str_contains($raw, 'nofollow'),
            'raw' => $raw,
        ];
    }

    private function analyzeOgp(\DOMXPath $xpath): array
    {
        $result = [];
        foreach (['title', 'description', 'image', 'url', 'type'] as $key) {
            $nodes = $xpath->query("//meta[@property=\"og:{$key}\"]");
            $result[$key] = ($nodes?->length ?? 0) > 0 ? trim((string) $nodes->item(0)->getAttribute('content')) : null;
        }

        return $result;
    }

    private function analyzeStructuredData(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//script[translate(@type, "APLICONJSD-", "aplconjsd-")="application/ld+json"]');
        $types = [];
        $parseErrors = 0;

        foreach ($nodes ?? [] as $node) {
            $decoded = json_decode($node->textContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $parseErrors++;

                continue;
            }

            $items = array_is_list($decoded ?? []) ? $decoded : [$decoded];
            foreach ($items as $item) {
                if (is_array($item) && isset($item['@type'])) {
                    $types[] = is_array($item['@type']) ? implode(',', $item['@type']) : (string) $item['@type'];
                }
            }
        }

        return [
            'count' => $nodes?->length ?? 0,
            'types' => array_values(array_unique($types)),
            'parse_errors' => $parseErrors,
        ];
    }

    private function analyzeImages(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//img');
        $total = $nodes?->length ?? 0;
        $withAlt = 0;
        $emptyAlt = 0;
        $missingAlt = 0;

        foreach ($nodes ?? [] as $node) {
            if (! $node->hasAttribute('alt')) {
                $missingAlt++;

                continue;
            }

            if (trim($node->getAttribute('alt')) === '') {
                $emptyAlt++;

                continue;
            }

            $withAlt++;
        }

        return [
            'total' => $total,
            'with_alt' => $withAlt,
            'empty_alt' => $emptyAlt,
            'missing_alt' => $missingAlt,
            'alt_coverage' => $total > 0 ? round($withAlt / $total, 4) : null,
        ];
    }

    private function analyzeLinks(\DOMXPath $xpath, string $pageHost): array
    {
        $nodes = $xpath->query('//a[@href]');
        $internal = 0;
        $external = 0;
        $mailto = 0;
        $tel = 0;
        $line = 0;
        $sns = 0;
        $contactLike = 0;

        foreach ($nodes ?? [] as $node) {
            $href = trim($node->getAttribute('href'));
            $text = mb_strtolower(trim($node->textContent));
            $hrefLower = mb_strtolower($href);

            if (str_starts_with($href, 'mailto:')) {
                $mailto++;

                continue;
            }

            if (str_starts_with($href, 'tel:')) {
                $tel++;

                continue;
            }

            if (str_contains($hrefLower, 'line.me') || str_contains($hrefLower, 'lin.ee')) {
                $line++;
            }

            $host = strtolower((string) parse_url($href, PHP_URL_HOST));

            if ($host !== '') {
                foreach (self::SNS_HOSTS as $snsHost) {
                    if (str_ends_with($host, $snsHost)) {
                        $sns++;
                        break;
                    }
                }

                if ($host === $pageHost) {
                    $internal++;
                } else {
                    $external++;
                }
            } else {
                // host無し (相対パス) は内部リンクとして扱う。
                $internal++;
            }

            foreach (self::CONTACT_KEYWORDS as $keyword) {
                if (str_contains($hrefLower, $keyword) || str_contains($text, mb_strtolower($keyword))) {
                    $contactLike++;
                    break;
                }
            }
        }

        return [
            'internal' => $internal,
            'external' => $external,
            'mailto' => $mailto,
            'tel' => $tel,
            'line' => $line,
            'sns' => $sns,
            'contact_like' => $contactLike,
        ];
    }

    private function analyzeContent(\DOMDocument $dom, \DOMXPath $xpath): array
    {
        $bodyNodes = $xpath->query('//body');
        $bodyText = ($bodyNodes?->length ?? 0) > 0 ? trim($bodyNodes->item(0)->textContent) : '';
        $bodyText = preg_replace('/\s+/u', ' ', $bodyText) ?? '';

        $htmlNodes = $xpath->query('//html');
        $lang = ($htmlNodes?->length ?? 0) > 0 ? ($htmlNodes->item(0)->getAttribute('lang') ?: null) : null;

        $viewportNodes = $xpath->query('//meta[translate(@name, "VIEWPORT", "viewport")="viewport"]');
        $faviconNodes = $xpath->query('//link[contains(translate(@rel, "ICON", "icon"), "icon")]');
        $manifestNodes = $xpath->query('//link[translate(@rel, "MANIFEST", "manifest")="manifest"]');

        return [
            'body_length' => mb_strlen($bodyText),
            'word_count' => $this->estimateWordCount($bodyText),
            'lang' => $lang,
            'viewport_present' => ($viewportNodes?->length ?? 0) > 0,
            'favicon_present' => ($faviconNodes?->length ?? 0) > 0,
            'manifest_present' => ($manifestNodes?->length ?? 0) > 0,
        ];
    }

    /**
     * 日本語は分かち書きがないため、英単語は空白区切り、CJK文字は1文字1語とみなして概算する。
     */
    private function estimateWordCount(string $text): int
    {
        $cjkCount = preg_match_all('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{FF66}-\x{FF9D}]/u', $text);
        $withoutCjk = preg_replace('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{FF66}-\x{FF9D}]/u', ' ', $text) ?? '';
        $latinWords = preg_split('/\s+/u', trim($withoutCjk), -1, PREG_SPLIT_NO_EMPTY);

        return $cjkCount + count($latinWords ?: []);
    }

    private function analyzeForms(\DOMXPath $xpath): array
    {
        $forms = $xpath->query('//form');
        $inputs = $xpath->query('//input');
        $buttons = $xpath->query('//button');
        $submits = $xpath->query('//input[translate(@type, "SUBMIT", "submit")="submit"] | //button[translate(@type, "SUBMIT", "submit")="submit" or not(@type)]');
        $telLinks = $xpath->query('//a[starts-with(@href, "tel:")]');
        $mailLinks = $xpath->query('//a[starts-with(@href, "mailto:")]');

        $bodyNodes = $xpath->query('//body');
        $bodyTextLower = ($bodyNodes?->length ?? 0) > 0 ? mb_strtolower($bodyNodes->item(0)->textContent) : '';

        return [
            'form_count' => $forms?->length ?? 0,
            'input_count' => $inputs?->length ?? 0,
            'button_count' => $buttons?->length ?? 0,
            'submit_count' => $submits?->length ?? 0,
            'tel_link_count' => $telLinks?->length ?? 0,
            'mail_link_count' => $mailLinks?->length ?? 0,
            'contact_like' => $this->containsAny($bodyTextLower, self::CONTACT_KEYWORDS),
            'reservation_like' => $this->containsAny($bodyTextLower, self::RESERVATION_KEYWORDS),
            'document_request_like' => $this->containsAny($bodyTextLower, self::DOCUMENT_REQUEST_KEYWORDS),
        ];
    }

    /**
     * @param  list<string>  $keywords
     */
    private function containsAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}
