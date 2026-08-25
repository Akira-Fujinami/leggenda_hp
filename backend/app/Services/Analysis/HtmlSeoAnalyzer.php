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

    /**
     * SNS判定における主要証拠(単独でplatform確定可)用のテキストキーワード。
     * aria-label/title/img alt/svg title/svg aria-labelに対して単語境界
     * (\b)付きで照合する。'x'は単独では曖昧すぎるため、href host
     * (x.com/twitter.com)・aria-label等の短いラベル文字列に対してのみ
     * 単語単位で使う(本文中の"x"のような偶然の一致は対象にしていない)。
     */
    private const SNS_TEXT_KEYWORDS = [
        'facebook' => ['facebook'],
        'instagram' => ['instagram'],
        'x' => ['twitter', 'x'],
        'line' => ['line'],
        'youtube' => ['youtube'],
        'tiktok' => ['tiktok'],
        'linkedin' => ['linkedin'],
        'pinterest' => ['pinterest'],
    ];

    /**
     * SNS判定における補助証拠(単独ではplatform確定に使わない。主要証拠と
     * 共起した場合のみconfidenceを加点する)用のclass名トークン。
     * 'x'は`class="x"`のような汎用ユーティリティクラスと衝突しやすいため
     * 意図的に含めない(Xの検出はhref host/aria-label/title/img alt等で行う)。
     */
    private const SNS_CLASS_TOKEN_PLATFORM = [
        'facebook' => 'facebook',
        'instagram' => 'instagram',
        'twitter' => 'x',
        'line' => 'line',
        'youtube' => 'youtube',
        'tiktok' => 'tiktok',
        'linkedin' => 'linkedin',
        'pinterest' => 'pinterest',
    ];

    private const CONTACT_KEYWORDS = ['contact', 'inquiry', 'お問い合わせ', 'お問合せ', '問い合わせ'];

    private const RESERVATION_KEYWORDS = ['reserve', 'reservation', 'booking', '予約'];

    private const DOCUMENT_REQUEST_KEYWORDS = ['資料請求', 'catalog', 'download', '資料ダウンロード'];

    private const PRICING_KEYWORDS = ['price', 'pricing', 'plan', 'fee', 'cost', '料金', '価格', 'プラン', '費用'];

    private const FAQ_KEYWORDS = ['faq', 'q&a', 'よくある質問'];

    /**
     * 「ヘルプ・サポート」系の導線をFAQと区別して検出するためのキーワード。
     * FAQ_KEYWORDSから分離した理由: Recommendation側で「チャット・ヘルプが
     * あれば緊急度を下げる」「FAQのみなら文言を弱めるに留める」という
     * 段階の異なる扱いをする必要があるため。
     */
    private const HELP_CENTER_KEYWORDS = ['ヘルプ', 'help', 'サポート', 'support', '使い方'];

    /**
     * チャットボット導入の目安となる既知ベンダーのスクリプトホスト。
     * 網羅的ではなく、確実にチャットウィジェットと分かるホストのみに限定する。
     */
    private const CHATBOT_SCRIPT_HOSTS = [
        'tawk.to', 'intercomcdn.com', 'zdassets.com', 'karte.io', 'chatplus.jp', 'crisp.chat', 'tidiochat.com',
    ];

    private const CHATBOT_ELEMENT_TOKENS = ['chatbot', 'chat-widget', 'livechat'];

    /**
     * 固定料金ページ(PRICING_KEYWORDS)とは別に、価格付き商品・プラン・
     * 予約カードの検出に用いる語。旅行・EC系サイトのように「固定の料金
     * ページ」は無くても、商品カード上に価格表示がある業態を正しく評価
     * するためのもの。
     */
    private const PRODUCT_PRICE_CARD_KEYWORDS = [
        '料金', '価格', '宿泊料金', 'プラン', '宿泊プラン', 'ツアー', 'クーポン', 'キャンペーン', '最安',
        'price', 'pricing', 'plan', 'package', 'deal', 'coupon',
    ];

    /**
     * H1の代表値として採用しない広告見出しマーカー。文中一致(部分一致)は
     * 「Advertisement industry trends」のような正当な見出しを誤って除外して
     * しまうため、括弧を除いた全文一致でのみ判定する。
     */
    private const H1_AD_MARKER_KEYWORDS = ['PR', '広告', 'AD', 'Sponsored', 'Advertisement'];

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
     * recruitカテゴリ専用の優先選定シグナル(2026-08-01, 欠陥①の修正)。
     * 「最初に一致したリンクを代表とする」方式は、"採用強化"(採用支援サービスの
     * 紹介ページ)のような紛らわしい文言が本来の採用ページより先にナビへ
     * 現れるサイトで、誤って別ページを採用ページとして選んでしまう
     * (実データ: レジェンダ自身のグローバルナビで再現・確認済み)。
     * 除外キーワードのリストは作らない ―― 企業ごとの言い回し(「採用強化」
     * 「採用実績」「採用担当者様へ」等)に個別対応するのは終わりが無く脆いため、
     * 代わりに「採用ページそのものである確度が高いシグナル」への一致数で
     * 優先順位を付ける方式にする。他6カテゴリ(pricing/faq/help_center/
     * case_study/company_info/privacy_policy)の判定・挙動はここでは一切
     * 変更しない。
     */
    private const RECRUIT_SUBDOMAIN_PREFIXES = [
        'careers.', 'recruit.', 'recruiting.', 'saiyo.', 'job.', 'jobs.', 'career.',
    ];

    private const RECRUIT_EXACT_PATH_SEGMENTS = [
        'recruit', 'recruits', 'careers', 'career', 'recruiting', 'saiyo', 'jobs', 'job',
    ];

    /**
     * 2026-08-18の指摘・実データ再現(ゴディバ採用サイト)。RECRUIT_SUBDOMAIN_
     * PREFIXESはサブドメインの前方一致のみを見るため、"godiva-saiyo.com"の
     * ようにセカンドレベルドメイン自体がハイフン区切りで採用ワードを含む
     * 「会社名-saiyo.com」「会社名-recruit.jp」形式の採用専用ドメインを
     * 拾えない。ホスト名の各ラベルを"-"/"_"で分割したトークン単位の完全一致
     * (部分文字列一致ではない)で判定する ―― ハイフン無しで連結された社名
     * (例: "bigjobs.co.jp")は分割されず誤検出しない。
     *
     * job/jobsは意図的に含めない。RECRUIT_EXACT_PATH_SEGMENTSと違い、この
     * 一致はページ全体を採用ページと確定させ、以降ナビから別候補を探しに
     * 行かなくなる強い判定のため、無関係なブランド名との衝突可能性がある
     * 汎用英単語(job/jobs)は対象から外し、より確度の高い語のみを使う。
     *
     * @var list<string>
     */
    private const RECRUIT_HOST_LABEL_KEYWORDS = [
        // saiyou(長音あり表記)は2026-08-24追加。saiyo(長音無し)のみでは
        // 「-saiyou.jp」のようなドメインを拾えなかった(優先度4-1)。
        'recruit', 'recruits', 'careers', 'career', 'recruiting', 'saiyo', 'saiyou',
    ];

    /**
     * 正規化(前後空白除去・連続空白の単一化・全角半角統一・小文字化)後に
     * 完全一致で照合するラベル。部分一致(例:「採用強化」)はここに含めない。
     */
    private const RECRUIT_EXACT_LABELS = [
        '採用情報', '採用サイト', '採用ページ', '採用', '採用・求人', '求人情報', '求人',
        'recruit', 'recruits', 'recruiting', 'careers', 'career', 'join us', 'work with us',
    ];

    /**
     * extractNavigationLinkLabels()の1件あたりの文字数上限/全体件数上限。
     */
    private const NAV_LINK_LABEL_MAX_LENGTH = 64;

    private const NAV_LINK_LABEL_MAX_COUNT = 50;

    /**
     * extractAllLinkTexts()の全体件数上限。ページ全体を対象にするため
     * extractNavigationLinkLabels()より広く取る(label_only_evidence判定用の
     * 集合であり、表示や個別のラベル抽出には使わないため、多めでも実害がない)。
     */
    private const ALL_LINK_TEXT_MAX_COUNT = 300;

    /**
     * extractNavigationLinkLabels()で「内容を持たないラベル」として除外する
     * 語(小文字化して比較する)。ページネーション・続きを読む系の定型文言のみを
     * 対象とし、実際の事業・商品名を誤って除外しないよう最小限にとどめる。
     *
     * @var list<string>
     */
    private const NAV_LINK_NO_CONTENT_LABELS = [
        '詳しくはこちら', 'もっと見る', '一覧', '一覧を見る', '次へ', '前へ',
        'more', 'read more', 'learn more', 'see more', 'top', 'home', 'next', 'prev', 'previous',
    ];

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

        // チャットボット埋め込みスクリプトのホスト判定は、script要素そのものが
        // 除去される前(stripNonContentElements実行前)に行う必要がある。
        $chatbotScriptMatch = $this->matchChatbotScriptHost($xpath);

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
            'business_links' => $this->analyzeBusinessLinks($xpath, $pageHost, $pageUrl),
            'chatbot' => $this->analyzeChatbotWidget($xpath, $chatbotScriptMatch),
            'product_price_cards' => $this->analyzeProductPriceCards($xpath),
            'third_party_reservation' => $this->analyzeThirdPartyReservationService($xpath),
            'content' => $this->analyzeContent($dom, $xpath),
            'forms' => $this->analyzeForms($xpath),
            'form_burden' => $this->analyzeFormBurden($xpath),
            'accessibility' => $this->analyzeAccessibilityHeuristics($xpath),
        ];
    }

    /**
     * ブロックレベル要素の境界とみなし、テキスト化の際に改行を挿入するタグ名。
     * DOMNode::textContentは要素境界に何の区切りも挿入せず子孫テキストノードを
     * 単純連結するため、これを使わずに直接textContentを取ると、見出しと直後の
     * 段落が地続きになり、サイト上に実在しない文字列が生成されてしまう
     * (例: <h2>挑戦</h2><p>する人材を求めています</p> →
     * 「挑戦する人材を求めています」という幻の文字列)。これはPhase 4の
     * evidence検証(元テキストへの部分一致)を無効化するため、ブロック境界を
     * 明示的に改行として保持する。
     *
     * @var list<string>
     */
    private const TEXT_BLOCK_BOUNDARY_TAGS = [
        'p', 'div', 'section', 'article', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'td', 'tr', 'dt', 'dd', 'blockquote', 'header', 'footer', 'nav', 'main', 'aside', 'figcaption',
    ];

    /**
     * extractBodyText($excludeNavigation: true)で除去するナビゲーション系
     * 要素のXPath式。role="navigation"は大小文字を無視して比較する
     * (他の属性判定箇所と同じtranslate()方式)。
     */
    private const NAVIGATION_ELEMENTS_XPATH =
        '//nav | //header | //footer | //aside | //*[translate(@role, "NAVIGATION", "navigation")="navigation"]';

    /**
     * ページ本文のプレーンテキストを返す(script/style除去後、空白正規化済み)。
     * ブロックレベル要素の境界は改行として保持する(段落単位で改行区切りに
     * 並ぶ)。Phase 4(ブランド・ホイール6軸分析)がAIへ渡す入力を組み立てる
     * ために追加した公開メソッド。analyze()の内部で同じ値を計算しているが
     * 返却していないため(analyzeContent()はbody_length/word_countなどの
     * 派生値しか返さない)、analyze()自体は一切変更せず、同じDOM読み込み手順を
     * 独立して行う新規メソッドとして追加する(既存の呼び出し元・テストへの
     * 影響をゼロにするため ―― extractBodyText()自体の呼び出し元は
     * BrandWheelAnalysisInputFactoryのみで、既存の指標算出は一切参照しない)。
     *
     * $excludeNavigation=true(2026-08-04、ブランド・ホイール専用): nav/header/
     * footer/aside(role="navigation"の要素も含む)を除いた本文を返す。
     * 実データ(website_analysis_id=57)で、本文の大半がグローバルナビ・
     * セクション見出しの繰り返し・「READ MORE」のようなナビゲーション文言に
     * 占められ、AIへ渡す実質的な情報量を圧迫していたことへの対応。
     * 専用メソッドではなく引数にした理由: 専用メソッドとして複製すると、
     * DOM読み込み・ブロック境界処理・空白正規化の3ステップがそのまま重複し、
     * 将来どちらかだけを修正して食い違う実装差の温床になる。この引数を
     * 追加しても、呼び出し元が明示的にtrueを渡さない限り既定値falseで
     * 従来と完全に同じ挙動のままのため、既存呼び出し元への影響はゼロ
     * (extractBodyText()の呼び出し元は現状BrandWheelAnalysisInputFactoryのみ)。
     */
    public function extractBodyText(string $html, bool $excludeNavigation = false): string
    {
        [, $xpath] = $this->loadDomForTextExtraction($html);

        if ($excludeNavigation) {
            $this->stripNavigationElements($xpath);
        }

        $bodyNodes = $xpath->query('//body');
        $rawText = ($bodyNodes?->length ?? 0) > 0 ? $this->textWithBlockBoundaries($bodyNodes->item(0)) : '';

        return $this->normalizeBlockText($rawText);
    }

    /**
     * nav/header/footer/aside(role="navigation"の要素も含む)の部分木を
     * DOMから除去する。stripNonContentElements()(script/style等)とは目的が
     * 異なる別メソッドとして分離する ―― こちらはextractBodyText()の
     * $excludeNavigation=true時のみ呼ばれ、他の全メソッド(analyze()経由の
     * 指標算出、extractHeadingTexts()、extractNavigationLinkLabels()自身)には
     * 一切影響しない。
     */
    private function stripNavigationElements(\DOMXPath $xpath): void
    {
        $toRemove = [];

        foreach ($xpath->query(self::NAVIGATION_ELEMENTS_XPATH) ?? [] as $node) {
            $toRemove[] = $node;
        }

        foreach ($toRemove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * ブロック境界タグの前後に改行を挿入しながら、テキストノードを文書順に連結する。
     */
    private function textWithBlockBoundaries(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return $node->wholeText;
        }

        if (! $node instanceof \DOMElement) {
            return '';
        }

        $tagName = strtolower($node->nodeName);

        if ($tagName === 'br') {
            return "\n";
        }

        $isBlockBoundary = in_array($tagName, self::TEXT_BLOCK_BOUNDARY_TAGS, true);

        $parts = [];
        if ($isBlockBoundary) {
            $parts[] = "\n";
        }
        foreach ($node->childNodes as $child) {
            $parts[] = $this->textWithBlockBoundaries($child);
        }
        if ($isBlockBoundary) {
            $parts[] = "\n";
        }

        return implode('', $parts);
    }

    /**
     * 改行以外の空白(半角/全角スペース・タブ)は単一のスペースに畳み、
     * 改行そのものは潰さずに残す。連続改行は1つに畳み、行頭/行末の
     * 空白と空行は除去する。
     */
    private function normalizeBlockText(string $text): string
    {
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? '';
        $text = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $text) ?? '';
        $text = preg_replace('/\n{2,}/u', "\n", $text) ?? '';

        return trim($text, " \n");
    }

    /**
     * H1〜H3の見出しテキストを文書順に返す。Phase 4の6軸分析入力用。
     *
     * @return list<array{level: int, text: string}>
     */
    public function extractHeadingTexts(string $html): array
    {
        [, $xpath] = $this->loadDomForTextExtraction($html);

        $nodes = $xpath->query('//h1 | //h2 | //h3');
        $headings = [];

        foreach ($nodes ?? [] as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

            if ($text === '') {
                continue;
            }

            $headings[] = ['level' => (int) substr($node->nodeName, 1), 'text' => $text];
        }

        return $headings;
    }

    /**
     * 「その会社が何を事業として展開しているか」を読み取るための材料として、
     * グローバルナビゲーション(header/nav配下)とフッターナビゲーションの
     * リンクテキストを文書順に返す。ブランド・ホイールの活動的魅力
     * (下位要素「展開事業・商品」)専用の抽出であり、analyzeBusinessLinks()
     * (料金/FAQ/会社概要等、信頼性評価用の個別ページ検出)とは目的も
     * 対象ノードも異なるため独立させている ―― analyzeBusinessLinks()には
     * 一切手を入れない。
     *
     * 正規化: 前後・連続空白の単一化、1件64文字で切り詰め、正規化後の
     * 文字列で重複除去、文書順で最大50件。空文字・記号のみ・
     * 「詳しくはこちら」等の内容を持たないラベルは除外する。URLは含めない。
     *
     * @return list<string>
     */
    public function extractNavigationLinkLabels(string $html): array
    {
        [, $xpath] = $this->loadDomForTextExtraction($html);

        $nodes = $xpath->query('//header//a | //nav//a | //footer//a');

        $labels = [];
        foreach ($nodes ?? [] as $node) {
            if (count($labels) >= self::NAV_LINK_LABEL_MAX_COUNT) {
                break;
            }

            $label = $this->normalizeNavLinkLabel($node->textContent);
            if ($label === null || in_array($label, $labels, true)) {
                continue;
            }

            $labels[] = $label;
        }

        return $labels;
    }

    private function normalizeNavLinkLabel(string $rawText): ?string
    {
        $text = $this->sanitizeCandidateText(trim($rawText)) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '' || ! preg_match('/[\p{L}\p{N}]/u', $text)) {
            return null;
        }

        if (in_array(mb_strtolower($text), self::NAV_LINK_NO_CONTENT_LABELS, true)) {
            return null;
        }

        return mb_substr($text, 0, self::NAV_LINK_LABEL_MAX_LENGTH);
    }

    /**
     * 2026-08-05: ブランド・ホイールのlabel_only_evidence判定専用。
     * extractNavigationLinkLabels()は`<header>`/`<nav>`/`<footer>`という
     * セマンティックタグの内側しか見ないため、`<div class="gnav_wrap">`の
     * ようにクラス名だけでナビゲーションを実装している実サイト(味の素の
     * 実測で確認)のリンクラベルを取りこぼす。ここではタグの意味に頼らず、
     * ページ内の全`<a>`タグのテキストをスコープ制限なしで集める ――
     * 「これはナビゲーションか」を判定するためではなく、「この文字列は
     * どこかのリンクのラベルと同じか」を判定するための集合であり、
     * 用途が異なるため既存のextractNavigationLinkLabels()/businessLinkLabels
     * (グローバルナビの表示・展開事業抽出用、既存の呼び出し元・件数上限は
     * 変更しない)とは完全に独立した戻り値として返す。
     *
     * @return list<string>
     */
    public function extractAllLinkTexts(string $html): array
    {
        [, $xpath] = $this->loadDomForTextExtraction($html);

        $nodes = $xpath->query('//a');

        $labels = [];
        foreach ($nodes ?? [] as $node) {
            if (count($labels) >= self::ALL_LINK_TEXT_MAX_COUNT) {
                break;
            }

            $label = $this->normalizeNavLinkLabel($node->textContent);
            if ($label === null || in_array($label, $labels, true)) {
                continue;
            }

            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * extractBodyText()/extractHeadingTexts()専用のDOM読み込み。analyze()と
     * 同じ手順(UTF-8強制・XXE対策・script/style等の除去)を独立して行う
     * ―― analyze()自体の実装には一切触れない。
     *
     * @return array{0: \DOMDocument, 1: \DOMXPath}
     */
    private function loadDomForTextExtraction(string $html): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?>'.$html,
            LIBXML_NOENT | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $this->stripNonContentElements($xpath);

        return [$dom, $xpath];
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
     * 依頼E-2(2026-08-25): 与えられたURLが採用ページであるかどうかを、
     * analyzeBusinessLinks()内で使っていた判定(RECRUIT_EXACT_PATH_SEGMENTS/
     * RECRUIT_SUBDOMAIN_PREFIXES/hostHasRecruitLabelToken()の組み合わせ)と
     * 完全に同じロジックで判定する。元々analyzeBusinessLinks()に private に
     * インライン展開されていたものをpublicメソッドへ切り出しただけで、
     * 判定内容・定数の中身は一切変更していない(BrandWheelAnalysisInputFactoryが
     * クロールページを採用クラスタ/トップページクラスタへ分類する用途で再利用する)。
     */
    public function isRecruitPageUrl(string $pageUrl): bool
    {
        $pageHost = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));

        return $this->segmentsMatchAny($this->pathSegments($pageUrl), self::RECRUIT_EXACT_PATH_SEGMENTS)
            || $this->startsWithAny($pageHost, self::RECRUIT_SUBDOMAIN_PREFIXES)
            || $this->hostHasRecruitLabelToken($pageHost);
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
    private function analyzeBusinessLinks(\DOMXPath $xpath, string $pageHost, string $pageUrl): array
    {
        $categories = [
            'pricing' => self::PRICING_KEYWORDS,
            'faq' => self::FAQ_KEYWORDS,
            'help_center' => self::HELP_CENTER_KEYWORDS,
            'case_study' => self::CASE_STUDY_KEYWORDS,
            'company_info' => self::COMPANY_INFO_KEYWORDS,
            'privacy_policy' => self::PRIVACY_POLICY_KEYWORDS,
            'recruit' => self::RECRUIT_KEYWORDS,
        ];

        // 2026-08-05: 今スキャンしているページ自身のURLが既に採用ページを
        // 指している場合(診断対象として渡されたURLが/recruit/や/saiyo_career
        // のように、それ自体で採用ページである場合)、ページ内のナビゲーション
        // リンクから「別の採用ページ」を探しに行かない。探しに行くと、
        // 自分自身への自己参照リンクを拾って無駄な二重取得になる(実データ:
        // カヤック採用ページで再現)か、同じく採用ページらしい別セクションへの
        // リンク(例: 障がい者採用ページ)をより優先シグナル数が高いという理由で
        // 誤って採用ページとして選んでしまう(実データ: 味の素で再現)。
        $pageIsRecruitPage = $this->isRecruitPageUrl($pageUrl);

        $nodes = $xpath->query('//a[@href]');
        $detected = array_fill_keys(array_keys($categories), null);
        $recruitCandidates = [];

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
                $hrefMatch = $this->segmentsMatchAny($hrefPathSegments, $keywords);
                $textMatch = $this->containsAny($textLower, $keywords) || $this->containsAny($labelLower, $keywords);

                if (! $hrefMatch && ! $textMatch) {
                    continue;
                }

                $confidence = $hrefMatch && $textMatch ? 0.95 : ($textMatch ? 0.75 : 0.65);
                $representativeText = $text !== '' ? $text : ($ariaLabel !== '' ? $ariaLabel : $title);
                $representativeText = $representativeText !== '' ? mb_substr($representativeText, 0, 100) : null;
                $linkType = $this->classifyLinkType($href, $pageHost);

                // recruitだけは「最初の一致」を即採用せず、全候補を集めて
                // 優先順位付けする(下記signalCountによる選定)。他カテゴリは
                // 従来どおり最初に見つかったリンクを代表として採用する。
                // ページ自身が既に採用ページの場合は候補収集自体を行わない
                // (上記$pageIsRecruitPage参照)。
                if ($category === 'recruit') {
                    if ($pageIsRecruitPage) {
                        continue;
                    }

                    $recruitCandidates[] = [
                        'url' => $href,
                        'text' => $representativeText,
                        'confidence' => $confidence,
                        'link_type' => $linkType,
                        'signal_count' => $this->countRecruitSignals($href, $hrefPathSegments, $text, $ariaLabel, $title, $pageHost),
                    ];

                    continue;
                }

                if ($detected[$category] === null) {
                    $detected[$category] = [
                        'url' => $href,
                        'text' => $representativeText,
                        'confidence' => $confidence,
                        'link_type' => $linkType,
                    ];
                }
            }
        }

        // ページ自身が既に採用ページの場合、探索結果に関わらず自己参照
        // (このページ自身のURL)を採用ページとして確定させる。resolveRecruitUrl()
        // (AnalysisPipeline.php)がこのURLをそのまま解決するため、
        // FetchRecruitPageJob::process()は同一URLへの2回目のHTTP取得を
        // 行わず、既に取得済みのトップページのAnalysisPage行を複製する
        // (優先度4-3、2026-08-24対応 ―― 以前は無駄な二重取得を許容していた)。
        $detected['recruit'] = $pageIsRecruitPage
            ? ['url' => $pageUrl, 'text' => null, 'confidence' => 1.0, 'link_type' => $this->classifyLinkType($pageUrl, $pageHost)]
            : $this->selectRecruitCandidate($recruitCandidates);

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
     * recruitカテゴリの候補が満たす優先シグナル(1〜3)の数を数える。
     *
     * @param  list<string>  $hrefPathSegments
     */
    private function countRecruitSignals(
        string $href,
        array $hrefPathSegments,
        string $text,
        string $ariaLabel,
        string $title,
        string $pageHost,
    ): int {
        $count = 0;

        // シグナル1: ホストが採用サイト用のサブドメイン(相対URLはhrefHost=''
        // となり該当しない)。
        $host = $this->hrefHost($href);
        if ($host !== '' && $this->startsWithAny($host, self::RECRUIT_SUBDOMAIN_PREFIXES)) {
            $count++;
        }

        // シグナル2: パスセグメントの完全一致(前方・後方一致は対象外。
        // "recruitment_support"のような紛らわしいセグメントを除外するため)。
        foreach ($hrefPathSegments as $segment) {
            if (in_array($segment, self::RECRUIT_EXACT_PATH_SEGMENTS, true)) {
                $count++;
                break;
            }
        }

        // シグナル3: リンクテキスト/aria-label/titleのいずれかが、正規化後に
        // 正規のラベルと完全一致する(部分一致は対象外)。
        foreach ([$text, $ariaLabel, $title] as $candidateText) {
            if ($candidateText === '') {
                continue;
            }

            if (in_array($this->normalizeForExactMatch($candidateText), self::RECRUIT_EXACT_LABELS, true)) {
                $count++;
                break;
            }
        }

        return $count;
    }

    /**
     * @param  list<array{url: string, text: ?string, confidence: float, link_type: string, signal_count: int}>  $candidates
     * @return array{url: string, text: ?string, confidence: float, link_type: string}|null
     */
    private function selectRecruitCandidate(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        foreach ([2, 1] as $minSignals) {
            foreach ($candidates as $candidate) {
                if ($candidate['signal_count'] >= $minSignals) {
                    return [
                        'url' => $candidate['url'],
                        'text' => $candidate['text'],
                        'confidence' => $minSignals === 2 ? 0.95 : 0.85,
                        'link_type' => $candidate['link_type'],
                    ];
                }
            }
        }

        // どのシグナルにも該当する候補が無い場合は、既存の部分一致方式による
        // 最初の候補をそのまま採用する(フォールバック。confidenceは元の算出値を
        // 維持し、「採用」しか手がかりの無いサイトで検出不能にならないようにする)。
        $first = $candidates[0];

        return [
            'url' => $first['url'],
            'text' => $first['text'],
            'confidence' => $first['confidence'],
            'link_type' => $first['link_type'],
        ];
    }

    private function hrefHost(string $href): string
    {
        return strtolower((string) parse_url($href, PHP_URL_HOST));
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function startsWithAny(string $haystack, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($haystack, mb_strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * ホスト名を"."でラベルに分割し、さらに各ラベルを"-"/"_"でトークンに
     * 分割したうえで、RECRUIT_HOST_LABEL_KEYWORDSとの完全一致を見る。
     * サブドメイン・TLD部分を区別せず全ラベルを対象にする ―― "com"/"co"/
     * "jp"/"www"のような短い一般語はキーワードと衝突しないため、除外リストを
     * 別途保守する必要はない。
     *
     * 【既知の誤検出リスク(優先度4-2、2026-08-24文書化、未対応)】
     * 社名・ブランド名自体が"recruit"を含む実在企業(例: 株式会社リクルート、
     * ドメインが"recruit.co.jp"や"recruit-xxx.jp"等)の場合、その企業の
     * 通常のコーポレートサイト(採用ページではない任意のページ)を診断対象と
     * すると、この関数はホスト名だけを見て`true`を返す。呼び出し元
     * (656-666行目の$pageIsRecruitPage)はこれを「診断中のページ自身が既に
     * 採用ページである」との判定に使うため、本来ナビゲーションから探すべき
     * 本物の採用ページを探しに行かず、コーポレートサイトのトップページ自体を
     * 採用ページとして扱ってしまう可能性がある。ブランド名がたまたま
     * RECRUIT_HOST_LABEL_KEYWORDSと衝突するケースはこの関数の設計上
     * 避けられない(サブドメイン・第二レベルドメインに"-saiyo"/"-recruit"を
     * 付ける採用専用ドメイン(ゴディバ型)を検出するための意図的な設計であり、
     * 社名自体との衝突を除外する一般的な方法が無いため)。対応は未着手
     * (HtmlSeoAnalyzerTest::test_it_treats_a_company_whose_own_brand_name_
     * contains_recruit_as_a_self_reference_known_limitation()に現在の挙動を
     * 固定するテストがある)。
     */
    private function hostHasRecruitLabelToken(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            foreach (preg_split('/[-_]/', $label) ?: [] as $token) {
                if (in_array($token, self::RECRUIT_HOST_LABEL_KEYWORDS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 完全一致照合用の正規化: 前後空白除去、連続空白の単一化、全角英数字・
     * 全角スペースの半角統一、小文字化。
     */
    private function normalizeForExactMatch(string $text): string
    {
        $normalized = mb_convert_kana($text, 'as');
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? '';

        return mb_strtolower($normalized);
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
     * H1を「有効な代表見出し候補」と「非表示・広告見出し・記号のみ等の
     * 除外対象」に分けて集計する。count(実際に存在するh1要素数)は常に
     * 正しく保ち、有効件数(valid_count)が0でもcountが0とは限らないという
     * 矛盾([count=3なのにH1なし]のような表示)を起こさないことが目的。
     *
     * 除外理由(excluded_reason)は以下の4種類のみ:
     *   - empty / template_placeholder: 空文字、または未評価のテンプレート
     *     プレースホルダー(sanitizeCandidateText参照)
     *   - hidden: hidden属性、aria-hidden="true"、style属性内の
     *     display:none/visibility:hiddenのいずれか(isStaticallyVisible参照)
     *   - ad_marker: 【PR】等の広告見出しマーカーとの全文一致
     *   - symbol_only: 記号・空白のみで構成される文字列
     * 文字数による除外は行わない(短いブランド名・サービス名を誤って
     * 無効化しないため)。
     *
     * 可視性判定は静的DOM解析の限界として、hidden属性・aria-hidden・
     * インラインstyleのみを見る。CSSクラス経由や外部スタイルシートによる
     * 非表示は(静的HTML/レンダリング後HTMLの文字列からは)判定できない。
     *
     * @return array{count: int, entries: list<array{text: string, visible: bool, valid: bool, excluded_reason: ?string}>, visible_count: int, valid_count: int, primary_text: ?string}
     */
    private function analyzeH1(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//h1');
        $entries = [];
        $visibleCount = 0;
        $validCount = 0;
        $primaryText = null;

        foreach ($nodes ?? [] as $node) {
            $rawText = trim($node->textContent);
            $visible = $node instanceof \DOMElement ? $this->isStaticallyVisible($node) : true;

            if ($visible) {
                $visibleCount++;
            }

            $sanitized = $this->sanitizeCandidateText($rawText);
            $valid = true;
            $excludedReason = null;

            if ($sanitized === null) {
                $valid = false;
                $excludedReason = $rawText === '' ? 'empty' : 'template_placeholder';
            } elseif (! $visible) {
                $valid = false;
                $excludedReason = 'hidden';
            } elseif ($this->isAdMarkerText($sanitized)) {
                $valid = false;
                $excludedReason = 'ad_marker';
            } elseif ($this->isSymbolOnlyText($sanitized)) {
                $valid = false;
                $excludedReason = 'symbol_only';
            }

            $displayText = $sanitized ?? $rawText;

            $entries[] = [
                'text' => $displayText,
                'visible' => $visible,
                'valid' => $valid,
                'excluded_reason' => $excludedReason,
            ];

            if ($valid) {
                $validCount++;
                if ($primaryText === null) {
                    $primaryText = $displayText;
                }
            }
        }

        return [
            'count' => $nodes?->length ?? 0,
            'entries' => $entries,
            'visible_count' => $visibleCount,
            'valid_count' => $validCount,
            'primary_text' => $primaryText,
        ];
    }

    /**
     * hidden属性・aria-hidden="true"・インラインstyleのdisplay:none/
     * visibility:hiddenのみを見る静的DOM解析ベースの可視性判定。CSSクラスや
     * 外部スタイルシートによる非表示は判定できない(静的HTML文字列からは
     * 実際の計算済みスタイルにアクセスできないため)。
     */
    private function isStaticallyVisible(\DOMElement $node): bool
    {
        if ($node->hasAttribute('hidden')) {
            return false;
        }

        if (mb_strtolower(trim($node->getAttribute('aria-hidden'))) === 'true') {
            return false;
        }

        $style = mb_strtolower($node->getAttribute('style'));
        if ($style !== '' && preg_match('/display\s*:\s*none|visibility\s*:\s*hidden/', $style)) {
            return false;
        }

        return true;
    }

    /**
     * 括弧([]/【】/())を除いた全文が広告見出しマーカーと一致する場合のみ
     * 広告見出しとみなす。部分一致(文中一致)は正当な見出しの誤除外に
     * つながるため行わない。
     */
    private function isAdMarkerText(string $text): bool
    {
        $normalized = trim((string) preg_replace('/^[\【\[\(]+|[\】\]\)]+$/u', '', $text));

        foreach (self::H1_AD_MARKER_KEYWORDS as $marker) {
            if (mb_strtolower($normalized) === mb_strtolower($marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 記号・空白のみで構成される文字列かどうか(文字数では判定しない)。
     */
    private function isSymbolOnlyText(string $text): bool
    {
        return (bool) preg_match('/^[\s\p{P}\p{S}]+$/u', $text);
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

    /**
     * alt属性を5つのバケットに分離して集計する(一律に「装飾画像」として
     * 除外・適切扱いしない)。
     *   - missing_alt: alt属性そのものが無い(明確な違反候補)
     *   - empty_alt: alt=""(「装飾画像候補」。role=presentation/aria-hidden
     *     のように装飾と確定はできないため、分母には残し分子には含めない
     *     ―― 完全に適切とは断定しない)
     *   - decorative_count: role="presentation"またはaria-hidden="true"
     *     (装飾と確定できるため分母・分子から除外する)
     *   - with_alt: 有効なaltテキストあり(分子にカウント)
     * alt_coverage = with_alt / (total - decorative_count)。empty_altは
     * 分母には含まれるが分子には含まれない(現状同様スコアには反映されるが、
     * raw_value上はmissing_altと明確に区別して報告する)。
     */
    private function analyzeImages(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//img');
        $total = $nodes?->length ?? 0;
        $withAlt = 0;
        $emptyAlt = 0;
        $missingAlt = 0;
        $decorativeCount = 0;

        foreach ($nodes ?? [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $role = mb_strtolower(trim($node->getAttribute('role')));
            $ariaHidden = mb_strtolower(trim($node->getAttribute('aria-hidden')));
            if ($role === 'presentation' || $ariaHidden === 'true') {
                $decorativeCount++;

                continue;
            }

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

        $scoredDenominator = $total - $decorativeCount;

        return [
            'total' => $total,
            'with_alt' => $withAlt,
            'empty_alt' => $emptyAlt,
            'missing_alt' => $missingAlt,
            'decorative_count' => $decorativeCount,
            'alt_coverage' => $scoredDenominator > 0 ? round($withAlt / $scoredDenominator, 4) : null,
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
     * SNSリンクをプラットフォーム別に検出する。判定はクリック可能な
     * a[href]要素のみを起点とし、本文中に「Instagram」等の語があるだけでは
     * 検出しない(この安全策は変更しない)。
     *
     * href host一致だけでは検出漏れが生じる(例: 短縮URL・独自ドメイン経由の
     * リダイレクト、テキストのみでhrefが直接SNSドメインを指さないアイコン
     * リンク等)ため、a要素ごとに複数のシグナルを「主要証拠(単独でplatform
     * 確定可)」と「補助証拠(単独では確定不可、主要証拠と共起した場合のみ
     * confidenceを加点)」に分けて評価する。class名のような短い汎用トークン
     * (例: class="x")は誤検知しやすいため補助証拠に留める。
     *
     * @return array{detected: bool, count: int, platforms: list<array{platform: string, url: string, label: string, source: string, confidence: float}>}
     */
    private function analyzeSnsLinks(\DOMXPath $xpath, string $pageUrl): array
    {
        $nodes = $xpath->query('//a[@href]');
        $pageScheme = (string) (parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https');
        $found = [];
        $seenPlatforms = [];

        foreach ($nodes ?? [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $resolvedHref = str_starts_with($href, '//') ? "{$pageScheme}:{$href}" : $href;
            $host = strtolower((string) parse_url($resolvedHref, PHP_URL_HOST));

            $text = $this->sanitizeCandidateText(trim($node->textContent)) ?? '';
            $ariaLabel = $this->sanitizeCandidateText(trim($node->getAttribute('aria-label'))) ?? '';
            $title = $this->sanitizeCandidateText(trim($node->getAttribute('title'))) ?? '';
            $imgAlt = $this->descendantAttributeText($xpath, $node, './/img', 'alt');
            $svgTitle = $this->descendantText($xpath, $node, './/svg/title');
            $svgAriaLabel = $this->descendantAttributeText($xpath, $node, './/svg', 'aria-label');

            $primary = null;

            if ($host !== '' && ($platform = $this->matchSnsHost($host)) !== null) {
                $primary = ['platform' => $platform, 'source' => 'href_host', 'confidence' => 0.95];
            }

            if ($primary === null && ($platform = $this->matchSnsQueryParamHost($href)) !== null) {
                $primary = ['platform' => $platform, 'source' => 'href_query_param', 'confidence' => 0.80];
            }

            if ($primary === null && $ariaLabel !== '' && ($platform = $this->matchSnsKeyword($ariaLabel)) !== null) {
                $primary = ['platform' => $platform, 'source' => 'aria_label', 'confidence' => 0.70];
            }

            if ($primary === null && $title !== '' && ($platform = $this->matchSnsKeyword($title)) !== null) {
                $primary = ['platform' => $platform, 'source' => 'title', 'confidence' => 0.70];
            }

            if ($primary === null && $svgTitle !== '' && ($platform = $this->matchSnsKeyword($svgTitle)) !== null) {
                $primary = ['platform' => $platform, 'source' => 'svg_title', 'confidence' => 0.65];
            }

            if ($primary === null && $svgAriaLabel !== '' && ($platform = $this->matchSnsKeyword($svgAriaLabel)) !== null) {
                $primary = ['platform' => $platform, 'source' => 'svg_aria_label', 'confidence' => 0.65];
            }

            if ($primary === null && $imgAlt !== '' && ($platform = $this->matchSnsKeyword($imgAlt)) !== null) {
                $primary = ['platform' => $platform, 'source' => 'img_alt', 'confidence' => 0.60];
            }

            if ($primary === null) {
                continue; // 補助証拠(class名・data属性)単独ではplatformを確定しない。
            }

            if (isset($seenPlatforms[$primary['platform']])) {
                continue; // 同一プラットフォームの2件目以降はスキップ(代表1件のみ記録)。
            }

            // 補助証拠は、主要証拠が既に成立している同一platformの場合のみconfidenceを加点する。
            if ($this->matchSnsClassToken($node->getAttribute('class'), $primary['platform'])
                || $this->matchSnsKeyword($this->dataAttributeValuesText($node)) === $primary['platform']) {
                $primary['confidence'] = min(1.0, $primary['confidence'] + 0.03);
            }

            $label = $text !== '' ? $text : ($ariaLabel !== '' ? $ariaLabel : ucfirst($primary['platform']));

            $seenPlatforms[$primary['platform']] = true;
            $found[] = [
                'platform' => $primary['platform'],
                'url' => $href,
                'label' => mb_substr($label, 0, 50),
                'source' => $primary['source'],
                'confidence' => $primary['confidence'],
            ];
        }

        return [
            'detected' => $found !== [],
            'count' => count($found),
            'platforms' => array_values($found),
        ];
    }

    private function matchSnsHost(string $host): ?string
    {
        foreach (self::SNS_PLATFORMS as $platformKey => $hosts) {
            foreach ($hosts as $snsHost) {
                if (str_ends_with($host, $snsHost)) {
                    return $platformKey;
                }
            }
        }

        return null;
    }

    /**
     * href内のクエリパラメータ値(リダイレクトURL等)をURLデコードし、
     * その遷移先ホストがSNSと一致するかを見る(例: 独自の外部リンク計測
     * リダイレクタ経由でSNSへ遷移するケース)。
     */
    private function matchSnsQueryParamHost(string $href): ?string
    {
        $query = (string) parse_url($href, PHP_URL_QUERY);
        if ($query === '') {
            return null;
        }

        parse_str($query, $params);
        foreach ($params as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $decoded = urldecode($value);
            $host = strtolower((string) parse_url($decoded, PHP_URL_HOST));
            if ($host === '') {
                continue;
            }

            $platform = $this->matchSnsHost($host);
            if ($platform !== null) {
                return $platform;
            }
        }

        return null;
    }

    /**
     * aria-label/title/img alt/svg title等の短いラベル文字列に対して
     * SNSプラットフォーム名を照合する。日本語混在テキストではPCREの`\b`が
     * (UTF-8モードでは平仮名等も`\w`とみなされるため)期待通りに機能しない
     * ため使わない。「x」のような1文字の曖昧なキーワードのみ、ASCII英数字
     * との連続を除外する簡易的な境界判定を行い、"next"等への誤爆を防ぐ。
     * それ以外のキーワード(facebook/instagram等)は十分に長く誤検知しにくい
     * ため単純な部分一致で判定する。
     */
    private function matchSnsKeyword(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        $lower = mb_strtolower($text);
        foreach (self::SNS_TEXT_KEYWORDS as $platformKey => $keywords) {
            foreach ($keywords as $keyword) {
                $keywordLower = mb_strtolower($keyword);

                if (mb_strlen($keywordLower) <= 1) {
                    if (preg_match('/(?<![a-z0-9])'.preg_quote($keywordLower, '/').'(?![a-z0-9])/u', $lower)) {
                        return $platformKey;
                    }

                    continue;
                }

                if (str_contains($lower, $keywordLower)) {
                    return $platformKey;
                }
            }
        }

        return null;
    }

    /**
     * 補助証拠。class属性を空白/ハイフン/アンダースコアで分割したトークンが
     * 指定platformのトークンと完全一致するかのみを見る(部分一致は誤検知の元)。
     */
    private function matchSnsClassToken(string $classAttribute, string $platform): bool
    {
        if ($classAttribute === '') {
            return false;
        }

        $tokens = preg_split('/[\s\-_]+/u', mb_strtolower($classAttribute)) ?: [];
        foreach ($tokens as $token) {
            if ((self::SNS_CLASS_TOKEN_PLATFORM[$token] ?? null) === $platform) {
                return true;
            }
        }

        return false;
    }

    private function dataAttributeValuesText(\DOMElement $node): string
    {
        $values = [];
        foreach ($node->attributes ?? [] as $attribute) {
            if ($attribute instanceof \DOMAttr && str_starts_with($attribute->name, 'data-')) {
                $values[] = $attribute->value;
            }
        }

        return implode(' ', $values);
    }

    /**
     * $contextの子孫要素($relativeQueryで指定)から、最初に見つかった
     * 空でない属性値を返す(未評価テンプレートプレースホルダーはsanitize)。
     */
    private function descendantAttributeText(\DOMXPath $xpath, \DOMElement $context, string $relativeQuery, string $attribute): string
    {
        $nodes = $xpath->query($relativeQuery, $context);
        foreach ($nodes ?? [] as $node) {
            if ($node instanceof \DOMElement) {
                $value = $this->sanitizeCandidateText(trim($node->getAttribute($attribute)));
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function descendantText(\DOMXPath $xpath, \DOMElement $context, string $relativeQuery): string
    {
        $nodes = $xpath->query($relativeQuery, $context);
        foreach ($nodes ?? [] as $node) {
            $value = $this->sanitizeCandidateText(trim($node->textContent));
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return '';
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
     * script[src]の既知ベンダーホストを判定する。stripNonContentElements()が
     * script要素自体を除去してしまう前に呼び出す必要がある。
     */
    private function matchChatbotScriptHost(\DOMXPath $xpath): ?string
    {
        foreach ($xpath->query('//script[@src]') ?? [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $host = strtolower((string) parse_url(trim($node->getAttribute('src')), PHP_URL_HOST));
            if ($host === '') {
                continue;
            }

            foreach (self::CHATBOT_SCRIPT_HOSTS as $chatbotHost) {
                if (str_ends_with($host, $chatbotHost)) {
                    return $chatbotHost;
                }
            }
        }

        return null;
    }

    /**
     * チャットサポート導入の目安を、script[src]の既知ベンダーホスト
     * (matchChatbotScriptHostで事前に判定済み)、または要素id/classの
     * 既知トークンから検出する。
     *
     * @return array{detected: bool, matched: ?string}
     */
    private function analyzeChatbotWidget(\DOMXPath $xpath, ?string $scriptMatch): array
    {
        if ($scriptMatch !== null) {
            return ['detected' => true, 'matched' => $scriptMatch];
        }

        foreach ($xpath->query('//*[@id or @class]') ?? [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $haystack = mb_strtolower($node->getAttribute('id').' '.$node->getAttribute('class'));
            foreach (self::CHATBOT_ELEMENT_TOKENS as $chatbotToken) {
                if (str_contains($haystack, $chatbotToken)) {
                    return ['detected' => true, 'matched' => $chatbotToken];
                }
            }
        }

        return ['detected' => false, 'matched' => null];
    }

    /**
     * 固定の料金ページリンクとは別に、価格表記(¥/円)と予約・プランCTAが
     * 同一コンテナ要素内に共存する箇所を検出する(旅行・EC系サイトのように
     * 固定料金ページが無くても商品カード上に価格がある業態のため)。
     * 本文中に「円」が単独で出るだけではpresent=trueにしない。
     *
     * ヒューリスティックであり網羅的な精度は保証しない(コンテナ判定は
     * 直近のli/div/article/section祖先を使う簡易的なものである)。
     *
     * @return array{present: bool, count: int, confidence: ?float, sample_text: ?string}
     */
    private function analyzeProductPriceCards(\DOMXPath $xpath): array
    {
        $priceCandidates = [];
        foreach ($xpath->query('//text()') ?? [] as $textNode) {
            $text = trim($textNode->textContent);
            if ($text === '' || ! preg_match('/[¥￥]\s?[\d,]+|[\d,]+\s?円/u', $text)) {
                continue;
            }

            $container = $this->nearestContainerAncestor($textNode);
            if ($container !== null) {
                $priceCandidates[spl_object_id($container)] = $container;
            }
        }

        $matchedContainers = [];
        $bestConfidence = 0.0;
        $sampleText = null;

        foreach ($priceCandidates as $container) {
            $duplicate = false;
            foreach ($matchedContainers as $existing) {
                if ($existing === $container
                    || $this->isDescendantOf($container, $existing)
                    || $this->isDescendantOf($existing, $container)) {
                    $duplicate = true;

                    break;
                }
            }

            if ($duplicate) {
                continue;
            }

            $hasCta = false;
            foreach ($xpath->query('.//a | .//button', $container) ?? [] as $ctaNode) {
                $ctaText = mb_strtolower($this->sanitizeCandidateText(trim($ctaNode->textContent)) ?? '');
                $ctaLabel = $ctaNode instanceof \DOMElement
                    ? mb_strtolower($this->sanitizeCandidateText(trim($ctaNode->getAttribute('aria-label'))) ?? '')
                    : '';

                if ($this->containsAny($ctaText, self::PRODUCT_PRICE_CARD_KEYWORDS) || $this->containsAny($ctaLabel, self::PRODUCT_PRICE_CARD_KEYWORDS)) {
                    $hasCta = true;

                    break;
                }
            }

            $containerText = trim($container->textContent);
            $hasKeywordNearby = $this->containsAny(mb_strtolower($containerText), self::PRODUCT_PRICE_CARD_KEYWORDS);

            if (! $hasCta && ! $hasKeywordNearby) {
                continue;
            }

            $matchedContainers[] = $container;
            $bestConfidence = max($bestConfidence, $hasCta ? 0.85 : 0.6);
            $sampleText ??= mb_substr($containerText, 0, 100);
        }

        return [
            'present' => $matchedContainers !== [],
            'count' => count($matchedContainers),
            'confidence' => $matchedContainers !== [] ? $bestConfidence : null,
            'sample_text' => $sampleText,
        ];
    }

    private function nearestContainerAncestor(\DOMNode $node): ?\DOMElement
    {
        $containerTags = ['li', 'div', 'article', 'section'];
        $parent = $node->parentNode;

        while ($parent !== null) {
            if ($parent instanceof \DOMElement && in_array(strtolower($parent->tagName), $containerTags, true)) {
                return $parent;
            }
            $parent = $parent->parentNode;
        }

        return null;
    }

    private function isDescendantOf(\DOMNode $node, \DOMNode $ancestor): bool
    {
        $parent = $node->parentNode;

        while ($parent !== null) {
            if ($parent === $ancestor) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
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
