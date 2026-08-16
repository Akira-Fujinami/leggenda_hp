<?php

namespace App\Services\Report;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\LeadSession;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelComparisonSummaryComposer;
use App\Services\BrandWheel\BrandWheelEvidenceLookupBuilder;
use App\Services\BrandWheel\BrandWheelHexagonRenderer;
use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use App\Services\BrandWheel\BrandWheelRadarSvgBuilder;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use App\Support\Report\ReportViewModel;

/**
 * Analysis(+LeadSession)から、Word/PDF生成が共通で参照するReportViewModelを
 * 組み立てる。判定ロジックは既存のComposer群をそのまま呼び出すだけで、
 * 再実装は一切しない。
 *
 * 2026-08-08: レポートを7ページ構成へ再編し、内部の7カテゴリ/4観点
 * (LeadScoreCalculator/LeadPerspectiveComposer/LeadRecommendationComposer)
 * 由来のページ(4観点・技術的提案)をレポートから削除した(ユーザー指示)。
 * これらのComposer自体はJSON API(LeadAnalysisController)・画面向けに
 * 引き続き存在するが、レポート(このクラス)はもう参照しない。
 */
class ReportViewModelBuilder
{
    // BrandWheelRadarSvgBuilderのviewBox(380x276)に対する2倍解像度。
    // ヘキサゴン用(380x316)とアスペクト比が異なるため、
    // BrandWheelHexagonRenderer::renderPng()へ明示的に渡す。
    private const RADAR_WIDTH_PX = 760;

    private const RADAR_HEIGHT_PX = 552;

    public function __construct(
        private readonly HonorificNameFormatter $nameFormatter,
        // ブランド・ホイール(6軸)。JSON API(LeadAnalysisController)と同じ
        // Composerを共有する ―― 画面(旧)・レポートで判定ロジックが
        // 食い違わないようにするため(2026-08-03、画面から診断内容を
        // 外したことでレポートが6軸の唯一の配信経路になった)。
        private readonly BrandWheelLeadResponseComposer $brandWheelComposer,
        private readonly BrandWheelComparisonSummaryComposer $brandWheelSummaryComposer,
        // レーダー図。SVGの組み立てはBrandWheel専用のBrandWheelRadarSvgBuilder、
        // PNG化は既存のBrandWheelHexagonRenderer(rsvg-convertラッパー)を
        // そのまま再利用する(2026-08-04、新しいラスタライズ経路は作らない方針)。
        private readonly BrandWheelRadarSvgBuilder $radarSvgBuilder,
        private readonly BrandWheelHexagonRenderer $pngRenderer,
        // 「○△－対比表」ページの唯一の情報源(2026-08-04、
        // docs/lead-report-layout/README.md「設計の要」)。
        private readonly BrandWheelSubElementComparisonComposer $subElementComparisonComposer,
        // 「改善提案」ページの領域選択・3項目選定(決定的な規則)。
        private readonly BrandWheelImprovementFocusComposer $improvementFocusComposer,
        private readonly BrandWheelEvidenceLookupBuilder $evidenceLookupBuilder,
    ) {}

    public function build(Analysis $analysis, LeadSession $leadSession): ReportViewModel
    {
        $analysis->loadMissing([
            'websiteAnalyses.website',
            // is_mock/status以外に絞る必要はない(1件のみ使うため軽量)。
            // latest('id')でwebsite_analysis_idあたり1件(最新)に絞る
            // (LeadAnalysisController::results()と同じ方針)。
            'websiteAnalyses.brandWheelAnalysisResults' => fn ($query) => $query->latest('id')->limit(1),
        ]);

        $selfWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        $competitorWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => ! (bool) $wa->website?->is_primary);

        $selfBrandWheelRecord = $selfWebsiteAnalysis?->brandWheelAnalysisResults->first();
        $competitorBrandWheelRecord = $competitorWebsiteAnalysis?->brandWheelAnalysisResults->first();
        $brandWheelSelf = $selfWebsiteAnalysis !== null
            ? $this->brandWheelComposer->compose($selfBrandWheelRecord, $selfWebsiteAnalysis->website)
            : null;
        $brandWheelCompetitor = $competitorWebsiteAnalysis !== null
            ? $this->brandWheelComposer->compose($competitorBrandWheelRecord, $competitorWebsiteAnalysis->website)
            : null;

        // 判定ロジックはBrandWheelComparisonSummaryComposerのみに置く(ここでは
        // 呼び出すだけ) ―― 件数からの導出ルールを2箇所に持たない。
        $selfAxes = (array) ($brandWheelSelf['axes'] ?? []);
        $competitorAxes = (array) ($brandWheelCompetitor['axes'] ?? []);
        $selfReadable = ($brandWheelSelf['status'] ?? null) === 'success' && $selfAxes !== [];
        $competitorReadable = ($brandWheelCompetitor['status'] ?? null) === 'success' && $competitorAxes !== [];

        // 2026-08-08: 「競合サイトの分析結果」ページ新設に伴いcompetitor_points
        // を復活(2026-08-04時点では「他社ページ比較とのまとめ」ページ削除で
        // 廃止していたが、今回は自社ページと完全に同じ形式で競合単独ページを
        // 出すため、同じComposerを競合axesに対しても呼ぶだけで良い ―― AI呼び
        // 出しは増えない)。one_pointは「改善提案」ページ冒頭のワンポイントに
        // 引き続き使う。
        //
        // 2026-08-10: self_points/competitor_pointsはpointsForReport()を使う
        // (points()ではない)。0件の軸が複数ある場合に1行へまとめることで
        // 情報を1件も落とさずに箇条書きの行数を抑える(ユーザー指摘 ――
        // 前回入れた件数上限(最大4件、5件目以降を無言で切り捨て)は不要に
        // なったため廃止した。理由はBrandWheelComparisonSummaryComposer::
        // pointsForReport()のdocblock参照)。points()自体(JSON API/画面が
        // 共有)は無改修。
        $brandWheelComparison = [
            'self_points' => $this->brandWheelSummaryComposer->pointsForReport($selfAxes),
            'competitor_points' => $this->brandWheelSummaryComposer->pointsForReport($competitorAxes),
            'one_point' => $this->brandWheelSummaryComposer->onePoint($selfAxes),
        ];

        // 「自社ページの分析結果」「競合ページの分析結果」「○△－対比表」
        // 「改善提案」が必ず同じ値を参照するよう、合計件数はここで1回だけ
        // 集計する(docs/lead-report-layout/README.md「合計件数Nは必ず
        // 同じソースから算出すること。別々に集計しないでください」)。
        $selfTotalMatched = array_sum(array_column($selfAxes, 'matched_count'));
        $selfTotalMax = array_sum(array_column($selfAxes, 'max_count'));
        $competitorTotalMatched = array_sum(array_column($competitorAxes, 'matched_count'));
        $competitorTotalMax = array_sum(array_column($competitorAxes, 'max_count'));

        // △(見出し・リンクラベルのみ)の参考件数も同じソース(axes)から
        // 1回だけ集計する(2026-08-08、対比表の「(参考)」表示用。合計には
        // 含めない)。
        $selfTotalLabelOnly = array_sum(array_map(fn (array $a) => count($a['label_only_sub_elements'] ?? []), $selfAxes));
        $competitorTotalLabelOnly = array_sum(array_map(fn (array $a) => count($a['label_only_sub_elements'] ?? []), $competitorAxes));

        // 「○△－対比表」「改善提案」の唯一の情報源(2026-08-04)。
        $subElementComparison = $this->subElementComparisonComposer->compose($selfAxes, $competitorAxes);

        // 2026-08-17追加: 比較ページ冒頭の比較サマリー・グループ優劣バッジ。
        // 競合が読み取れない場合は意味を持たないため空配列にする(呼び出し側は
        // 空配列かどうかで表示可否を判断する)。
        $groupTotals = $competitorReadable ? $this->subElementComparisonComposer->groupTotals($subElementComparison) : [];
        $comparisonOverview = $selfReadable && $competitorReadable
            ? $this->brandWheelSummaryComposer->comparisonOverview($selfTotalMatched, $selfTotalMax, $competitorTotalMatched, $competitorTotalMax, $groupTotals)
            : [];

        // 改善提案ページの3項目分だけ、比較サイトの実際のevidenceを渡す
        // (2026-08-04、README「その領域から3項目を、比較サイトの実際の
        // evidenceつきで提示する」への対応 ―― これまでの「競合サイトの本文を
        // レポートに出さない」方針からの意図的な例外。ViewModelへは
        // improvementFocusが選んだ最大3件分のevidenceしか渡らないため、
        // 競合サイトの本文を広く露出させるものではない)。
        // 2026-08-17追加: 改善提案AI(GenerateBrandWheelImprovementSuggestionJob)の
        // 生成結果。まだ生成中/失敗している場合は$improvementSuggestionがnullの
        // ままとなり、ワンポイントは既存の決定的ロジック($comparison['one_point'])
        // にフォールバックする ―― AI呼び出しの失敗・遅延がレポート生成全体を
        // 止めてはいけない(既存のレーダーPNG生成失敗時と同じ方針)。
        $improvementSuggestion = BrandWheelImprovementSuggestion::query()
            ->where('analysis_id', $analysis->id)
            ->where('status', 'success')
            ->first();
        $improvementOnePoint = $improvementSuggestion?->one_point ?? ($brandWheelComparison['one_point']['text'] ?? null);
        $improvementRecommendation = $improvementSuggestion?->recommendation;

        $improvementFocus = $selfReadable && $competitorReadable
            ? $this->improvementFocusComposer->compose(
                $subElementComparison,
                $this->evidenceLookupBuilder->build($competitorBrandWheelRecord),
            )
            : null;

        // 2026-08-10: 競合が無い(または読み取れない)診断向けの改善提案
        // (ユーザー指示 ―― 「比較サイトが無いため、領域ごとの比較はご用意
        // できません。」の1行だけでページの大半が空白になる問題への対応)。
        // $improvementFocusが立つケース(自社・競合とも読み取れた)とは排他。
        $improvementFocusSelfOnly = $selfReadable && ! $competitorReadable
            ? $this->improvementFocusComposer->composeSelfOnly($subElementComparison)
            : null;

        return new ReportViewModel(
            companyDisplayName: $this->nameFormatter->format($leadSession->company_name),
            generatedAtLabel: sprintf('%d年%d月%d日', now()->year, now()->month, now()->day),
            selfWebsiteUrl: $selfWebsiteAnalysis?->website?->url ?? '',
            competitorWebsiteUrl: $competitorWebsiteAnalysis?->website?->url,
            isPartial: $analysis->status === AnalysisStatus::Partial,
            brandWheelSelf: $brandWheelSelf,
            brandWheelCompetitor: $brandWheelCompetitor,
            brandWheelComparison: $brandWheelComparison,
            brandWheelRadarPngSelf: $this->buildRadarPng($selfReadable ? $selfAxes : null),
            brandWheelRadarPngCompetitor: $this->buildRadarPng($competitorReadable ? $competitorAxes : null, color: BrandWheelRadarSvgBuilder::competitorColor()),
            brandWheelRadarPngComparison: $this->buildRadarPng($selfReadable ? $selfAxes : null, secondaryAxes: $competitorReadable ? $competitorAxes : null),
            selfTotalMatched: $selfTotalMatched,
            selfTotalMax: $selfTotalMax,
            competitorTotalMatched: $competitorTotalMatched,
            competitorTotalMax: $competitorTotalMax,
            selfTotalLabelOnly: $selfTotalLabelOnly,
            competitorTotalLabelOnly: $competitorTotalLabelOnly,
            subElementComparison: $subElementComparison,
            groupTotals: $groupTotals,
            comparisonOverview: $comparisonOverview,
            improvementFocus: $improvementFocus,
            improvementFocusSelfOnly: $improvementFocusSelfOnly,
            improvementOnePoint: $improvementOnePoint,
            improvementRecommendation: $improvementRecommendation,
        );
    }

    /**
     * $axesがnull(=呼び出し側で該当サイトがstatus!=='success'/未readableと
     * 判定済み)、または万一ラスタライズ自体が失敗した場合はnullを返す ――
     * 呼び出し側(Blade/WordReportGenerator)は図を省略し、軸ごとの件数の表
     * だけで成立させる(既存メールと同じ方針、画像の失敗がレポート生成全体を
     * 止めてはいけない)。
     *
     * 2026-08-08: 自社単独(3ページ目)・競合単独(4ページ目)・自社×競合の
     * 重ね図(対比表ページ)の3種類をこの1メソッドで生成する
     * (BrandWheelRadarSvgBuilder::build()自体は無改修 ―― $primaryColorを
     * 追加しただけ)。競合単独ページは$axesに競合のaxesを渡した上で
     * $colorに競合色(オレンジ)を指定し、「常に自社色(青)で描かれる」
     * 不具合を避ける。
     *
     * @param  ?list<array{key: string, name: string, matched_count: int, max_count: int}>  $axes  グリッド・主系列。呼び出し側がreadableと判断したものだけ渡す
     * @param  ?list<array{key: string, name: string, matched_count: int, max_count: int}>  $secondaryAxes  重ねる2系列目(対比表ページのみ指定)
     */
    private function buildRadarPng(?array $axes, ?array $secondaryAxes = null, ?string $color = null): ?string
    {
        if ($axes === null || $axes === []) {
            return null;
        }

        $svg = $this->radarSvgBuilder->build($axes, $secondaryAxes, $color ?? BrandWheelRadarSvgBuilder::selfColor());

        return $this->pngRenderer->renderPng($svg, self::RADAR_WIDTH_PX, self::RADAR_HEIGHT_PX);
    }
}
