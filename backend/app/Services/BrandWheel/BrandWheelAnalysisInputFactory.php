<?php

namespace App\Services\BrandWheel;

use App\Enums\PageType;
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

        [$keptRecruitBody, $keptHomepageBody, $keptLabels, $truncated] = $this->applyTokenLimit(
            websiteAnalysisId: $websiteAnalysis->id,
            recruitBodyText: $recruitBodyText,
            homepageBodyText: $homepageBodyText,
            recruitHeadings: $recruitHeadings,
            homepageHeadings: $homepageHeadings,
            recruitTitle: $recruitPage?->title,
            homepageTitle: $homepage?->title,
            labels: $businessLinkLabels,
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
     * AI_MAX_INPUT_TOKENS(既存のOpenAiAnalysisProviderと同一のconfigキー、
     * config('services.ai.max_input_tokens'))を超過する場合、採用ページ本文を
     * 最優先で残し、トップページ本文→事業リンクラベルの順に削って上限内に
     * 収める。見出し構造・<title>は絶対に削らない(軸の読み取りに効くため)。
     * 無言での切り捨ては禁止のため、切り詰めた場合は必ずLogへ記録する
     * (本文の実テキストはログへ出さず、文字数のみを記録する)。
     *
     * @param  list<array{level: int, text: string}>  $recruitHeadings
     * @param  list<array{level: int, text: string}>  $homepageHeadings
     * @param  list<string>  $labels
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
    ): array {
        $maxInputTokens = config('services.ai.max_input_tokens');

        if ($maxInputTokens === null) {
            return [$recruitBodyText, $homepageBodyText, $labels, false];
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
            return [$recruitBodyText, $homepageBodyText, $labels, false];
        }

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

        return [$keptRecruitBody, $keptHomepageBody, $keptLabels, true];
    }

    /**
     * @param  list<array{level: int, text: string}>  $headings
     */
    private function headingsCharLength(array $headings): int
    {
        return array_sum(array_map(fn (array $h) => mb_strlen($h['text']), $headings));
    }
}
