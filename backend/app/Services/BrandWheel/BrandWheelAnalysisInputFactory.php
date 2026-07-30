<?php

namespace App\Services\BrandWheel;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\HtmlSeoAnalyzer;
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
 * ラベルは、いずれも保存済みの生HTMLをHtmlSeoAnalyzer::extractBodyText()/
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
 * 【仕様】診断実行時に保存された生HTMLのスナップショットを読む(ライブサイトを
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

    public function __construct(
        private readonly HtmlSeoAnalyzer $htmlSeoAnalyzer,
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

        [$recruitBodyText, $recruitHeadings, $recruitNavLabels, $recruitPageStatus] = $this->extractPageText($recruitPage);
        [$homepageBodyText, $homepageHeadings, $homepageNavLabels, $homepageStatus] = $this->extractPageText($homepage);

        // グローバルナビは通常トップページ側が正とみなせるため先に採用し、
        // 採用ページ独自のナビ項目(例: 採用サイト側にしかない事業紹介導線)を
        // 後から補う。重複は正規化後の文字列で除去する。
        $businessLinkLabels = $this->mergeNavLabels($homepageNavLabels, $recruitNavLabels);

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
        );
    }

    /**
     * @return array{0: string, 1: list<array{level: int, text: string}>, 2: list<string>, 3: string}
     */
    private function extractPageText(?AnalysisPage $page): array
    {
        if ($page === null || $page->raw_html_path === null) {
            // AnalysisPage行自体が無い(例: 採用ページが検出されなかった)のは
            // 正当な状態であり、想定内の経路として無言で空扱いにする。
            return ['', [], [], self::PAGE_STATUS_ABSENT];
        }

        $disk = Storage::disk('analysis');

        if (! $disk->exists($page->raw_html_path)) {
            // 行はあるがファイル実体が無い/読めない(ファイル欠損・パス不整合)。
            // 「ページが元々無い」(正常系、AnalysisPage行自体が存在しない)とは
            // 明確に異なり、こちらはストレージ到達性の障害である可能性がある
            // (例: Renderで生HTMLを書き込むワーカーとこのJobが別サービスに
            // 分かれ、永続ディスクを共有できていない場合、症状はまさにこれと
            // 一致する)。運用上検知されるべき障害のためLog::errorで記録する
            // (2026-07-29の指摘。例外は投げず処理は継続する ―― 呼び出し元が
            // 入力の充足度を見て評価不可として扱う)。
            Log::error('Brand wheel analysis: stored raw HTML file is missing', [
                'analysis_page_id' => $page->id,
                'website_analysis_id' => $page->website_analysis_id,
                'page_type' => $page->page_type->value,
            ]);

            return ['', [], [], self::PAGE_STATUS_UNREADABLE];
        }

        $html = $disk->get($page->raw_html_path);

        return [
            $this->htmlSeoAnalyzer->extractBodyText($html),
            $this->htmlSeoAnalyzer->extractHeadingTexts($html),
            $this->htmlSeoAnalyzer->extractNavigationLinkLabels($html),
            self::PAGE_STATUS_READ,
        ];
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
