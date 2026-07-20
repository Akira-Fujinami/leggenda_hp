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

    private const PRICING_KEYWORDS = ['price', 'pricing', 'plan', 'fee', 'cost', '料金', '価格', 'プラン', '費用'];

    private const FAQ_KEYWORDS = ['faq', 'q&a', 'よくある質問', 'ヘルプ', 'help'];

    private const CASE_STUDY_KEYWORDS = ['case-study', 'case_study', 'testimonial', 'voice', 'works', 'portfolio', '導入事例', '事例', 'お客様の声', '実績'];

    private const COMPANY_INFO_KEYWORDS = ['about', 'company', 'profile', '会社概要', '企業情報', '会社案内'];

    private const PRIVACY_POLICY_KEYWORDS = ['privacy', 'プライバシー', '個人情報保護方針', '個人情報'];

    private const RECRUIT_KEYWORDS = ['recruit', 'careers', 'career', '採用', '求人'];

    /**
     * 外部予約サービスとして既知のホスト。網羅的ではなく、フェイクの検出を
     * 避けるため「確実に予約サービスと分かるドメイン」のみに限定する。
     *
     * @var list<string>
     */
    private const THIRD_PARTY_RESERVATION_HOSTS = [
        'airregi.jp', 'tablecheck.com', 'opentable.com', 'coubic.com', 'hotpepper.jp',
        'ebica.jp', 'toreta.in', 'timerex.net', 'calendly.com',
    ];

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
            'business_links' => $this->analyzeBusinessLinks($xpath, $pageHost),
            'third_party_reservation' => $this->analyzeThirdPartyReservationService($xpath),
            'content' => $this->analyzeContent($dom, $xpath),
            'forms' => $this->analyzeForms($xpath),
            'form_burden' => $this->analyzeFormBurden($xpath),
            'accessibility' => $this->analyzeAccessibilityHeuristics($xpath),
        ];
    }

    /**
     * 営業・信頼性に関わる代表的なページ(料金/FAQ/導入事例/会社概要/
     * プライバシーポリシー/採用情報)へのリンクをキーワードベースで検出する。
     * href・リンクテキスト・aria-label・titleのいずれかにキーワードが
     * 含まれるかで判定し、URL(href)だけの一致では確信度を上限に置かない
     * (実データに存在しない一致を捏造しないため、リンクテキスト等でも
     * 一致した場合のみ高い確信度とする)。
     *
     * @return array<string, array{present: bool, url: ?string, text: ?string, confidence: ?float, link_type: ?string}>
     */
    private function analyzeBusinessLinks(\DOMXPath $xpath, string $pageHost): array
    {
        $categories = [
            'pricing' => self::PRICING_KEYWORDS,
            'faq' => self::FAQ_KEYWORDS,
            'case_study' => self::CASE_STUDY_KEYWORDS,
            'company_info' => self::COMPANY_INFO_KEYWORDS,
            'privacy_policy' => self::PRIVACY_POLICY_KEYWORDS,
            'recruit' => self::RECRUIT_KEYWORDS,
        ];

        $nodes = $xpath->query('//a[@href]');
        $detected = array_fill_keys(array_keys($categories), null);

        foreach ($nodes ?? [] as $node) {
            $href = trim($node->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            $text = trim($node->textContent);
            $ariaLabel = $node instanceof \DOMElement ? trim($node->getAttribute('aria-label')) : '';
            $title = $node instanceof \DOMElement ? trim($node->getAttribute('title')) : '';
            $hrefLower = mb_strtolower($href);
            $textLower = mb_strtolower($text);
            $labelLower = mb_strtolower($ariaLabel.' '.$title);

            foreach ($categories as $category => $keywords) {
                if ($detected[$category] !== null) {
                    continue; // 最初に見つかったリンクを代表として採用する。
                }

                $hrefMatch = $this->containsAny($hrefLower, $keywords);
                $textMatch = $this->containsAny($textLower, $keywords) || $this->containsAny($labelLower, $keywords);

                if (! $hrefMatch && ! $textMatch) {
                    continue;
                }

                $confidence = $hrefMatch && $textMatch ? 0.95 : ($textMatch ? 0.75 : 0.65);
                $representativeText = $text !== '' ? $text : ($ariaLabel !== '' ? $ariaLabel : $title);

                $detected[$category] = [
                    'url' => $href,
                    'text' => mb_substr($representativeText, 0, 100),
                    'confidence' => $confidence,
                    'link_type' => $this->classifyLinkType($href, $pageHost),
                ];
            }
        }

        $result = [];
        foreach (array_keys($categories) as $category) {
            $result[$category] = [
                'present' => $detected[$category] !== null,
                'url' => $detected[$category]['url'] ?? null,
                'text' => $detected[$category]['text'] ?? null,
                'confidence' => $detected[$category]['confidence'] ?? null,
                'link_type' => $detected[$category]['link_type'] ?? null,
            ];
        }

        return $result;
    }

    private function classifyLinkType(string $href, string $pageHost): string
    {
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));

        if ($host === '') {
            return 'internal'; // host無し(相対パス)は内部リンクとして扱う。
        }

        foreach (self::THIRD_PARTY_RESERVATION_HOSTS as $thirdPartyHost) {
            if (str_ends_with($host, $thirdPartyHost)) {
                return 'third_party';
            }
        }

        return $host === $pageHost ? 'internal' : 'external';
    }

    /**
     * 既知の外部予約サービス(第三者ドメイン)へのリンクを検出する。
     * 予約導線が「自社フォーム」か「外部予約サービス」かは事業内容によって
     * 優劣がつくものではないため、採点はせず情報表示のみに用いる。
     *
     * @return array{detected: bool, host: ?string, url: ?string}
     */
    private function analyzeThirdPartyReservationService(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//a[@href]');

        foreach ($nodes ?? [] as $node) {
            $href = trim($node->getAttribute('href'));
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));

            if ($host === '') {
                continue;
            }

            foreach (self::THIRD_PARTY_RESERVATION_HOSTS as $thirdPartyHost) {
                if (str_ends_with($host, $thirdPartyHost)) {
                    return ['detected' => true, 'host' => $host, 'url' => $href];
                }
            }
        }

        return ['detected' => false, 'host' => null, 'url' => null];
    }

    /**
     * フォームごとの入力項目数(hidden/submit/button/imageを除く)を数え、
     * 「最も問い合わせらしいフォーム」(メール系入力やcontact/inquiryを
     * 示すname属性を含むもの)を代表として選び、入力負担を
     * small(必須5以下)/medium(6〜10)/large(11以上)に分類する。
     * 該当するフォームが複数あり問い合わせらしいものが無い場合は、
     * 入力項目数が最も多いフォームを代表とする。
     *
     * @return array{form_found: bool, form_count: int, required_field_count: ?int, total_field_count: ?int, tier: ?string}
     */
    private function analyzeFormBurden(\DOMXPath $xpath): array
    {
        $forms = $xpath->query('//form');

        if (($forms?->length ?? 0) === 0) {
            return ['form_found' => false, 'form_count' => 0, 'required_field_count' => null, 'total_field_count' => null, 'tier' => null];
        }

        $lowerType = 'translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';
        $fieldQuery = ".//input[not({$lowerType}=\"hidden\") and not({$lowerType}=\"submit\") and not({$lowerType}=\"button\") and not({$lowerType}=\"image\")] | .//select | .//textarea";

        $candidates = [];
        foreach ($forms as $form) {
            $fields = $xpath->query($fieldQuery, $form);
            $total = $fields?->length ?? 0;
            $required = 0;
            $contactLike = false;

            foreach ($fields ?? [] as $field) {
                if (! $field instanceof \DOMElement) {
                    continue;
                }

                if ($field->hasAttribute('required') || strtolower($field->getAttribute('aria-required')) === 'true') {
                    $required++;
                }

                $type = strtolower($field->getAttribute('type'));
                $name = strtolower($field->getAttribute('name'));
                if ($type === 'email' || str_contains($name, 'mail') || str_contains($name, 'contact') || str_contains($name, 'inquiry')) {
                    $contactLike = true;
                }
            }

            $candidates[] = ['total' => $total, 'required' => $required, 'contact_like' => $contactLike];
        }

        usort($candidates, function (array $a, array $b): int {
            if ($a['contact_like'] !== $b['contact_like']) {
                return $a['contact_like'] ? -1 : 1;
            }

            return $b['total'] <=> $a['total'];
        });

        $chosen = $candidates[0];

        $tier = match (true) {
            $chosen['required'] <= 5 => 'small',
            $chosen['required'] <= 10 => 'medium',
            default => 'large',
        };

        return [
            'form_found' => true,
            'form_count' => count($candidates),
            'required_field_count' => $chosen['required'],
            'total_field_count' => $chosen['total'],
            'tier' => $tier,
        ];
    }

    /**
     * アクセシビリティの簡易判定。厳密なDOM順序検証やlabel/inputの
     * 個別対応チェックまでは行わず、MVPとして粗い判定に留める。
     */
    private function analyzeAccessibilityHeuristics(\DOMXPath $xpath): array
    {
        $labelCount = $xpath->query('//label')?->length ?? 0;
        $inputCount = $xpath->query('//input[not(@type="hidden")]')?->length ?? 0;

        $submits = $xpath->query('//input[translate(@type, "SUBMIT", "submit")="submit"] | //button[translate(@type, "SUBMIT", "submit")="submit" or not(@type)]');
        $buttonsWithoutName = 0;
        foreach ($submits ?? [] as $node) {
            $text = trim($node->textContent);
            $ariaLabel = $node instanceof \DOMElement ? trim($node->getAttribute('aria-label')) : '';
            $value = $node instanceof \DOMElement ? trim($node->getAttribute('value')) : '';
            if ($text === '' && $ariaLabel === '' && $value === '') {
                $buttonsWithoutName++;
            }
        }

        return [
            'heading_order_ok' => $this->isHeadingOrderValid($xpath),
            'form_label_present' => $inputCount === 0 ? null : $labelCount > 0,
            'button_name_present' => ($submits?->length ?? 0) === 0 ? null : $buttonsWithoutName === 0,
        ];
    }

    /**
     * 見出し(h1〜h6)を文書順に並べ、レベルが一度に2段以上飛ばないかを確認する
     * (例: h1→h3のようなスキップを「順序が乱れている」とみなす簡易判定)。
     */
    private function isHeadingOrderValid(\DOMXPath $xpath): bool
    {
        $nodes = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');

        if ($nodes === false || $nodes->length === 0) {
            return true;
        }

        $levels = [];
        foreach ($nodes as $node) {
            $levels[] = (int) substr($node->nodeName, 1);
        }

        $previous = 0;
        foreach ($levels as $level) {
            if ($previous > 0 && $level > $previous + 1) {
                return false;
            }
            $previous = $level;
        }

        return true;
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
