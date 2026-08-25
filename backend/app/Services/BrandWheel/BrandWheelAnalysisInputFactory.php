<?php

namespace App\Services\BrandWheel;

use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\PageHtmlResolver;
use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ブランド・ホイール(6軸)分析のAI入力を組み立てる。既存のAiAnalysisInputFactory
 * (app/Services/AiAnalysis/AiAnalysisInputFactory.php)と同じ「入力の組み立てのみを
 * 責務とし、プロンプト構築はProvider側に委ねる」設計を踏襲するが、名前空間は
 * AiAnalysisとは分けて新設のApp\Services\BrandWheel配下に置く ―― ブランド・
 * ホイールは既存のAI分析(スコアリング入力)とは独立したProvider・DBテーブル・
 * Jobを持つ別サブシステムとして設計されている(承認済み実装計画)ため、
 * 既存名前空間には混在させない。
 *
 * LeadSession等リード系モデルへの依存を一切持たない ―― これにより、
 * リードの個人情報・企業識別情報(会社名・担当者名・電話番号)が
 * このFactory経由でAIへ渡る経路が構造的に存在しない。
 *
 * 採用ページ/トップページの本文テキスト・H1〜H3見出し・ナビゲーションリンク
 * ラベルは、いずれも保存済みのHTMLをHtmlSeoAnalyzer::extractBodyText()/
 * extractHeadingTexts()/extractNavigationLinkLabels()へ通してその場で
 * 抽出する ―― これらの値はこれまでどこにも永続化されていないため
 * (AnalysisPage.word_count/h1_countは集計値のみ)、既存のMetricResultからの
 * 再利用はできない。事業・サービスへのリンクラベルについては当初
 * pricing_info_link_present等の既存MetricResultの流用を検討したが、
 * それらは料金/FAQ/会社概要等「営業・信頼性評価用」の個別ページ検出であり
 * 「その会社が何を事業として展開しているか」(グローバルナビ上の事業単位の
 * ラベル)を示すものではないため差し戻され、header/nav/footer配下の
 * リンクテキストを直接抽出する方式に変更した。
 *
 * レンダリング後HTML(rendered_html_path、RenderPageJobがJS実行後のDOMを
 * 保存したもの)が利用可能ならそちらを優先し、無ければ静的HTML
 * (raw_html_path)にフォールバックする(PageHtmlResolver経由、
 * AnalyzeHtmlSeoJob/DetectTechnologyJobと同じ優先順位)。2026-08-04まで
 * このFactoryだけが常にraw_html_pathしか読まない実装になっており、
 * JSで本文を描画するサイト(recruit.lifull.com/culture/、
 * hello-world.smarthr.co.jp/で実際に再現)では静的HTMLが実質空になり、
 * 200文字のinsufficient_inputしきい値を必ず下回っていた。ただし
 * RenderPageJobは別ジョブとして並行dispatchされているため、この
 * Factoryが呼ばれる時点でまだrendered_html_pathが用意できていない
 * (=レースに負ける)ケースは残る。dispatch順序自体の見直しは別途
 * 検討中(AnalysisPipeline::dispatchWebsiteFanOut()参照)。
 *
 * 【仕様】診断実行時に保存されたHTMLのスナップショットを読む(ライブサイトを
 * 再取得しない)。これは実装上の近道ではなく、維持すべき仕様である ――
 * 相談ボタンは診断からある程度時間が経ってから押されることがあるが、この間に
 * サイトが更新されていても、ブランド・ホイール評価は診断時と同一のスナップショットに
 * 基づく。これにより、1通目(診断結果レポート)と2通目(ブランド・ホイール評価)が
 * 参照するサイトの内容が常に一致し、両者の間で矛盾が生じない
 * (2026-07-29、スナップショット一貫性の要件として明文化)。
 *
 * ファイル欠損(AnalysisPage行はあるが実体ファイルが無い/読めない)は異常系では
 * なく想定される経路として扱う ―― 例外を投げず、該当ページを「取得できな
 * かった」として空のテキストを返す。呼び出し元(GenerateBrandWheelAnalysisJob)は
 * これを正常なBrandWheelAnalysisInputとして扱い、既知のログに残した上で処理を
 * 継続する(相談申込通知メールの送信をブロックしない)。
 *
 * 依頼E(2026-08-25): 依頼C・Dで作られたサイト全ページ巡回
 * (analysis_crawled_pages)は、この時点までAIへの入力に一切反映されて
 * いなかった(Analysis.crawl_site=trueにしても本Factoryの出力は
 * crawl_site=falseと同一だった)。この依頼でその配線を追加する。
 *
 * 【最重要の不変条件】Analysis.crawl_site=falseのとき、toArray()の出力は
 * この変更の前後でバイト単位で完全に同一であること
 * (BrandWheelAnalysisInputFactoryToArrayRegressionTest参照)。クロール由来の
 * 処理は、まずcrawl_site=trueかどうかで完全に分岐し、falseの場合は
 * クロール関連のクエリすら発行しない。
 *
 * 【DTOのフィールド名と中身の意味のずれ、意図的なトレードオフ】
 * crawl_site=trueの場合、recruitPageBodyText/homepageBodyTextには、採用
 * ページ/トップページ本文(seed)に加えて、巡回で発見した複数ページ由来の
 * 段落(採用クラスタ/トップページクラスタに分類したもの、buildClusterPools()
 * 参照)が連結される。フィールド名は「採用ページ本文」「トップページ本文」の
 * ままだが、実際には複数ページ由来の本文が混在しうる。BrandWheelAnalysisInput
 * のフィールド構成(依頼Bの案1で合意)・判定プロンプトv8の入力形状を変えない
 * ことを優先し、新しいトップレベルフィールドを追加しない意図的な選択である。
 * クロールの件数・内訳はAIへの入力にもtoArray()にも一切現れず、Log::infoに
 * のみ記録する(下記buildClusterPools()参照)。
 */
class BrandWheelAnalysisInputFactory
{
    /**
     * ホームページとリード採用ページのナビゲーションラベルを統合した後、
     * 全体で保持する最大件数(HtmlSeoAnalyzer::extractNavigationLinkLabels()
     * は1ページあたり最大50件を返すため、2ページ分の統合後に再度キャップする)。
     */
    private const MERGED_NAV_LABEL_MAX_COUNT = 50;

    /**
     * 各ページの取得状態(BrandWheelAnalysisInput::sourcePages / #97のメール本文で
     * 使う診断情報)。'absent'(AnalysisPage行自体が無い、正常系)と
     * 'unreadable'(行はあるがファイル実体が無い、異常系)を区別することで、
     * insufficient_inputの原因がサイト側の事情かストレージ側の事情かを
     * 2通目メールの読み手が判別できるようにする。
     */
    private const PAGE_STATUS_READ = 'read';

    private const PAGE_STATUS_ABSENT = 'absent';

    private const PAGE_STATUS_UNREADABLE = 'unreadable';

    /**
     * 優先度4-3(2026-08-24追加)。トップページ自身が既に採用ページである
     * 自己参照(FetchRecruitPageJob::process()参照、Recruit/Homepageの
     * AnalysisPage行が同一のraw_html_pathを指す)の場合に使う。既存の
     * 'absent'(採用ページが見つからなかった)や'unreadable'(取得失敗)とは
     * 意味が異なる ―― こちらは正常に取得できているが、トップページ本文と
     * 重複するため意図的に本文を空扱いにしている。同じ値にすると2通目
     * メールの読み手が「採用ページが見つからなかった/取得に失敗した」と
     * 取り違えるため、既存値とは別の値にする(依頼者指摘)。
     */
    private const PAGE_STATUS_SELF_REFERENCE = 'self_reference';

    public function __construct(
        private readonly HtmlSeoAnalyzer $htmlSeoAnalyzer,
        private readonly PageHtmlResolver $htmlResolver,
    ) {}

    public function build(WebsiteAnalysis $websiteAnalysis): BrandWheelAnalysisInput
    {
        $recruitPage = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('page_type', PageType::Recruit)
            ->first();

        $homepage = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('page_type', PageType::Homepage)
            ->first();

        // 優先度4-3(2026-08-24): FetchRecruitPageJob::process()が自己参照
        // (トップページ自身が既に採用ページ)を検出した場合、RecruitとHomepageの
        // AnalysisPage行は同一のraw_html_pathを指す。ここで検出せずに両方を
        // extractPageText()すると、同じHTMLファイルから同じ本文・見出し・
        // ナビゲーションラベルを2回読み出してしまい、(1)AIへ同じ本文が2回
        // 渡る、(2)isInputInsufficient()の文字数合計が実質2倍になり薄い
        // サイトが安全弁を通過する、という問題が生じる(依頼者指摘)。
        // raw_html_pathの一致で判定する ―― 同じファイルを指していれば内容も
        // 必ず同一のため、これ以上の判定(final_urlの正規化比較等)は不要。
        $isSelfReference = $recruitPage !== null
            && $homepage !== null
            && $recruitPage->raw_html_path !== null
            && $recruitPage->raw_html_path === $homepage->raw_html_path;

        if ($isSelfReference) {
            [$recruitBodyText, $recruitHeadings, $recruitNavLabels, $recruitPageStatus, $recruitHtmlSource, $recruitAllLinkLabels]
                = ['', [], [], self::PAGE_STATUS_SELF_REFERENCE, null, []];
        } else {
            [$recruitBodyText, $recruitHeadings, $recruitNavLabels, $recruitPageStatus, $recruitHtmlSource, $recruitAllLinkLabels] = $this->extractPageText($recruitPage);
        }

        [$homepageBodyText, $homepageHeadings, $homepageNavLabels, $homepageStatus, $homepageHtmlSource, $homepageAllLinkLabels] = $this->extractPageText($homepage);

        // どちらのHTML(rendered/static)を読んだかは、レース(RenderPageJobが
        // まだ完了していない)が実際に起きているかを事後に判別できるよう
        // ログにのみ残す。sourcePages(下記)はBrandWheelAnalysisResult経由で
        // リード向けJSON API・メールへそのまま渡っているため、新しい値を
        // 混ぜて既存のレスポンス形状を変えないようにする(2026-08-04)。
        Log::info('Brand wheel analysis input: html source resolved', [
            'website_analysis_id' => $websiteAnalysis->id,
            'recruit_page_html_source' => $recruitHtmlSource,
            'home_page_html_source' => $homepageHtmlSource,
        ]);

        // グローバルナビは通常トップページ側が正とみなせるため先に採用し、
        // 採用ページ独自のナビ項目(例: 採用サイト側にしかない事業紹介導線)を
        // 後から補う。重複は正規化後の文字列で除去する。
        $businessLinkLabels = $this->mergeNavLabels($homepageNavLabels, $recruitNavLabels);

        // label_only_evidence判定専用(2026-08-05追加)。AIへは渡さないため
        // トークン予算(applyTokenLimit())の対象外とし、件数上限もbusinessLinkLabels
        // (MERGED_NAV_LABEL_MAX_COUNT=50)より緩い、extractAllLinkTexts()自体の
        // 上限(300件/ページ)にそのまま従う。
        $allLinkLabels = array_values(array_unique([...$homepageAllLinkLabels, ...$recruitAllLinkLabels]));

        // 依頼E-1: crawl_site=falseのときはクロール関連のクエリを一切発行
        // しない ―― 「クロール結果が0件だから空プールになる」のではなく、
        // オプトインしていない限りそもそも巡回結果を見に行かない、という
        // 構造そのものでtoArray()のバイト単位一致(最重要の不変条件)を
        // 担保する。
        $crawlEnabled = $websiteAnalysis->analysis?->crawl_site === true;
        $crawlPools = $crawlEnabled
            ? $this->buildClusterPools($websiteAnalysis, $recruitBodyText, $homepageBodyText)
            : ['recruit' => [], 'homepage' => []];

        [$keptRecruitBody, $keptHomepageBody, $keptLabels, $truncated] = $this->applyTokenLimit(
            websiteAnalysisId: $websiteAnalysis->id,
            recruitBodyText: $recruitBodyText,
            homepageBodyText: $homepageBodyText,
            recruitHeadings: $recruitHeadings,
            homepageHeadings: $homepageHeadings,
            recruitTitle: $recruitPage?->title,
            homepageTitle: $homepage?->title,
            labels: $businessLinkLabels,
            crawlPools: $crawlPools,
        );

        return new BrandWheelAnalysisInput(
            websiteAnalysisId: $websiteAnalysis->id,
            recruitPageTitle: $recruitPage?->title,
            recruitPageBodyText: $keptRecruitBody,
            recruitPageHeadings: $recruitHeadings,
            homepageTitle: $homepage?->title,
            homepageBodyText: $keptHomepageBody,
            homepageHeadings: $homepageHeadings,
            businessLinkLabels: $keptLabels,
            inputTruncated: $truncated,
            sourcePages: ['recruit_page' => $recruitPageStatus, 'home_page' => $homepageStatus],
            allLinkLabels: $allLinkLabels,
        );
    }

    /**
     * @return array{0: string, 1: list<array{level: int, text: string}>, 2: list<string>, 3: string, 4: ?string, 5: list<string>}
     */
    private function extractPageText(?AnalysisPage $page): array
    {
        if ($page === null) {
            // AnalysisPage行自体が無い(例: 採用ページが検出されなかった)のは
            // 正当な状態であり、想定内の経路として無言で空扱いにする。
            return ['', [], [], self::PAGE_STATUS_ABSENT, null, []];
        }

        // レンダリング後HTML(JS実行後)が既に利用可能ならそちらを優先する
        // (AnalyzeHtmlSeoJob/DetectTechnologyJobと同じ優先順位、
        // PageHtmlResolver参照)。RenderPageJobは別ジョブとして並行
        // dispatchされているため、この時点でまだrendered_html_pathが
        // 用意できていないことは正常系であり、その場合は静的HTMLへ
        // フォールバックする。
        $resolved = $this->htmlResolver->resolve($page);

        if ($resolved !== null) {
            $html = Storage::disk('analysis')->get($resolved['path']);

            return [
                // 2026-08-04: ブランド・ホイールの入力に限りnav/header/footer/
                // asideを除いた本文を渡す(HtmlSeoAnalyzer::extractBodyText()の
                // $excludeNavigation docblock参照)。extractHeadingTexts()/
                // extractNavigationLinkLabels()はここでは変更しない ――
                // 見出し構造・ナビゲーションラベル自体は既存どおり別途入力に含める。
                $this->htmlSeoAnalyzer->extractBodyText($html, excludeNavigation: true),
                $this->htmlSeoAnalyzer->extractHeadingTexts($html),
                $this->htmlSeoAnalyzer->extractNavigationLinkLabels($html),
                self::PAGE_STATUS_READ,
                $resolved['source'],
                $this->htmlSeoAnalyzer->extractAllLinkTexts($html),
            ];
        }

        if ($page->raw_html_path === null) {
            // 生HTMLのパス自体が記録されていない(FetchStaticPageJob/
            // RenderPageJobのいずれもまだ完了していない等)。異常ではないため
            // 無言でABSENT扱いにする(既存挙動を維持)。
            return ['', [], [], self::PAGE_STATUS_ABSENT, null, []];
        }

        // raw_html_pathは記録されているのにファイル実体が無い/読めない
        // (ファイル欠損・パス不整合)。「ページが元々無い」(正常系、
        // AnalysisPage行自体が存在しない)とは明確に異なり、こちらは
        // ストレージ到達性の障害である可能性がある(例: Renderで生HTMLを
        // 書き込むワーカーとこのJobが別サービスに分かれ、永続ディスクを
        // 共有できていない場合、症状はまさにこれと一致する)。運用上検知
        // されるべき障害のためLog::errorで記録する(2026-07-29の指摘。
        // 例外は投げず処理は継続する ―― 呼び出し元が入力の充足度を見て
        // 評価不可として扱う)。
        Log::error('Brand wheel analysis: stored raw HTML file is missing', [
            'analysis_page_id' => $page->id,
            'website_analysis_id' => $page->website_analysis_id,
            'page_type' => $page->page_type->value,
        ]);

        return ['', [], [], self::PAGE_STATUS_UNREADABLE, null, []];
    }

    /**
     * @param  list<string>  $primaryLabels
     * @param  list<string>  $secondaryLabels
     * @return list<string>
     */
    private function mergeNavLabels(array $primaryLabels, array $secondaryLabels): array
    {
        $merged = [];
        foreach ([...$primaryLabels, ...$secondaryLabels] as $label) {
            if (count($merged) >= self::MERGED_NAV_LABEL_MAX_COUNT) {
                break;
            }
            if (in_array($label, $merged, true)) {
                continue;
            }
            $merged[] = $label;
        }

        return $merged;
    }

    /**
     * 依頼E-2/E-3: クロールで発見したページ(analysis_crawled_pages、
     * status='fetched'かつraw_html_pathが非null)を読み、採用クラスタ/
     * トップページクラスタへ分類したうえで、段落単位の候補プールを組み立てる。
     * 新しい抽出器は書かない ―― 本文抽出はseedページと全く同じ
     * HtmlSeoAnalyzer::extractBodyText($html, excludeNavigation: true)を使う。
     *
     * 手順(依頼E-3):
     * 1. seed(トップページ・採用ページ)の本文は呼び出し元で既に確保済み。
     *    ここではその段落を「既知の段落」として重複除去の初期集合に使う。
     * 2. クロールページ本文を段落(extractBodyText()が\nで区切るブロック
     *    境界)単位に分割する。
     * 3. 重複除去 ―― seedとの重複、クロールページ同士の重複の両方を、
     *    正規化済み(extractBodyText()が既に空白・改行を正規化している)
     *    段落の完全一致で除去する。ページ順(depth→id)で処理するため、
     *    「最初に出現した箇所を残す」early-win方式になる。
     * 4. 各クラスタ内で残った段落を文字数の多い順にソートする(依頼C-8の
     *    測定で改善が確認済みの「最長段落優先」の考え方をそのまま踏襲。
     *    キーワード辞書・スコアリングは実装しない、依頼者指定)。同じ長さの
     *    場合はpage_order/para_orderでタイブレークし、結果を決定的にする。
     * 5〜6. 実際の予算内選定・元の順序への並べ替え・バケットへの追記は
     *    呼び出し元のapplyTokenLimit()が行う(トークン予算の配分と一体の
     *    処理のため、こちらでは候補プールを用意するところまでを担う)。
     *
     * @return array{recruit: list<array{text: string, length: int, page_order: int, para_order: int}>, homepage: list<array{text: string, length: int, page_order: int, para_order: int}>}
     */
    private function buildClusterPools(WebsiteAnalysis $websiteAnalysis, string $recruitBodyText, string $homepageBodyText): array
    {
        $seen = [];
        foreach ([...$this->splitParagraphs($recruitBodyText), ...$this->splitParagraphs($homepageBodyText)] as $seedParagraph) {
            $seen[$seedParagraph] = true;
        }

        $pages = AnalysisCrawledPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
            ->whereNotNull('raw_html_path')
            ->orderBy('depth')
            ->orderBy('id')
            ->get();

        $pools = ['recruit' => [], 'homepage' => []];
        $pagesRead = 0;
        $pagesUnreadable = 0;
        $paragraphsSeen = 0;
        $paragraphsDeduped = 0;

        foreach ($pages as $pageOrder => $page) {
            $resolved = $this->htmlResolver->resolve($page);

            if ($resolved === null) {
                $pagesUnreadable++;

                continue;
            }

            $html = Storage::disk('analysis')->get($resolved['path']);
            $body = $this->htmlSeoAnalyzer->extractBodyText($html, excludeNavigation: true);
            $pagesRead++;

            $cluster = $this->htmlSeoAnalyzer->isRecruitPageUrl($page->final_url ?? $page->url) ? 'recruit' : 'homepage';

            foreach ($this->splitParagraphs($body) as $paraOrder => $paragraph) {
                $paragraphsSeen++;

                if (isset($seen[$paragraph])) {
                    $paragraphsDeduped++;

                    continue;
                }

                $seen[$paragraph] = true;
                $pools[$cluster][] = [
                    'text' => $paragraph,
                    'length' => mb_strlen($paragraph),
                    'page_order' => $pageOrder,
                    'para_order' => $paraOrder,
                ];
            }
        }

        foreach ($pools as &$pool) {
            usort($pool, fn (array $a, array $b) => $b['length'] <=> $a['length']
                ?: $a['page_order'] <=> $b['page_order']
                ?: $a['para_order'] <=> $b['para_order']);
        }
        unset($pool);

        // 依頼E-7の測定用(件数・内訳のみ。本文の実テキストは出さない)。
        Log::info('Brand wheel analysis input: crawled pages integrated', [
            'website_analysis_id' => $websiteAnalysis->id,
            'crawled_pages_total' => $pages->count(),
            'crawled_pages_read' => $pagesRead,
            'crawled_pages_unreadable' => $pagesUnreadable,
            'crawled_paragraphs_seen' => $paragraphsSeen,
            'crawled_paragraphs_deduped' => $paragraphsDeduped,
            'crawled_paragraphs_kept' => $paragraphsSeen - $paragraphsDeduped,
            'recruit_cluster_pool_count' => count($pools['recruit']),
            'homepage_cluster_pool_count' => count($pools['homepage']),
        ]);

        return $pools;
    }

    /**
     * extractBodyText()はブロック境界タグの前後に\nを挿入した後、連続改行を
     * 1つに畳んでいる(normalizeBlockText())ため、\nで分割すればブロック
     * (段落相当)単位のテキストが文書順に得られる。空行(前後トリムで空に
     * なるもの)は除く。
     *
     * @return list<string>
     */
    private function splitParagraphs(string $bodyText): array
    {
        if ($bodyText === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $bodyText)),
            fn (string $paragraph) => $paragraph !== '',
        ));
    }

    /**
     * AI_MAX_INPUT_TOKENS(既存のOpenAiAnalysisProviderと同一のconfigキー、
     * config('services.ai.max_input_tokens'))を超過する場合、採用ページ本文を
     * 最優先で残し、トップページ本文→事業リンクラベルの順に削って上限内に
     * 収める。見出し構造・<title>は絶対に削らない(軸の読み取りに効くため)。
     * 無言での切り捨ては禁止のため、切り詰めた場合は必ずLogへ記録する
     * (本文の実テキストはログへ出さず、文字数のみを記録する)。
     *
     * 依頼E-4(2026-08-25): この既存の優先順位(seed本文→ラベル)による
     * 切り詰めを一切変更せずまず実行し、そこで余った予算だけをクロール分
     * ($crawlPools)に回す。「seed本文は必ず予算内に確保すること」
     * (依頼者指定)を、クロール処理を一切経由しない従来ロジックの実行結果を
     * そのまま使うことで構造的に保証する ―― $crawlPoolsの両クラスタが空
     * (crawl_site=falseの場合を含む)なら、この関数は既存の実装と
     * バイト単位で完全に同一の値を返す(下記の分岐参照)。
     *
     * クロール分の配分方式(allocateCrawlBudget()参照)は「まず予算を半分ずつ
     * 固定で割り当て、使い切らなかった分だけ相手クラスタへ回す」方式を
     * 採用した。ラウンドロビン(1段落ずつ交互)も検討したが、片方のクラスタの
     * 最初の候補1段落だけで予算全体を超えるような偏った状況(例: 採用クラスタに
     * 巨大な1段落、トップページクラスタに小さな候補が複数)では、ラウンド
     * ロビンでも最初の1段落がその場で予算を食い尽くし、もう片方に一切
     * 配分されない(依頼者指定の「片方が全部落ちる形にはしないこと」に
     * 反する)ことが実装中の自己検証で判明したため、固定按分方式に変更した。
     * 固定按分なら、相手クラスタの段落サイズに関わらず、各クラスタは
     * 常に自分の割り当て分(予算の半分)を専有できる。
     *
     * @param  list<array{level: int, text: string}>  $recruitHeadings
     * @param  list<array{level: int, text: string}>  $homepageHeadings
     * @param  list<string>  $labels
     * @param  array{recruit: list<array{text: string, length: int, page_order: int, para_order: int}>, homepage: list<array{text: string, length: int, page_order: int, para_order: int}>}  $crawlPools
     * @return array{0: string, 1: string, 2: list<string>, 3: bool}
     */
    private function applyTokenLimit(
        int $websiteAnalysisId,
        string $recruitBodyText,
        string $homepageBodyText,
        array $recruitHeadings,
        array $homepageHeadings,
        ?string $recruitTitle,
        ?string $homepageTitle,
        array $labels,
        array $crawlPools,
    ): array {
        $maxInputTokens = config('services.ai.max_input_tokens');
        $hasCrawlContent = $crawlPools['recruit'] !== [] || $crawlPools['homepage'] !== [];

        if ($maxInputTokens === null) {
            if (! $hasCrawlContent) {
                return [$recruitBodyText, $homepageBodyText, $labels, false];
            }

            // 上限が設定されていない場合、切り詰めという概念自体が無いため
            // クロール分も無条件に全て連結する(予算による選定は行わない)。
            return [
                $this->appendCrawlText($recruitBodyText, $this->joinInOriginalOrder($crawlPools['recruit'])),
                $this->appendCrawlText($homepageBodyText, $this->joinInOriginalOrder($crawlPools['homepage'])),
                $labels,
                false,
            ];
        }

        // OpenAiAnalysisProvider::estimateTokenCount()と同一の粗い概算
        // (文字数/3)を逆算し、文字数ベースの予算として扱う。
        $maxChars = ((int) $maxInputTokens) * 3;

        $fixedChars = mb_strlen((string) $recruitTitle)
            + mb_strlen((string) $homepageTitle)
            + $this->headingsCharLength($recruitHeadings)
            + $this->headingsCharLength($homepageHeadings);

        $bodyAndLabelBudget = max(0, $maxChars - $fixedChars);

        $totalBodyAndLabelChars = mb_strlen($recruitBodyText) + mb_strlen($homepageBodyText)
            + array_sum(array_map('mb_strlen', $labels));

        if ($totalBodyAndLabelChars <= $bodyAndLabelBudget) {
            $keptRecruitBody = $recruitBodyText;
            $keptHomepageBody = $homepageBodyText;
            $keptLabels = $labels;
            $seedTruncated = false;
            $remainingForCrawl = $bodyAndLabelBudget - $totalBodyAndLabelChars;
        } else {
            // 既存アルゴリズムそのまま(バイト単位で同一の挙動を保つ)。
            $remaining = $bodyAndLabelBudget;

            $keptRecruitBody = mb_substr($recruitBodyText, 0, $remaining);
            $remaining = max(0, $remaining - mb_strlen($keptRecruitBody));

            $keptHomepageBody = mb_substr($homepageBodyText, 0, $remaining);
            $remaining = max(0, $remaining - mb_strlen($keptHomepageBody));

            $keptLabels = [];
            foreach ($labels as $label) {
                $labelLength = mb_strlen($label);
                if ($labelLength > $remaining) {
                    continue;
                }
                $keptLabels[] = $label;
                $remaining -= $labelLength;
            }

            $seedTruncated = true;
            $remainingForCrawl = $remaining;
        }

        if (! $hasCrawlContent) {
            if ($seedTruncated) {
                Log::warning('Brand wheel analysis input truncated due to AI_MAX_INPUT_TOKENS', [
                    'website_analysis_id' => $websiteAnalysisId,
                    'max_input_tokens' => $maxInputTokens,
                    'recruit_body_chars_before' => mb_strlen($recruitBodyText),
                    'recruit_body_chars_after' => mb_strlen($keptRecruitBody),
                    'homepage_body_chars_before' => mb_strlen($homepageBodyText),
                    'homepage_body_chars_after' => mb_strlen($keptHomepageBody),
                    'business_link_labels_before' => count($labels),
                    'business_link_labels_after' => count($keptLabels),
                ]);
            }

            return [$keptRecruitBody, $keptHomepageBody, $keptLabels, $seedTruncated];
        }

        [$recruitCrawlText, $homepageCrawlText, $crawlTruncated, $crawlCharsUsed] = $this->allocateCrawlBudget($crawlPools, $remainingForCrawl);

        $truncated = $seedTruncated || $crawlTruncated;

        if ($truncated) {
            Log::warning('Brand wheel analysis input truncated due to AI_MAX_INPUT_TOKENS', [
                'website_analysis_id' => $websiteAnalysisId,
                'max_input_tokens' => $maxInputTokens,
                'recruit_body_chars_before' => mb_strlen($recruitBodyText),
                'recruit_body_chars_after' => mb_strlen($keptRecruitBody),
                'homepage_body_chars_before' => mb_strlen($homepageBodyText),
                'homepage_body_chars_after' => mb_strlen($keptHomepageBody),
                'business_link_labels_before' => count($labels),
                'business_link_labels_after' => count($keptLabels),
                'crawl_recruit_paragraphs_available' => count($crawlPools['recruit']),
                'crawl_homepage_paragraphs_available' => count($crawlPools['homepage']),
                'crawl_chars_added' => $crawlCharsUsed,
            ]);
        }

        return [
            $this->appendCrawlText($keptRecruitBody, $recruitCrawlText),
            $this->appendCrawlText($keptHomepageBody, $homepageCrawlText),
            $keptLabels,
            $truncated,
        ];
    }

    /**
     * クロール分の予算配分(固定按分+余り再配分方式、applyTokenLimit()の
     * docblockに選定理由の記載あり)。
     *
     * 1. budgetを半分ずつ($half, $budget - $half)、recruit/homepageクラスタへ
     *    固定で割り当てる(キャップ)。
     * 2. 各クラスタは自分のキャップの範囲内で、文字数の多い順(buildClusterPools()で
     *    ソート済み)に段落を採用する。キャップ内に収まらない段落は
     *    mb_substrで部分的に残す(既存のseed本文の切り詰めと同じ方針)。
     * 3. 一方のクラスタが自分のキャップを使い切らなかった(候補プールが
     *    キャップより小さかった)場合、その余りをもう一方のクラスタの
     *    残り候補(自分のキャップで採用しきれなかった分)へ回す。
     *
     * @param  array{recruit: list<array{text: string, length: int, page_order: int, para_order: int}>, homepage: list<array{text: string, length: int, page_order: int, para_order: int}>}  $crawlPools
     * @return array{0: string, 1: string, 2: bool, 3: int} [recruit用クロールテキスト, homepage用クロールテキスト, 切り詰めが発生したか, 実際に使った文字数]
     */
    private function allocateCrawlBudget(array $crawlPools, int $budget): array
    {
        $budget = max(0, $budget);
        $half = intdiv($budget, 2);
        $caps = ['recruit' => $half, 'homepage' => $budget - $half];

        $selected = ['recruit' => [], 'homepage' => []];
        $leftoverQueues = [];
        $usedChars = [];
        $cutOffByBudget = false;

        foreach (['recruit', 'homepage'] as $cluster) {
            [$taken, $rest, $used, $cutOff] = $this->takeParagraphsUpTo($crawlPools[$cluster], $caps[$cluster]);
            $selected[$cluster] = $taken;
            $leftoverQueues[$cluster] = $rest;
            $usedChars[$cluster] = $used;
            $cutOffByBudget = $cutOffByBudget || $cutOff;
        }

        foreach (['recruit' => 'homepage', 'homepage' => 'recruit'] as $donor => $receiver) {
            $unused = $caps[$donor] - $usedChars[$donor];
            if ($unused <= 0 || $leftoverQueues[$receiver] === []) {
                continue;
            }

            [$extra, $rest, $used, $cutOff] = $this->takeParagraphsUpTo($leftoverQueues[$receiver], $unused);
            $selected[$receiver] = [...$selected[$receiver], ...$extra];
            $leftoverQueues[$receiver] = $rest;
            $usedChars[$receiver] += $used;
            $cutOffByBudget = $cutOffByBudget || $cutOff;
        }

        // 手順4〜5(依頼E-3): 予算内に選ばれた段落だけを、元のページ順・
        // ページ内出現順に戻してから連結する(選定の基準(長さ)と配置の
        // 順序(元の並び)は別物として扱う、依頼者指定)。
        $recruitText = $this->joinInOriginalOrder($selected['recruit']);
        $homepageText = $this->joinInOriginalOrder($selected['homepage']);

        // budget内に収まりきらず採用されなかった段落がキューに残っていれば、
        // それも切り詰め扱いにする。
        $truncated = $cutOffByBudget || $leftoverQueues['recruit'] !== [] || $leftoverQueues['homepage'] !== [];

        return [$recruitText, $homepageText, $truncated, mb_strlen($recruitText) + mb_strlen($homepageText)];
    }

    /**
     * 与えられたキュー(文字数の多い順に並んだ段落リスト)から、capの範囲内で
     * 先頭から順に採用する。キャップ内に収まらない段落に達したら
     * mb_substrで部分的に採用して打ち切る(既存のseed本文の切り詰めと同じ
     * 方針)。
     *
     * @param  list<array{text: string, length: int, page_order: int, para_order: int}>  $queue
     * @return array{0: list<array{text: string, page_order: int, para_order: int}>, 1: list<array{text: string, length: int, page_order: int, para_order: int}>, 2: int, 3: bool} [採用した段落, 未処理のまま残ったキュー, 使った文字数, 段落の途中でキャップが尽きたか]
     */
    private function takeParagraphsUpTo(array $queue, int $cap): array
    {
        $taken = [];
        $used = 0;
        $cutOff = false;

        while ($queue !== [] && $used < $cap) {
            $paragraph = array_shift($queue);
            $remainingCap = $cap - $used;

            if ($paragraph['length'] <= $remainingCap) {
                $taken[] = $paragraph;
                $used += $paragraph['length'];

                continue;
            }

            $partial = mb_substr($paragraph['text'], 0, $remainingCap);
            if ($partial !== '') {
                $taken[] = ['text' => $partial, 'page_order' => $paragraph['page_order'], 'para_order' => $paragraph['para_order']];
                $used += mb_strlen($partial);
            }
            $cutOff = true;
            break;
        }

        return [$taken, $queue, $used, $cutOff];
    }

    /**
     * @param  list<array{text: string, page_order: int, para_order: int}>  $paragraphs
     */
    private function joinInOriginalOrder(array $paragraphs): string
    {
        usort($paragraphs, fn (array $a, array $b) => $a['page_order'] <=> $b['page_order']
            ?: $a['para_order'] <=> $b['para_order']);

        return implode("\n", array_column($paragraphs, 'text'));
    }

    private function appendCrawlText(string $seedText, string $crawlText): string
    {
        if ($crawlText === '') {
            return $seedText;
        }

        return $seedText === '' ? $crawlText : $seedText."\n".$crawlText;
    }

    /**
     * @param  list<array{level: int, text: string}>  $headings
     */
    private function headingsCharLength(array $headings): int
    {
        return array_sum(array_map(fn (array $h) => mb_strlen($h['text']), $headings));
    }
}
