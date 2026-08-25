<?php

namespace App\Services\Report;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\LeadSession;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelComparisonSufficiency;
use App\Services\BrandWheel\BrandWheelComparisonSummaryComposer;
use App\Services\BrandWheel\BrandWheelEvidenceLookupBuilder;
use App\Services\BrandWheel\BrandWheelHexagonRenderer;
use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use App\Services\BrandWheel\BrandWheelRadarSvgBuilder;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use App\Services\BrandWheel\BrandWheelTextTruncator;
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
        // 自社/競合それぞれの合計matched件数が、比較・個別提案の根拠として
        // 十分な情報量かどうかの判定(2026-08-25追加、修正1〜3・5)。
        private readonly BrandWheelComparisonSufficiency $comparisonSufficiency,
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

        // 2026-08-25追加: 自社/競合それぞれの合計matched件数が、比較・個別
        // 提案の根拠として十分な情報量かどうか(修正1〜3・5)。「読み取れたか
        // どうか」($selfReadable/$competitorReadable、既存)とは別の判定 ――
        // 1件でも読み取れれば$readableはtrueになるが、その1件を根拠に比較や
        // 優劣判定を組み立てるのは不適切なため。
        $selfSufficient = $this->comparisonSufficiency->isSufficient($selfTotalMatched);
        $competitorSufficient = $this->comparisonSufficiency->isSufficient($competitorTotalMatched);

        // 「○△－対比表」「改善提案」の唯一の情報源(2026-08-04)。
        $subElementComparison = $this->subElementComparisonComposer->compose($selfAxes, $competitorAxes);

        // 依頼R(2026-08-26): 「○と判定した根拠」ページ(○△－の対比表の
        // 直後)の唯一の情報源。自社のBrandWheelAnalysisResult.axes(生の
        // matched_sub_elements、evidence=原文の抜粋を含む)から組み立てる ――
        // $brandWheelSelf['axes']は既にBrandWheelLeadResponseComposerが
        // evidenceを剥がした後の値のため使えない(2026-08-03の非開示方針、
        // 画面向け)。$evidenceLookupBuilderは既に改善提案ページの競合引用
        // カード用に存在していた仕組みをそのまま流用する(新しい仕組みを
        // 作らない)。競合側は一切呼ばない(競合サイトの引用を載せない、
        // 依頼者指定)。discarded_sub_elements(evidence_not_found等で棄却
        // された引用)はevidenceLookupBuilderがそもそもmatched_sub_elements
        // しか読まないため、参照する余地が無い。
        $selfEvidenceByAxis = $this->buildSelfEvidenceByAxis(
            $subElementComparison,
            $this->evidenceLookupBuilder->build($selfBrandWheelRecord),
        );

        // 2026-08-17追加: 比較ページ冒頭の比較サマリー・グループ優劣バッジ。
        // 競合が読み取れない場合は意味を持たないため空配列にする(呼び出し側は
        // 空配列かどうかで表示可否を判断する)。
        // 2026-08-25追加: 自社・競合のいずれかが閾値未満のときも同様に空配列
        // とする(修正3 ―― 例えば1件対1件のような薄いデータ同士を「同程度」
        // と判定して見せるのは、実態は「両方とも読めていないだけ」であり
        // 不適切なため)。
        $groupTotals = $competitorReadable && $selfSufficient && $competitorSufficient
            ? $this->subElementComparisonComposer->groupTotals($subElementComparison)
            : [];
        $comparisonOverview = $selfReadable && $competitorReadable && $selfSufficient && $competitorSufficient
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
        // 2026-08-25追加: 自社が閾値未満のときは、AIが生成したone_pointが
        // あっても使わず、config('brand_wheel.one_point_messages.insufficient_content')
        // を直接使う(修正2、依頼者指定)。既存のBrandWheelComparisonSummaryComposer::
        // onePoint()はzero_axes_min_count(軸単位、既定2)という別の閾値で
        // 判定しており、必ずしもinsufficient_contentを返すとは限らないため、
        // ここではその結果を経由せず直接参照する。
        $improvementOnePoint = $selfSufficient
            ? ($improvementSuggestion?->one_point ?? ($brandWheelComparison['one_point']['text'] ?? null))
            : (string) config('brand_wheel.one_point_messages.insufficient_content');
        // 2026-08-18追加: 「情報が不足しているので追加してください」という
        // 一般論から脱却させるための構造化フィールド(依頼者指定の表示構成
        // ワンポイント→理由→自社と競合の差(既存)→具体的に追加すべき情報→
        // 中長期施策に対応)。AI未生成/失敗時はすべてnull/空配列のままとなり、
        // Blade/WordReportGenerator側は該当ブロックを出さないだけで、既存の
        // グループ差バー・証拠カードは無条件に表示され続ける。
        // 2026-08-25追加: 自社が閾値未満のときは、個別項目に基づく提案
        // (旧recommendation/reason/recommended_contents/mid_term_action)を
        // 一律で出さない(修正2)。GenerateBrandWheelImprovementSuggestionJob
        // 側でも自社が閾値未満のときはAIを呼ばずこれらをnull/空配列のまま
        // 保存するが、ここでも同じ判定をかけることで、閾値導入前に生成済みの
        // BrandWheelImprovementSuggestion行が残っていても営業資料として
        // 不適切な個別提案を出さない。
        $improvementRecommendation = $selfSufficient ? $improvementSuggestion?->recommendation : null;
        $improvementReason = $selfSufficient ? $improvementSuggestion?->reason : null;
        $improvementRecommendedContents = $selfSufficient ? ($improvementSuggestion?->recommended_contents ?? []) : [];
        $improvementMidTermAction = $selfSufficient ? $improvementSuggestion?->mid_term_action : null;

        // 2026-08-25追加: 競合が閾値未満のときはcompose()を使わない(修正1)。
        // 「競合の該当件数の合計 - 自社の該当件数の合計」が最大の領域を選ぶ
        // compose()の計算は、競合が1件しか読み取れていない場合もそのまま
        // 走ってしまい、その1件のnoiseを根拠に比較サイトの引用付き提案が
        // 組み立てられる(実物レポート32で確認された不具合)。
        $improvementFocus = $selfReadable && $competitorReadable && $competitorSufficient
            ? $this->improvementFocusComposer->compose(
                $subElementComparison,
                $this->evidenceLookupBuilder->build($competitorBrandWheelRecord),
            )
            : null;

        // 2026-08-10: 競合が無い(または読み取れない)診断向けの改善提案
        // (ユーザー指示 ―― 「比較サイトが無いため、領域ごとの比較はご用意
        // できません。」の1行だけでページの大半が空白になる問題への対応)。
        // $improvementFocusが立つケース(自社・競合とも読み取れかつ十分な
        // 情報量)とは排他。
        // 2026-08-25追加: 競合が閾値未満の場合もこちら(自社単独の改善提案)へ
        // フォールバックする(修正1)。
        //
        // 依頼Q-2(2026-08-25): composeSelfOnly()自体(グループ選定・items選定
        // 規則)は無改修 ―― ここでは$groups(棒グラフ用の数値、規則のまま)は
        // そのまま使い、$items(3枚のカード)だけをAI(改善提案AI、
        // focus_sub_element_keys)由来のものに差し替える。理由: レポート35で
        // AI由来のワンポイント/理由と、規則由来の「最も少なかったのは〜」+
        // 3枚のカードが同時に描画され、領域が食い違って見える不具合が
        // あった(1ページに2つの推奨が並ぶ状態)。AI(サイト固有の着手
        // しやすさ判断ができる)を主、規則(数値の棒グラフ)を従にする方針
        // (依頼者指定)。$selfSufficient=falseのとき(GenerateBrandWheelImprovement
        // SuggestionJobがAIを呼ばずfocus_sub_element_keysが空のまま保存される
        // ケース、修正2)や、AIの提案がまだ生成されていない/失敗している場合は
        // 差し替える材料が無いため、従来どおり規則由来の$itemsのまま
        // (items_source='rule')にフォールバックする ―― 「誤ったカードを
        // 出すくらいなら規則由来のほうがマシ」という依頼者方針どおり、
        // AI由来の有効なカードが1件も作れない場合は必ず規則側にフォール
        // バックする(buildAiSelfOnlyFocusItems()参照)。
        $improvementFocusSelfOnly = $selfReadable && (! $competitorReadable || ! $competitorSufficient)
            ? $this->improvementFocusComposer->composeSelfOnly($subElementComparison)
            : null;

        if ($improvementFocusSelfOnly !== null) {
            $aiItems = $selfSufficient
                ? $this->buildAiSelfOnlyFocusItems($subElementComparison, $improvementSuggestion?->focus_sub_element_keys ?? [])
                : [];

            if ($aiItems !== []) {
                $improvementFocusSelfOnly['items'] = $aiItems;
                $improvementFocusSelfOnly['items_source'] = 'ai';
            } else {
                $improvementFocusSelfOnly['items_source'] = 'rule';
            }
        }

        // 2026-08-25追加: 自社が閾値未満のときの但し書き(修正5)。サイトを
        // 責める表現("情報が無い")ではなく、こちら側の読み取り量の問題として
        // 書く(依頼者指定の文言、config('brand_wheel.self_low_content_notice')、
        // 原文ママ)。
        //
        // 依頼O-1/依頼P-2(2026-08-25): この文言は元々「本文が少なかった」と
        // 主張するが、判定材料は$selfSufficient(matched件数)であって本文の
        // 文字数ではなかった。本文は十分あったのに該当項目が少なかっただけの
        // ケース(レポート34: 本文23,935字→17,945字に切り詰め、matched=5/24)を
        // 「本文が少ない」と誤って断定しないよう、実際の入力文字数
        // (brand_wheel_analysis_results.input_char_count)を根拠に分岐する
        // (詳細はresolveSelfLowContentNotice()のdocblock参照)。
        $selfLowContentNotice = $this->resolveSelfLowContentNotice(
            $selfSufficient,
            (bool) ($selfBrandWheelRecord->input_truncated ?? false),
            $selfBrandWheelRecord->input_char_count ?? null,
        );

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
            improvementReason: $improvementReason,
            improvementRecommendedContents: $improvementRecommendedContents,
            improvementMidTermAction: $improvementMidTermAction,
            selfLowContentNotice: $selfLowContentNotice,
            crawlSiteEnabled: $analysis->crawl_site === true,
            selfEvidenceByAxis: $selfEvidenceByAxis,
        );
    }

    /**
     * 依頼R(2026-08-26): 「○と判定した根拠」ページの唯一の情報源。
     * $subElementComparison(対比表と同じ軸順・下位要素順)を先頭から走査し、
     * self_matched===trueの項目についてだけ、$selfEvidenceLookup
     * (axis_key => sub_key => evidence、BrandWheelEvidenceLookupBuilder::
     * build()の戻り値)からevidenceを引く。evidenceが空文字(trim後)の項目は
     * その項目ごと含めない(空の引用符だけが並ぶ状態を作らない、依頼者指定)。
     * config('brand_wheel.evidence_page_quote_max_chars')でBrandWheelText
     * Truncator::truncateAtSentenceBoundary()(既存、文の途中で切らない)
     * により切り詰める ―― 要約・言い換えは一切行わない(原文の一部をそのまま
     * 削るだけ)。
     *
     * 軸は最初に登場した項目の順で並び、1件も無い軸はキー自体が存在しない
     * ため出力に含まれない(空の軸見出しを作らない)。matched=0件、または
     * 全項目のevidenceが空文字の場合は空配列を返し、呼び出し側(Blade/
     * WordReportGenerator)はこの場合ページ自体を出さない。
     *
     * @param  list<array{axis_key: string, axis_name: string, group: string, sub_key: string, sub_name: string, definition: string, recommendation: string, self_matched: bool, competitor_matched: bool, self_state: string, competitor_state: string}>  $subElementComparison
     * @param  array<string, array<string, string>>  $selfEvidenceLookup
     * @return list<array{axis_name: string, items: list<array{sub_name: string, evidence: string}>}>
     */
    private function buildSelfEvidenceByAxis(array $subElementComparison, array $selfEvidenceLookup): array
    {
        $maxChars = (int) config('brand_wheel.evidence_page_quote_max_chars');

        $byAxisKey = [];
        foreach ($subElementComparison as $item) {
            if (! $item['self_matched']) {
                continue;
            }

            $evidence = trim((string) ($selfEvidenceLookup[$item['axis_key']][$item['sub_key']] ?? ''));

            if ($evidence === '') {
                continue;
            }

            $byAxisKey[$item['axis_key']]['axis_name'] = $item['axis_name'];
            $byAxisKey[$item['axis_key']]['items'][] = [
                'sub_name' => $item['sub_name'],
                'evidence' => BrandWheelTextTruncator::truncateAtSentenceBoundary($evidence, $maxChars),
            ];
        }

        return array_values($byAxisKey);
    }

    /**
     * 依頼Q-2(2026-08-25): 改善提案ページ(自社単独モード)の3枚のカードを、
     * 改善提案AI(BrandWheelImprovementSuggestion.focus_sub_element_keys)が
     * 挙げた下位要素キーから組み立てる。カードの文面自体は
     * BrandWheelImprovementFocusComposer::composeSelfOnly()と同じ情報源
     * (config('brand_wheel.axes.*.sub_element_recommendations')、
     * $subElementComparisonの'recommendation')を使う ―― AIは「どの3項目を
     * 選ぶか」だけを判断し、文面自体は既存の決定的カタログのまま
     * (依頼者指定: sub_element_recommendationsの文面をそのまま使う)。
     *
     * 二重の安全策で「誤ったカードを出さない」ことを保証する:
     * 1. focus_sub_element_keys自体、AIの応答パーサ
     *    (BrandWheelImprovementSuggestionResponseParser::parseSubElementKeyList())
     *    が実在する24キー以外を既に除外済み(捏造キーの防止)。
     * 2. ここでさらに、$subElementComparison上でself_state==='matched'
     *    (自社に既にある)キーを除外する ―― プロンプト側(v7)でAIに
     *    self_unmatched_itemsのみを選ばせる指示を追加したが、指示に従わない
     *    場合の最後の防波堤として、呼び出し側でも機械的に検証する。
     *
     * 有効なキーが1件も残らない場合は空配列を返す ―― 呼び出し側
     * (build())はこの場合、規則由来(composeSelfOnly()の元のitems)に
     * フォールバックする。「AIの選定が信頼できないなら、規則由来だけの
     * ほうがマシ」という依頼者方針をコードでそのまま表現している。
     *
     * @param  list<array{axis_key: string, axis_name: string, group: string, sub_key: string, sub_name: string, definition: string, recommendation: string, self_matched: bool, competitor_matched: bool, self_state: string, competitor_state: string}>  $subElementComparison
     * @param  list<string>  $focusSubElementKeys
     * @return list<array{axis_name: string, sub_name: string, definition: string, recommendation: string, self_reason: string}>
     */
    private function buildAiSelfOnlyFocusItems(array $subElementComparison, array $focusSubElementKeys): array
    {
        $bySubKey = collect($subElementComparison)->keyBy('sub_key');

        $items = [];
        foreach (array_slice($focusSubElementKeys, 0, 3) as $key) {
            $item = $bySubKey->get($key);

            if ($item === null || $item['self_state'] === 'matched') {
                continue;
            }

            $items[] = [
                'axis_name' => $item['axis_name'],
                'sub_name' => $item['sub_name'],
                'definition' => $item['definition'],
                'recommendation' => $item['recommendation'],
                // self_state('none'|'label_only'、'matched'は上で除外済み)を
                // そのままself_reasonとして使う ――
                // BrandWheelImprovementFocusComposer::composeSelfOnly()の
                // 出力形式と一致させ、Blade/WordReportGenerator側の
                // 表示ロジック(selfOnlyReasonLabel等)を無改修で共有できる
                // ようにする。
                'self_reason' => $item['self_state'],
            ];
        }

        return $items;
    }

    /**
     * 依頼P-2(2026-08-25、依頼Oの続き): matched件数が閾値未満のときに
     * 何を出すかの分岐。
     *
     * - matched件数が閾値以上: 何も出さない(従来どおり)。
     * - matched未満 かつ input_truncated=true: 予算(AI_MAX_INPUT_TOKENS)
     *   上限まで本文があった証拠のため、本文は十分あったのに該当項目が
     *   少なかっただけ ―― (b)。
     * - matched未満 かつ input_char_count(実際の入力文字数)が
     *   config('brand_wheel.self_low_content_notice_min_chars')以上:
     *   同じく本文は十分あった ―― (b)。
     * - matched未満 かつ input_char_count がその閾値未満: 実際に本文が
     *   少なかった ―― (a)(従来の文言のまま)。
     * - matched未満 かつ input_char_count が null(旧データ・入力組み立て
     *   失敗等で判定材料が無い): 何も出さない。判定材料が無いときに推測で
     *   「本文が少なかった」と断定しない(レポート34の誤りの再発防止、
     *   依頼者指定)。
     */
    private function resolveSelfLowContentNotice(bool $selfSufficient, bool $selfInputTruncated, ?int $selfInputCharCount): ?string
    {
        if ($selfSufficient) {
            return null;
        }

        if ($selfInputTruncated) {
            return (string) config('brand_wheel.self_low_content_notice_thin_match');
        }

        if ($selfInputCharCount === null) {
            return null;
        }

        $minChars = (int) config('brand_wheel.self_low_content_notice_min_chars');

        return $selfInputCharCount >= $minChars
            ? (string) config('brand_wheel.self_low_content_notice_thin_match')
            : (string) config('brand_wheel.self_low_content_notice');
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
