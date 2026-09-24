<?php

namespace App\Services\Report;

use App\Services\BrandWheel\BrandWheelMultiSiteComparisonComposer;
use App\Support\Report\MultiSiteReportViewModel;

/**
 * 依頼BG: 既存のMultiSiteReportViewModel(多社比較PDFと同じ唯一の情報源、
 * MultiSiteReportViewModelBuilder参照)を、AdminComparisonPptxGeneratorが
 * 期待するデータ形へ変換するだけの薄い変換層。判定・集計ロジックは一切
 * 持たない ―― PDF/PPTXが異なる数値を見せることが無いよう、既存の
 * ViewModelが持つ値をそのまま渡す。
 *
 * 依頼BM(2026-09-09): 「競合が伝えていて自社が伝えていない項目」の件数に
 * 依存する構成(該当0件だと下2/3が白紙になる、総合が同点だと前ページの
 * 流れと矛盾する)を、実データで確認して作り直した。6領域×各社のマトリクス
 * (常に埋まる)へ切り替え、他社サイトの引用文(missingFromSelf由来)は
 * 一切取得しない(依頼BM-4 ―― 表示を止めるだけでなく、取得処理自体を
 * 呼ばない)。MultiSiteReportViewModel.missingFromSelfそのものは多社比較PDF
 * (対象外、依頼者指定)がまだ使うため変更しない。
 *
 * 依頼BO-1(2026-09-09): 「競合が伝えていて自社が伝えていない項目」の項目名
 * 一覧を追加した。viewModel.missingFromSelf(件数降順で既に並んでいる)から
 * axis_name/sub_nameの2フィールドだけを読む ―― quote/quote_translation/
 * representative_company_nameは一切読まない(依頼BM-4で止めた競合引用の
 * 復活を防ぐ、依頼者指定)。definition/recommendationも読まない ――
 * 同名の情報が必要な場合(依頼CB-2)は、missingFromSelf経由ではなく
 * config('brand_wheel.axes')から直接引く(下記buildMissingItems()参照)。
 *
 * 依頼BQ-1(2026-09-11): 抽出条件(BrandWheelMultiSiteComparisonComposer::
 * extractMissingFromSelf())は「競合の少なくとも1社」ではなく「競合の
 * 過半数」だが、見出しの文言がそれを表していなかった(調査で判明、依頼BQの
 * 背景参照)。見出しに「競合N社中M社以上」を出すようにした ―― M は
 * majorityThreshold()から算出し、このクラス側に計算式を複製しない。
 *
 * 依頼CB-4(2026-09-24): 差し込みを「説明→比較→足りないもの→階層図→
 * 参照元」の4枚構成に作り直した。旧「6領域マトリクス+まとめの帯」の
 * 比較スライド(依頼BM〜BQ)はブランド・ホイール比較スライド(CB-1、
 * ヘキサゴン)に置き換わったため、その専用データだった buildSummary()・
 * 'summary'キーを削除した(companies/axesは既存のままCB-1が再利用する
 * ―― 新しい集計を作らない、依頼者指定)。missing_itemsには、CB-2
 * 「足りないもの」スライドが必要とする領域名・一文・候補者調査の対応
 * (config('brand_wheel_candidate_survey'))を追加した。
 */
class AdminComparisonPptxDataBuilder
{
    /**
     * @return array{
     *     self_company_name: string,
     *     companies: list<array{name: string, matched: int, total: int, is_self: bool}>,
     *     axes: list<array{
     *         name: string,
     *         caption: ?string,
     *         denominator: int,
     *         self_count: int,
     *         competitor_counts: list<int>,
     *         self_gap: bool,
     *     }>,
     *     missing_items: array{
     *         heading: string,
     *         empty_text: string,
     *         items: list<array{
     *             axis_name: string,
     *             sub_name: string,
     *             region: string,
     *             impact: string,
     *             candidate_survey: array{item: ?string, percentage: ?float},
     *         }>,
     *         others_count: int,
     *     },
     *     candidate_survey_source_note: string,
     *     recommended_site_flow_names: list<string>,
     *     source_note: string,
     *     page_number: ?string,
     * }
     */
    public function build(MultiSiteReportViewModel $viewModel): array
    {
        $totalItems = count($viewModel->comparisonTable);

        $companies = [];
        $companies[] = [
            'name' => $viewModel->selfCompanyDisplayName,
            'matched' => $viewModel->selfTotalMatched,
            'total' => $viewModel->selfTotalMax,
            'is_self' => true,
        ];

        foreach ($viewModel->competitors as $index => $competitor) {
            $matched = 0;
            foreach ($viewModel->comparisonTable as $item) {
                if ($item['competitor_matched'][$index] ?? false) {
                    $matched++;
                }
            }

            $companies[] = [
                'name' => $competitor['name'],
                'matched' => $matched,
                'total' => $totalItems,
                'is_self' => false,
            ];
        }

        $axes = $this->buildAxisMatrix($viewModel->comparisonTable, count($viewModel->competitors));
        $missingItems = $this->buildMissingItems($viewModel->missingFromSelf, count($viewModel->competitors));

        return [
            'self_company_name' => $viewModel->selfCompanyDisplayName,
            'companies' => $companies,
            'axes' => $axes,
            'missing_items' => $missingItems,
            'candidate_survey_source_note' => (string) config('brand_wheel_candidate_survey.source_note'),
            'recommended_site_flow_names' => $this->buildRecommendedSiteFlowNames($viewModel->missingFromSelf),
            'source_note' => "Leggenda 採用ブランド・ホイール診断({$viewModel->generatedAtLabel}時点)",
            'page_number' => null,
        ];
    }

    /**
     * 依頼BO-1: 上限(missing_items_max_count)は「これ以上は出さない」という
     * 天井。超えた分は「ほかN件」1件に畳んで、Generatorが常に「項目N件+
     * ほか1件」以下の固定件数だけを受け取れば済むようにする。
     *
     * 依頼BQ-1(2026-09-11): 見出しに「競合N社中M社以上」の具体的な数字を
     * 出す。M(過半数の人数)は、抽出条件そのものである
     * BrandWheelMultiSiteComparisonComposer::majorityThreshold()から算出する
     * (この依頼では同クラスを変更しないが、既存の公開メソッドを呼ぶのは
     * 「定義を1箇所に保つ」という既存方針に沿うため問題ない ―― 計算式を
     * このクラス側に複製すると、将来どちらか片方だけ変更されて定義が
     * 割れる恐れがある)。
     *
     * 依頼CB-2(2026-09-24): 「足りないもの」スライドの各行に、項目名・
     * sub_name以外に3つを追加した。いずれもmissingFromSelf側の
     * definition/recommendation/quote系フィールドは読まず(依頼BM-4を
     * 維持)、axis_name/sub_nameからconfig('brand_wheel.axes')・
     * config('brand_wheel_candidate_survey')を逆引きして得る:
     *   - region: 3領域の区分名(会社の魅力/会社との距離/仕事の魅力)。
     *     AdminComparisonPptxGenerator::regionName()(依頼BZ-1の
     *     EXPLANATION_REGIONSを再利用、新しい3領域表をここに複製しない)。
     *   - impact: 「伝わっていないと何が起きるか」の一文。
     *     config('brand_wheel.axes.*.sub_element_definitions')(その項目の
     *     定義、既存の確定済み文言)を
     *     admin_comparison_pptx.missing_item_impact_templateへ埋め込む。
     *   - candidate_survey: 対応する候補者調査の項目名・割合
     *     (config('brand_wheel_candidate_survey.mapping')。「該当なし」の
     *     項目はitem/percentageともnull ―― 数字を捏造しない、依頼者指定)。
     *
     * @param  list<array{axis_name: string, sub_name: string, competitor_matched_count: int}>  $missingFromSelf  件数降順で既に並んでいる(BrandWheelMultiSiteComparisonComposer::extractMissingFromSelf())
     * @return array{heading: string, empty_text: string, items: list<array{axis_name: string, sub_name: string, region: string, impact: string, candidate_survey: array{item: ?string, percentage: ?float}}>, others_count: int}
     */
    private function buildMissingItems(array $missingFromSelf, int $competitorCount): array
    {
        $maxCount = (int) config('admin_comparison_pptx.missing_items_max_count');

        $items = array_map(fn (array $item) => $this->enrichMissingItem($item['axis_name'], $item['sub_name']), $missingFromSelf);

        $othersCount = 0;
        if (count($items) > $maxCount) {
            $othersCount = count($items) - ($maxCount - 1);
            $items = array_slice($items, 0, max(0, $maxCount - 1));
        }

        $majorityThreshold = (new BrandWheelMultiSiteComparisonComposer)->majorityThreshold($competitorCount);
        $heading = sprintf((string) config('admin_comparison_pptx.missing_items_heading'), $competitorCount, $majorityThreshold);

        return [
            'heading' => $heading,
            'empty_text' => (string) config('admin_comparison_pptx.missing_items_empty_text'),
            'items' => $items,
            'others_count' => $othersCount,
        ];
    }

    /**
     * @return array{axis_name: string, sub_name: string, region: string, impact: string, candidate_survey: array{item: ?string, percentage: ?float}}
     */
    private function enrichMissingItem(string $axisName, string $subName): array
    {
        $keys = $this->resolveAxisSubKeys($axisName, $subName);
        if ($keys === null) {
            // config('brand_wheel.axes')に無い組み合わせ(理論上到達しない
            // ―― missingFromSelf自体がconfig('brand_wheel.axes')の順で
            // 組み立てられているため)。フォールバックとして空欄にする
            // (捏造しない・例外で全体を落とさない、既存方針)。
            return [
                'axis_name' => $axisName,
                'sub_name' => $subName,
                'region' => '',
                'impact' => '',
                'candidate_survey' => ['item' => null, 'percentage' => null],
            ];
        }

        [$axisKey, $subKey] = $keys;
        $axisConfig = (array) config("brand_wheel.axes.{$axisKey}");
        $definition = (string) ($axisConfig['sub_element_definitions'][$subKey] ?? '');
        $mapping = (array) config("brand_wheel_candidate_survey.mapping.{$axisKey}.{$subKey}", []);

        return [
            'axis_name' => $axisName,
            'sub_name' => $subName,
            'region' => AdminComparisonPptxGenerator::regionName((string) ($axisConfig['group'] ?? '')),
            'impact' => sprintf((string) config('admin_comparison_pptx.missing_item_impact_template'), $definition),
            'candidate_survey' => [
                'item' => $mapping['survey_item'] ?? null,
                'percentage' => isset($mapping['percentage']) ? (float) $mapping['percentage'] : null,
            ],
        ];
    }

    /**
     * 依頼CB-3: 「足りないもの」(CB-2の表示上限より前、missingFromSelf
     * 全件)に対応するサイトの導線名を、重複を除いて出現順に返す。
     * config('brand_wheel_candidate_survey.mapping.*.site_flow_name')が
     * null(該当なし)の項目は含めない ―― 存在しない導線名を「推奨」として
     * 出さないため。
     *
     * @param  list<array{axis_name: string, sub_name: string}>  $missingFromSelf
     * @return list<string>
     */
    private function buildRecommendedSiteFlowNames(array $missingFromSelf): array
    {
        $names = [];
        foreach ($missingFromSelf as $item) {
            $keys = $this->resolveAxisSubKeys($item['axis_name'], $item['sub_name']);
            if ($keys === null) {
                continue;
            }
            [$axisKey, $subKey] = $keys;
            $siteFlowName = config("brand_wheel_candidate_survey.mapping.{$axisKey}.{$subKey}.site_flow_name");
            if (is_string($siteFlowName) && $siteFlowName !== '' && ! in_array($siteFlowName, $names, true)) {
                $names[] = $siteFlowName;
            }
        }

        return $names;
    }

    /**
     * axis_name(name_ja)・sub_name(表示名)から、config('brand_wheel.axes')の
     * axis_key/sub_keyを逆引きする。BrandWheelMultiSiteComparisonComposer::
     * compose()の出力(MultiSiteReportViewModel::missingFromSelf)は
     * axis_key/sub_key自体を持つが、依頼BM-4の設計判断によりこのクラスは
     * その配列からはaxis_name/sub_nameの2フィールドしか読まない
     * (buildMissingItems()のdocblock参照) ―― そのため、config側を
     * name_ja/表示名で逆引きする。
     *
     * @return array{0: string, 1: string}|null  [axisKey, subKey]
     */
    private function resolveAxisSubKeys(string $axisName, string $subName): ?array
    {
        foreach ((array) config('brand_wheel.axes') as $axisKey => $axisConfig) {
            if (($axisConfig['name_ja'] ?? null) !== $axisName) {
                continue;
            }
            foreach ((array) ($axisConfig['sub_elements'] ?? []) as $subKey => $name) {
                if ($name === $subName) {
                    return [$axisKey, $subKey];
                }
            }
        }

        return null;
    }

    /**
     * 依頼BM-1: 領域はconfig('brand_wheel.axes')の並び・name_jaを使う
     * (コードに直書きしない)。分母はその領域のsub_elements件数から出す
     * (24÷6=4を直書きしない)。comparisonTable(24項目×自社+競合N社、
     * axis_name一致で領域ごとに集計)自体はMultiSiteReportViewModelBuilderが
     * 既にconfig('brand_wheel.axes')の順で構築しているが、この集計では
     * config側を主として回り、comparisonTable側から該当領域の項目を
     * 抽出する形にしてある ―― 分母をcomparisonTable側の件数から数えるの
     * ではなく、必ずconfigのsub_elements件数から出すため(依頼者指定)。
     *
     * @param  list<array{axis_name: string, group: string, sub_name: string, self_matched: bool, competitor_matched: list<bool>}>  $comparisonTable
     * @return list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>
     */
    private function buildAxisMatrix(array $comparisonTable, int $competitorCount): array
    {
        $captions = (array) config('admin_comparison_pptx.axis_captions', []);
        $axes = [];

        foreach ((array) config('brand_wheel.axes') as $axisKey => $axisConfig) {
            $nameJa = (string) $axisConfig['name_ja'];
            $denominator = count((array) $axisConfig['sub_elements']);
            $items = array_values(array_filter(
                $comparisonTable,
                fn (array $item) => $item['axis_name'] === $nameJa,
            ));

            $selfCount = count(array_filter($items, fn (array $item) => $item['self_matched']));

            $competitorCounts = [];
            for ($i = 0; $i < $competitorCount; $i++) {
                $competitorCounts[] = count(array_filter(
                    $items,
                    fn (array $item) => $item['competitor_matched'][$i] ?? false,
                ));
            }

            $maxCompetitorCount = $competitorCounts === [] ? 0 : max($competitorCounts);

            $axes[] = [
                'name' => $nameJa,
                'caption' => $captions[$axisKey] ?? null,
                'denominator' => $denominator,
                'self_count' => $selfCount,
                'competitor_counts' => $competitorCounts,
                // 依頼BM-2: 自社が「その領域の競合の最高値を下回る」場合
                // にのみ網かける。同値は網かけしない(依頼者指定)。
                'self_gap' => $selfCount < $maxCompetitorCount,
            ];
        }

        return $axes;
    }
}
