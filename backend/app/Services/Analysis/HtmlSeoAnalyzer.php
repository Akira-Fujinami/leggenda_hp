<?php

namespace App\Services\Analysis;

/**
 * 静的HTML/レンダリング後HTMLを解析し、基本的なSEO・コンテンツ指標を抽出する。
 * 装飾画像の判定などの高度な処理はMVPでは行わない。
 */
class HtmlSeoAnalyzer
{
    private const SNS_PLATFORMS = [
        'instagram' => ['instagram.com'],
        'x' => ['x.com', 'twitter.com'],
        'facebook' => ['facebook.com', 'fb.com', 'fb.me'],
        'line' => ['line.me', 'lin.ee'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'tiktok' => ['tiktok.com'],
        'linkedin' => ['linkedin.com'],
        'pinterest' => ['pinterest.com', 'pinterest.jp'],
    ];

    private const CONTACT_KEYWORDS = ['contact', 'inquiry', 'お問い合わせ', 'お問合せ', '問い合わせ'];

    private const RESERVATION_KEYWORDS = ['reserve', 'reservation', 'booking', '予約'];

    private const DOCUMENT_REQUEST_KEYWORDS = ['資料請求', 'catalog', 'download', '資料ダウンロード'];

    private const PRICING_KEYWORDS = ['price', 'pricing', 'plan', 'fee', 'cost', '料金', '価格', 'プラン', '費用'];

    private const FAQ_KEYWORDS = ['faq', 'q&a', 'よくある質問', 'ヘルプ', 'help'];

    /**
     * 導入事例・お客様の声として確度の高い語のみ。「取り組み」「実績」「story」
     * 「works」等の単独では意味が広すぎる語は、誤検出(例:「改善の取り組み」)を
     * 招くため意図的に含めない(強い語のみでdetected=trueとする)。
     */
    private const CASE_STUDY_KEYWORDS = [
        'case-study', 'case_study', 'case study', 'case studies', 'testimonial', 'testimonials',
        'customer stories', 'success stories', 'portfolio', 'reviews',
        '導入事例', '活用事例', '事例紹介', 'お客様の声', '利用者の声', '体験談', '制作実績', '実績紹介', '口コミ', 'レビュー',
    ];

    private const COMPANY_INFO_KEYWORDS = [
        'company', 'corporate', 'about us', 'about', 'organization', 'operator',
        '会社概要', '会社情報', '企業情報', '運営会社', '運営者情報', '法人情報', 'コーポレート', '私たちについて',
    ];

    private const PRIVACY_POLICY_KEYWORDS = ['privacy', 'プライバシー', '個人情報保護方針', '個人情報'];

    private const RECRUIT_KEYWORDS = ['recruit', 'careers', 'career', '採用', '求人'];

    /**
     * 代表フォーム選定において「問い合わせ・相談フォームらしい」とみなす語
     * (action/id/class、input name/type、周辺見出しの判定に共通で使う)。
     */
    private const CONTACT_FORM_SIGNAL_KEYWORDS = [
        'contact', 'inquiry', 'inquire', 'consult', 'consultation', 'support',
        'mail', 'email', 'message', 'subject',
        'お問い合わせ', 'お問合せ', '問い合わせ', '相談', 'ご相談', '連絡',
    ];

    /**
     * 検索フォームらしいことを示す語(代表フォーム選定で問い合わせフォームより
     * 優先度を下げるための除外シグナル)。
     */
    private const SEARCH_FORM_SIGNAL_KEYWORDS = [
        'search', 'query', 'keyword', 'destination', 'checkin', 'checkout',
        '検索', 'キーワード', '目的地', 'チェックイン', 'チェックアウト',
    ];

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

        // script/style/template/noscript配下は解析対象から除外する。libxml2の
        // HTMLパーサーは、script内のJSテンプレートリテラル(例:
        // `<a href="...">${tagHTML}</a>`)に含まれるタグ様の文字列を実際のDOM
        // 要素として誤って構築することがあり、未評価の`${...}`プレースホルダーが
        // そのままリンクテキスト等として抽出されてしまう不具合の根本原因になる。
        // ただしJSON-LD構造化データ(application/ld+json)は解析に必要なため残す。
        $this->stripNonContentElements($xpath);
        $pageHost = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));

        return [
            'page_structure' => $this->analyzePageStructure($xpath),
            'title' => $this->analyzeTitle($xpath),
            'meta_description' => $this->analyzeMetaDescription($xpath),
            'h1' => $this->analyzeH1($xpath),
            'canonical' => $this->analyzeCanonical($xpath, $pageUrl),
            'robots_meta' => $this->analyzeRobotsMeta($xpath),
            'ogp' => $this->analyzeOgp($xpath),
            'structured_data' => $this->analyzeStructuredData($xpath),
            'images' => $this->analyzeImages($xpath),
            'links' => $this->analyzeLinks($xpath, $pageHost),
            'sns_links' => $this->analyzeSnsLinks($xpath, $pageUrl),
            'business_links' => $this->analyzeBusinessLinks($xpath, $pageHost),
            'third_party_reservation' => $this->analyzeThirdPartyReservationService($xpath),
            'content' => $this->analyzeContent($dom, $xpath),
            'forms' => $this->analyzeForms($xpath),
            'form_burden' => $this->analyzeFormBurden($xpath),
            'accessibility' => $this->analyzeAccessibilityHeuristics($xpath),
        ];
    }

    /**
     * script(JSON-LD以外)・style・template・noscriptの部分木をDOMから除去する。
     * 除去は「事後に見つけて弾く」のではなく、以降の全解析処理(analyzeLinks
     * ・analyzeBusinessLinks・analyzeContent等すべて)が最初から script/style
     * 配下を一切見なくなるようにするための根本対策。
     */
    private function stripNonContentElements(\DOMXPath $xpath): void
    {
        $toRemove = [];

        foreach ($xpath->query('//script') ?? [] as $node) {
            if ($node instanceof \DOMElement && strtolower($node->getAttribute('type')) === 'application/ld+json') {
                continue; // 構造化データの抽出に必要なため残す。
            }
            $toRemove[] = $node;
        }

        foreach (['style', 'template', 'noscript'] as $tag) {
            foreach ($xpath->query("//{$tag}") ?? [] as $node) {
                $toRemove[] = $node;
            }
        }

        foreach ($toRemove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * 取得したHTMLが実際のページ内容を持っているかどうかの簡易判定。
     * head/bodyが存在しない、またはbodyがほぼ空(bot拒否ページ・取得失敗の
     * プレースホルダー等)の場合、h1/viewportなどの「無し」判定を単純な
     * not_found(意図的に設置していない)ではなくunavailable(そもそも
     * 判定材料が無い)として扱うためのシグナルにする。
     *
     * @return array{has_head: bool, has_body: bool, body_is_effectively_empty: bool}
     */
    private function analyzePageStructure(\DOMXPath $xpath): array
    {
        $hasHead = ($xpath->query('//head')?->length ?? 0) > 0;
        $bodyNodes = $xpath->query('//body');
        $hasBody = ($bodyNodes?->length ?? 0) > 0;

        $bodyText = $hasBody ? trim((string) $bodyNodes->item(0)?->textContent) : '';

        return [
            'has_head' => $hasHead,
            'has_body' => $hasBody,
            // 数文字程度しかない本文は、bot拒否ページやローディング画面のみが
            // 返された可能性が高いと判断する目安。
            'body_is_effectively_empty' => mb_strlen($bodyText) < 20,
        ];
    }

    /**
     * リンクテキスト等の候補文字列から、未評価のテンプレートプレースホルダー
     * (JSテンプレートリテラル/Vue/Handlebars/Blade等の`${...}`/`{{...}}`/`{%...%}`)や
     * 残存HTMLタグ断片を含むものを除外する。stripNonContentElements()による
     * 根本対策に加え、万一script以外の経路で同種のゴミ文字列が紛れ込んだ場合の
     * 二重の防御として機能する。
     */
    private function sanitizeCandidateText(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        if (preg_match('/\$\{.*?\}|\{\{.*?\}\}|\{%.*?%\}|<[a-zA-Z][^>]*>/u', $text)) {
            return null;
        }

        return $text;
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
                || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')
                || str_contains($href, '${') || str_contains($href, '{{')) {
                continue;
            }

            // 候補テキストは事前にsanitizeし、テンプレート未評価文字列や
            // HTMLタグ断片が残っていれば「テキスト無し」として扱う
            // (stripNonContentElements()の根本対策に対する二重の防御)。
            $text = $this->sanitizeCandidateText(trim($node->textContent)) ?? '';
            $ariaLabel = $node instanceof \DOMElement ? ($this->sanitizeCandidateText(trim($node->getAttribute('aria-label'))) ?? '') : '';
            $title = $node instanceof \DOMElement ? ($this->sanitizeCandidateText(trim($node->getAttribute('title'))) ?? '') : '';
            $textLower = mb_strtolower($text);
            $labelLower = mb_strtolower($ariaLabel.' '.$title);

            // hrefはパス部分のみをセグメント単位で照合する(クエリ文字列に
            // 偶然含まれる短い語(例: トラッキングパラメータ内の"about"や
            // "profile")による誤検出を避けるため)。
            $hrefPathSegments = $this->pathSegments($href);

            foreach ($categories as $category => $keywords) {
                if ($detected[$category] !== null) {
                    continue; // 最初に見つかったリンクを代表として採用する。
                }

                $hrefMatch = $this->segmentsMatchAny($hrefPathSegments, $keywords);
                $textMatch = $this->containsAny($textLower, $keywords) || $this->containsAny($labelLower, $keywords);

                if (! $hrefMatch && ! $textMatch) {
                    continue;
                }

                $confidence = $hrefMatch && $textMatch ? 0.95 : ($textMatch ? 0.75 : 0.65);
                $representativeText = $text !== '' ? $text : ($ariaLabel !== '' ? $ariaLabel : $title);

                $detected[$category] = [
                    'url' => $href,
                    'text' => $representativeText !== '' ? mb_substr($representativeText, 0, 100) : null,
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

    /**
     * hrefのパス部分を"/"区切りの小文字セグメント配列にする(クエリ文字列・
     * フラグメントは含めない)。
     *
     * @return list<string>
     */
    private function pathSegments(string $href): array
    {
        $path = (string) parse_url($href, PHP_URL_PATH);

        return array_values(array_filter(explode('/', mb_strtolower($path)), fn ($segment) => $segment !== ''));
    }

    /**
     * パスセグメントのいずれかが、キーワードと完全一致するか、
     * "keyword-"/"keyword_"で始まるか、"-keyword"/"_keyword"で終わるかを見る。
     * 単純な部分文字列一致(str_contains)と違い、"about-cancellation"のような
     * 無関係な語の一部に短い語(about等)がたまたま含まれるケースを誤検出しない。
     *
     * @param  list<string>  $segments
     * @param  list<string>  $keywords
     */
    private function segmentsMatchAny(array $segments, array $keywords): bool
    {
        foreach ($segments as $segment) {
            foreach ($keywords as $keyword) {
                $keywordLower = mb_strtolower($keyword);

                if (str_contains($keywordLower, ' ')) {
                    // "about us"のようにスペースを含むキーワードはURLパスに
                    // そのまま現れないため、セグメント一致の対象外(テキスト側でのみ判定)。
                    continue;
                }

                if ($segment === $keywordLower
                    || str_starts_with($segment, $keywordLower.'-') || str_starts_with($segment, $keywordLower.'_')
                    || str_ends_with($segment, '-'.$keywordLower) || str_ends_with($segment, '_'.$keywordLower)) {
                    return true;
                }
            }
        }

        return false;
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
     * ページ全体のフォーム数・入力項目総数と、代表フォーム(最も問い合わせ/
     * 相談らしいフォーム)の入力負担を分けて算出する。
     *
     * 代表フォームの選定優先順位:
     *   1. フォーム自身のaction/id/classにcontact/inquiry/consultation等の語がある
     *   2. フォーム直前の見出し(h1〜h4)にcontact/inquiry等の語がある
     *   3. mail/email/message/subject等を示す入力欄name/placeholderを持つ
     *   4. いずれにも該当しない場合、入力項目数が最も多いフォームを代表とする
     *      (search/検索等、検索フォームらしい語を持つフォームは、他に候補が
     *      無い場合の最終フォールバックとしてのみ選ぶ ―― 旅行検索フォームを
     *      問い合わせフォームとして評価しないため)。
     *
     * 入力負担(tier)は代表フォームの入力項目数(total)・必須項目数(required)の
     * いずれかが閾値を超えたら段階を上げる。必須項目が0でも入力項目数自体が
     * 多ければ負担は大きいと判断する:
     *   small:  total<=5 かつ required<=5
     *   medium: total 6-10 または required 6-10
     *   large:  total>=11 または required>=11
     *
     * @return array{form_found: bool, form_count: int, page_total_field_count: int, required_field_count: ?int, total_field_count: ?int, tier: ?string, representative_form_reason: ?string}
     */
    private function analyzeFormBurden(\DOMXPath $xpath): array
    {
        $forms = $xpath->query('//form');

        if (($forms?->length ?? 0) === 0) {
            return [
                'form_found' => false, 'form_count' => 0, 'page_total_field_count' => 0,
                'required_field_count' => null, 'total_field_count' => null, 'tier' => null,
                'representative_form_reason' => null,
            ];
        }

        $lowerType = 'translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';
        $fieldQuery = ".//input[not({$lowerType}=\"hidden\") and not({$lowerType}=\"submit\") and not({$lowerType}=\"button\") and not({$lowerType}=\"image\")] | .//select | .//textarea";

        $candidates = [];
        $pageTotalFields = 0;

        foreach ($forms as $form) {
            $fields = $xpath->query($fieldQuery, $form);
            $total = $fields?->length ?? 0;
            $pageTotalFields += $total;
            $required = 0;

            $formAttrs = $form instanceof \DOMElement
                ? mb_strtolower($form->getAttribute('action').' '.$form->getAttribute('id').' '.$form->getAttribute('class'))
                : '';
            $isContactByAttrs = $this->containsAny($formAttrs, self::CONTACT_FORM_SIGNAL_KEYWORDS);
            $isSearchByAttrs = $this->containsAny($formAttrs, self::SEARCH_FORM_SIGNAL_KEYWORDS);
            $isContactByHeading = $this->containsAny(mb_strtolower($this->precedingHeadingText($xpath, $form)), self::CONTACT_FORM_SIGNAL_KEYWORDS);

            $isContactByField = false;
            foreach ($fields ?? [] as $field) {
                if (! $field instanceof \DOMElement) {
                    continue;
                }

                if ($field->hasAttribute('required') || strtolower($field->getAttribute('aria-required')) === 'true') {
                    $required++;
                }

                $type = strtolower($field->getAttribute('type'));
                $name = strtolower($field->getAttribute('name'));
                $placeholder = strtolower($field->getAttribute('placeholder'));
                if ($type === 'email' || $this->containsAny($name.' '.$placeholder, ['mail', 'contact', 'inquiry', 'message', 'subject'])) {
                    $isContactByField = true;
                }
            }

            $priority = match (true) {
                $isContactByAttrs => 1,
                $isContactByHeading => 2,
                $isContactByField => 3,
                $isSearchByAttrs => 5,
                default => 4,
            };

            $candidates[] = ['total' => $total, 'required' => $required, 'priority' => $priority];
        }

        usort($candidates, function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }

            return $b['total'] <=> $a['total'];
        });

        $chosen = $candidates[0];

        $tier = match (true) {
            $chosen['total'] >= 11 || $chosen['required'] >= 11 => 'large',
            $chosen['total'] >= 6 || $chosen['required'] >= 6 => 'medium',
            default => 'small',
        };

        $reason = match ($chosen['priority']) {
            1 => 'form_attributes',
            2 => 'nearby_heading',
            3 => 'field_names',
            5 => 'largest_search_form_fallback',
            default => 'largest_form_fallback',
        };

        return [
            'form_found' => true,
            'form_count' => count($candidates),
            'page_total_field_count' => $pageTotalFields,
            'required_field_count' => $chosen['required'],
            'total_field_count' => $chosen['total'],
            'tier' => $tier,
            'representative_form_reason' => $reason,
        ];
    }

    /**
     * フォーム直前に現れる見出し(h1〜h4)のテキストを取得する
     * (代表フォーム選定における「周辺見出し」シグナルに使う)。
     */
    private function precedingHeadingText(\DOMXPath $xpath, \DOMNode $form): string
    {
        $headings = $xpath->query('(preceding::h1|preceding::h2|preceding::h3|preceding::h4)[last()]', $form);

        if ($headings === false || $headings->length === 0) {
            return '';
        }

        return trim($headings->item(0)->textContent);
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

    /**
     * H1のテキストも、リンクテキストと同様に未評価のテンプレートプレースホルダー
     * (`${...}`等)が残っていないかsanitizeする。これは解析側の誤検出だけでなく、
     * 対象サイト自身のテンプレートがサーバーサイドで正しく評価されずに配信
     * されているケース(実在)にも対応するため ―― count(実際に存在するh1要素数)
     * は正しく保つが、表示用のtexts配列には採用しない。
     */
    private function analyzeH1(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//h1');
        $texts = [];
        foreach ($nodes ?? [] as $node) {
            $sanitized = $this->sanitizeCandidateText(trim($node->textContent));
            if ($sanitized !== null) {
                $texts[] = $sanitized;
            }
        }

        return [
            'count' => $nodes?->length ?? 0,
            'texts' => $texts,
            'primary_text' => $texts[0] ?? null,
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
            'contact_like' => $contactLike,
        ];
    }

    /**
     * SNSリンクをプラットフォーム別に検出する。判定は実際のa[href]の遷移先
     * ホスト名のみに基づき、本文中に「Instagram」等の語があるだけでは
     * 検出しない。protocol-relative URL(//instagram.com/...)はページと
     * 同じschemeとして解決し、サブドメイン(m.facebook.com等)も許容する。
     *
     * @return array{detected: bool, count: int, platforms: list<array{platform: string, url: string, text: ?string}>}
     */
    private function analyzeSnsLinks(\DOMXPath $xpath, string $pageUrl): array
    {
        $nodes = $xpath->query('//a[@href]');
        $pageScheme = (string) (parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https');
        $found = [];
        $seenPlatforms = [];

        foreach ($nodes ?? [] as $node) {
            $href = trim($node->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $resolvedHref = str_starts_with($href, '//') ? "{$pageScheme}:{$href}" : $href;
            $host = strtolower((string) parse_url($resolvedHref, PHP_URL_HOST));

            if ($host === '') {
                continue;
            }

            $platform = null;
            foreach (self::SNS_PLATFORMS as $platformKey => $hosts) {
                foreach ($hosts as $snsHost) {
                    if (str_ends_with($host, $snsHost)) {
                        $platform = $platformKey;
                        break 2;
                    }
                }
            }

            if ($platform === null || isset($seenPlatforms[$platform])) {
                continue; // 未知のホスト、または同一プラットフォームの2件目以降はスキップ(代表1件のみ記録)。
            }

            $text = $this->sanitizeCandidateText(trim($node->textContent)) ?? '';
            $ariaLabel = $node instanceof \DOMElement ? ($this->sanitizeCandidateText(trim($node->getAttribute('aria-label'))) ?? '') : '';
            $label = $text !== '' ? $text : ($ariaLabel !== '' ? $ariaLabel : ucfirst($platform));

            $seenPlatforms[$platform] = true;
            $found[] = ['platform' => $platform, 'url' => $href, 'text' => mb_substr($label, 0, 50)];
        }

        return [
            'detected' => $found !== [],
            'count' => count($found),
            'platforms' => array_values($found),
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
