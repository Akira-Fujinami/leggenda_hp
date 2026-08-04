<?php

namespace App\Services\Report;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelComparisonSummaryComposer;
use App\Services\BrandWheel\BrandWheelHexagonRenderer;
use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use App\Services\BrandWheel\BrandWheelRadarSvgBuilder;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use App\Services\Lead\LeadPerspectiveComposer;
use App\Services\Lead\LeadRecommendationComposer;
use App\Services\Lead\LeadScoreCalculator;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;

/**
 * Analysis(+LeadSession)から、Word/PDF生成が共通で参照するReportViewModelを
 * 組み立てる。スコア計算・強み弱み判定は既存のLeadScoreCalculator等を
 * そのまま呼び出すだけで、再実装は一切しない。
 *
 * Phase 3: 内部の7カテゴリ別内訳の代わりに、採用担当向けの4観点
 * (LeadPerspectiveComposer)を組み込む。JSON API(LeadAnalysisController)と
 * 同じComposerを使うため、画面とレポートで表示内容が食い違わない。
 *
 * スコアも同様にLeadScoreCalculator(社内版OverallScoreCalculatorとは別建て、
 * 4観点に表示している指標だけを対象に算出)をJSON APIと共有する
 * ―― 画面の点数とレポートの点数が食い違うことがないようにするため
 * (2026-07-28のユーザー指摘への対応)。
 */
class ReportViewModelBuilder
{
    private const MAX_RECOMMENDATIONS = 5;

    // BrandWheelRadarSvgBuilderのviewBox(380x276)に対する2倍解像度。
    // ヘキサゴン用(380x316)とアスペクト比が異なるため、
    // BrandWheelHexagonRenderer::renderPng()へ明示的に渡す。
    private const RADAR_WIDTH_PX = 760;

    private const RADAR_HEIGHT_PX = 552;

    public function __construct(
        private readonly LeadScoreCalculator $scoreCalculator,
        private readonly ReportSummaryComposer $summaryComposer,
        private readonly LeadPerspectiveComposer $perspectiveComposer,
        private readonly HonorificNameFormatter $nameFormatter,
        private readonly RecommendationLabelFormatter $labelFormatter,
        private readonly LeadRecommendationComposer $recommendationComposer,
        // ブランド・ホイール(6軸)。JSON API(LeadAnalysisController)と同じ
        // Composerを共有する ―― 画面(旧)・レポートで判定ロジックが
        // 食い違わないようにするため(2026-08-03、画面から診断内容を
        // 外したことでレポートが6軸の唯一の配信経路になった)。
        private readonly BrandWheelLeadResponseComposer $brandWheelComposer,
        private readonly BrandWheelComparisonSummaryComposer $brandWheelSummaryComposer,
        // レーダー図(自社×競合を重ねた図)。SVGの組み立てはBrandWheel専用の
        // BrandWheelRadarSvgBuilder、PNG化は既存のBrandWheelHexagonRenderer
        // (rsvg-convertラッパー)をそのまま再利用する(2026-08-04、新しい
        // ラスタライズ経路は作らない方針)。
        private readonly BrandWheelRadarSvgBuilder $radarSvgBuilder,
        private readonly BrandWheelHexagonRenderer $pngRenderer,
        // 「24項目の対比」「サイトで触れられていなかった項目」ページの唯一の
        // 情報源(2026-08-04、docs/lead-report-layout/README.md「設計の要」)。
        private readonly BrandWheelSubElementComparisonComposer $subElementComparisonComposer,
        // 「改善提案」ページの領域選択・3項目選定(決定的な規則)。
        private readonly BrandWheelImprovementFocusComposer $improvementFocusComposer,
    ) {}

    public function build(Analysis $analysis, LeadSession $leadSession): ReportViewModel
    {
        $analysis->loadMissing([
            'websiteAnalyses.website',
            'websiteAnalyses.recommendations.metricResult.metricDefinition',
            'websiteAnalyses.metricResults.metricDefinition',
            // is_mock/status以外に絞る必要はない(1件のみ使うため軽量)。
            // latest('id')でwebsite_analysis_idあたり1件(最新)に絞る
            // (LeadAnalysisController::results()と同じ方針)。
            'websiteAnalyses.brandWheelAnalysisResults' => fn ($query) => $query->latest('id')->limit(1),
        ]);

        $activeDefinitions = MetricDefinition::query()->where('is_active', true)->get();

        $selfWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        $competitorWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => ! (bool) $wa->website?->is_primary);

        $selfScore = $this->scoreCalculator->calculate($activeDefinitions, $selfWebsiteAnalysis?->metricResults ?? collect());
        $selfScoreArray = $selfScore->toArray();

        $competitorScoreArray = null;
        $comparisonSentence = null;

        if ($competitorWebsiteAnalysis !== null) {
            $competitorScore = $this->scoreCalculator->calculate($activeDefinitions, $competitorWebsiteAnalysis->metricResults);
            $competitorScoreArray = $competitorScore->toArray();
            $comparisonSentence = $this->summaryComposer->composeComparisonSentence($selfScoreArray, $competitorScoreArray);
        }

        // title/descriptionはLeadRecommendationComposer経由(screen/JSON APIの
        // LeadAnalysisControllerと同一クラスを共有) ―― 画面だけ直して
        // レポートに技術用語が残る食い違いを作らないため(2026-08-03)。
        $topRecommendations = $this->recommendationComposer->compose(
            $selfWebsiteAnalysis?->recommendations ?? collect(),
            self::MAX_RECOMMENDATIONS,
        );

        $overallSummaryText = $this->summaryComposer->composeOverallSummary(
            $selfScoreArray,
            count($topRecommendations),
            $this->nameFormatter->format($leadSession->company_name),
        );

        // 個別指標名(items[*].label)はレポートのテンプレート側で一切出さない
        // 方針(2026-08-04)のため、見出し・判定バッジと併せて出す「理由1文」を
        // ここで1回だけ機械的に付与する。LeadPerspectiveComposer::compose()
        // 自体(JSON APIと共有)は変更しない ―― この配列はこのメソッド内で
        // 独立して計算したものであり、JSON APIのレスポンスには影響しない。
        $perspectives = array_map(
            fn (array $p) => $p + ['one_liner' => $this->summaryComposer->composePerspectiveOneLiner($p)],
            $this->perspectiveComposer->compose($selfWebsiteAnalysis?->metricResults ?? collect()),
        );

        $recommendationRows = array_values(array_map(fn (array $row) => new ReportRecommendationRow(
            title: $row['title'],
            description: $row['description'],
            priorityLabel: $this->labelFormatter->priorityLabel($row['recommendation']->priority),
            impactLabel: $this->labelFormatter->impactLabel($row['recommendation']->impact),
            effortLabel: $this->labelFormatter->effortLabel($row['recommendation']->effort),
        ), $topRecommendations));

        $selfBrandWheelRecord = $selfWebsiteAnalysis?->brandWheelAnalysisResults->first();
        $competitorBrandWheelRecord = $competitorWebsiteAnalysis?->brandWheelAnalysisResults->first();
        $brandWheelSelf = $selfWebsiteAnalysis !== null
            ? $this->brandWheelComposer->compose($selfBrandWheelRecord, $selfWebsiteAnalysis->website)
            : null;
        $brandWheelCompetitor = $competitorWebsiteAnalysis !== null
            ? $this->brandWheelComposer->compose($competitorBrandWheelRecord, $competitorWebsiteAnalysis->website)
            : null;

        $selfBrandWheelEvidenceItems = $this->buildSelfBrandWheelEvidenceItems($selfBrandWheelRecord, (array) ($brandWheelSelf['axes'] ?? []));

        // 判定ロジックはBrandWheelComparisonSummaryComposerのみに置く(ここでは
        // 呼び出すだけ) ―― 件数からの導出ルールを2箇所に持たない。
        $selfAxes = (array) ($brandWheelSelf['axes'] ?? []);
        $competitorAxes = (array) ($brandWheelCompetitor['axes'] ?? []);
        $selfReadable = ($brandWheelSelf['status'] ?? null) === 'success' && $selfAxes !== [];
        $competitorReadable = ($brandWheelCompetitor['status'] ?? null) === 'success' && $competitorAxes !== [];

        // 2026-08-04: 「他社ページ比較とのまとめ」ページを削除したため
        // competitor_pointsは廃止(所見が「24項目の対比」ページの言い換えに
        // なっていた、docs/lead-report-layout/README.md)。one_pointは
        // 「改善提案」ページ冒頭のワンポイントとして引き続き使う。
        $brandWheelComparison = [
            'self_points' => $this->brandWheelSummaryComposer->points($selfAxes),
            'one_point' => $this->brandWheelSummaryComposer->onePoint($selfAxes),
        ];

        // 「自社ページの分析結果」「24項目の対比」「改善提案」の3ページが
        // 必ず同じ値を参照するよう、合計件数はここで1回だけ集計する
        // (docs/lead-report-layout/README.md「合計件数Nは3・6・8ページ目で
        // 必ず同じソースから算出すること。別々に集計しないでください」)。
        $selfTotalMatched = array_sum(array_column($selfAxes, 'matched_count'));
        $selfTotalMax = array_sum(array_column($selfAxes, 'max_count'));
        $competitorTotalMatched = array_sum(array_column($competitorAxes, 'matched_count'));
        $competitorTotalMax = array_sum(array_column($competitorAxes, 'max_count'));

        // 「24項目の対比」「サイトで触れられていなかった項目」「改善提案」の
        // 唯一の情報源(2026-08-04)。
        $subElementComparison = $this->subElementComparisonComposer->compose($selfAxes, $competitorAxes);
        $gapAnalysis = $this->subElementComparisonComposer->splitByGap($subElementComparison);

        // 改善提案ページの3項目分だけ、比較サイトの実際のevidenceを渡す
        // (2026-08-04、README「その領域から3項目を、比較サイトの実際の
        // evidenceつきで提示する」への対応 ―― これまでの「競合サイトの本文を
        // レポートに出さない」方針からの意図的な例外。ViewModelへは
        // improvementFocusが選んだ最大3件分のevidenceしか渡らないため、
        // 競合サイトの本文を広く露出させるものではない)。
        $improvementFocus = $selfReadable && $competitorReadable
            ? $this->improvementFocusComposer->compose(
                $subElementComparison,
                $this->buildEvidenceByAxisAndSubKey($competitorBrandWheelRecord),
            )
            : null;

        $brandWheelRadarPng = $this->buildBrandWheelRadarPng($brandWheelSelf, $brandWheelCompetitor);

        return new ReportViewModel(
            companyDisplayName: $this->nameFormatter->format($leadSession->company_name),
            generatedAtLabel: sprintf('%d年%d月%d日', now()->year, now()->month, now()->day),
            selfWebsiteUrl: $selfWebsiteAnalysis?->website?->url ?? '',
            competitorWebsiteUrl: $competitorWebsiteAnalysis?->website?->url,
            selfScore: $selfScoreArray,
            competitorScore: $competitorScoreArray,
            overallSummaryText: $overallSummaryText,
            comparisonSentence: $comparisonSentence,
            perspectives: $perspectives,
            topRecommendations: $recommendationRows,
            isPartial: $analysis->status === AnalysisStatus::Partial,
            brandWheelSelf: $brandWheelSelf,
            brandWheelCompetitor: $brandWheelCompetitor,
            brandWheelComparison: $brandWheelComparison,
            brandWheelRadarPng: $brandWheelRadarPng,
            selfBrandWheelEvidenceItems: $selfBrandWheelEvidenceItems,
            selfTotalMatched: $selfTotalMatched,
            selfTotalMax: $selfTotalMax,
            competitorTotalMatched: $competitorTotalMatched,
            competitorTotalMax: $competitorTotalMax,
            subElementComparison: $subElementComparison,
            gapAnalysis: $gapAnalysis,
            improvementFocus: $improvementFocus,
        );
    }

    /**
     * 「サイトから読み取れた記述」ページ(evidence一覧)用に、自社のみ生の
     * BrandWheelAnalysisResult.axesからevidenceを取り出す。
     * BrandWheelLeadResponseComposer::compose()はJSON API(画面)向けの
     * 唯一の定義元であり、意図的にevidenceを含めない設計(2026-08-03、
     * 他社サイトの本文が外部に出ることを避けるため)のため、このメソッドは
     * そのComposerを変更せず、レポート生成のこの箇所だけで独立して
     * evidenceを組み立てる。競合側は呼ばない(競合サイトの本文をレポートに
     * 出さないため、既存の設計意図をそのまま踏襲する)。
     *
     * 軸・下位要素の順序と名称・グループは$selfWheelAxes(composerの出力、
     * config('brand_wheel.axes')の並び順・日本語名が既に解決済み)にそのまま
     * 従う ―― ここでは(axis_key, sub_element_key)をキーにevidence文字列を
     * 引き当てるだけで、順序・名称の判定ロジックを重複させない。
     *
     * @param  list<array<string, mixed>>  $selfWheelAxes  BrandWheelLeadResponseComposer::compose()['axes']
     * @return list<array{axis_key: string, axis_name: string, group: string, sub_element_name: string, evidence: string}>
     */
    private function buildSelfBrandWheelEvidenceItems(?\App\Models\BrandWheelAnalysisResult $rawSelfRecord, array $selfWheelAxes): array
    {
        if ($rawSelfRecord === null) {
            return [];
        }

        $evidenceByAxisAndSubKey = $this->buildEvidenceByAxisAndSubKey($rawSelfRecord);

        $items = [];
        foreach ($selfWheelAxes as $axis) {
            foreach ((array) ($axis['matched_sub_elements'] ?? []) as $sub) {
                $evidence = $evidenceByAxisAndSubKey[$axis['key']][$sub['key']] ?? '';

                if ($evidence === '') {
                    continue;
                }

                $items[] = [
                    'axis_key' => $axis['key'],
                    'axis_name' => $axis['name'],
                    'group' => $axis['group'],
                    'sub_element_name' => $sub['name'],
                    'evidence' => $evidence,
                ];
            }
        }

        return $items;
    }

    /**
     * 生のBrandWheelAnalysisResult.axesから(axis_key => (sub_element_key =>
     * evidence))のルックアップを組み立てる。self/competitorのどちらの
     * レコードにも使える共通処理として切り出したもの(2026-08-04)。
     *
     * このメソッド自体はレコードを受け取れば誰の分でも組み立てるが、
     * 呼び出し側の使い方には非対称な制約がある: 自社分は
     * buildSelfBrandWheelEvidenceItems()経由で「サイトから読み取れた記述」
     * ページ全体に使われる一方、競合分はbuild()内でimprovementFocusComposer
     * が選んだ最大3件にしか使われない(競合サイトの本文を広く露出させない、
     * という既存方針を保つため)。
     *
     * @return array<string, array<string, string>>
     */
    private function buildEvidenceByAxisAndSubKey(?\App\Models\BrandWheelAnalysisResult $record): array
    {
        if ($record === null) {
            return [];
        }

        $evidenceByAxisAndSubKey = [];
        foreach ((array) ($record->axes ?? []) as $axis) {
            foreach ((array) ($axis['matched_sub_elements'] ?? []) as $sub) {
                $evidenceByAxisAndSubKey[$axis['axis_key']][$sub['key']] = (string) ($sub['evidence'] ?? '');
            }
        }

        return $evidenceByAxisAndSubKey;
    }

    /**
     * 自社がstatus==='success'でない場合(6項目すべて0件の表を出すことが
     * 禁止なのと同じ理由)、そして万一ラスタライズ自体が失敗した場合は
     * nullを返す ―― 呼び出し側(Blade/WordReportGenerator)は図を省略し、
     * 軸ごとの件数の表だけで成立させる(既存メールと同じ方針、画像の失敗が
     * レポート生成全体を止めてはいけない)。
     *
     * @param  ?array<string, mixed>  $brandWheelSelf
     * @param  ?array<string, mixed>  $brandWheelCompetitor
     */
    private function buildBrandWheelRadarPng(?array $brandWheelSelf, ?array $brandWheelCompetitor): ?string
    {
        if (($brandWheelSelf['status'] ?? null) !== 'success' || ($brandWheelSelf['axes'] ?? []) === []) {
            return null;
        }

        $competitorAxes = ($brandWheelCompetitor['status'] ?? null) === 'success' && ($brandWheelCompetitor['axes'] ?? []) !== []
            ? $brandWheelCompetitor['axes']
            : null;

        $svg = $this->radarSvgBuilder->build($brandWheelSelf['axes'], $competitorAxes);

        return $this->pngRenderer->renderPng($svg, self::RADAR_WIDTH_PX, self::RADAR_HEIGHT_PX);
    }
}
