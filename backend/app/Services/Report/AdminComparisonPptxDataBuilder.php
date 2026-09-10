<?php

namespace App\Services\Report;

use App\Support\Report\MultiSiteReportViewModel;

/**
 * 依頼BG: 既存のMultiSiteReportViewModel(多社比較PDFと同じ唯一の情報源、
 * MultiSiteReportViewModelBuilder参照)を、AdminComparisonPptxGenerator::
 * generate()が期待するデータ形へ変換するだけの薄い変換層。判定・集計
 * ロジックは一切持たない ―― PDF/PPTXが異なる数値を見せることが無いよう、
 * 既存のViewModelが持つ値をそのまま渡す。
 *
 * 依頼BM(2026-09-09): 「競合が伝えていて自社が伝えていない項目」の件数に
 * 依存する構成(該当0件だと下2/3が白紙になる、総合が同点だと前ページの
 * 流れと矛盾する)を、実データで確認して作り直した。6領域×各社のマトリクス
 * (常に埋まる)へ切り替え、他社サイトの引用文(missingFromSelf由来)は
 * 一切取得しない(依頼BM-4 ―― 表示を止めるだけでなく、取得処理自体を
 * 呼ばない)。MultiSiteReportViewModel.missingFromSelfそのものは多社比較PDF
 * (対象外、依頼者指定)がまだ使うため変更しない。
 *
 * 依頼BO-1(2026-09-09): まとめの帯の下に空く余白が「薄い」との指摘を受け、
 * 「競合が伝えていて自社が伝えていない項目」の項目名一覧を戻した。
 * viewModel.missingFromSelf(件数降順で既に並んでいる)からaxis_name/
 * sub_nameの2フィールドだけを読む ―― quote/quote_translation/
 * representative_company_name/definition/recommendationは一切読まない
 * (依頼BM-4で止めた引用取得の復活を防ぐ、依頼者指定)。
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
     *     summary: string,
     *     missing_items: array{
     *         heading: string,
     *         empty_text: string,
     *         items: list<array{axis_name: string, sub_name: string}>,
     *         others_count: int,
     *     },
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
        $summary = $this->buildSummary($companies, $axes);
        $missingItems = $this->buildMissingItems($viewModel->missingFromSelf);

        return [
            'self_company_name' => $viewModel->selfCompanyDisplayName,
            'companies' => $companies,
            'axes' => $axes,
            'summary' => $summary,
            'missing_items' => $missingItems,
            'source_note' => "Leggenda 採用ブランド・ホイール診断({$viewModel->generatedAtLabel}時点)",
            'page_number' => null,
        ];
    }

    /**
     * 依頼BO-1: 上限(missing_items_max_count)は「これ以上は出さない」という
     * 天井であり、実際に何件描画できるかはAdminComparisonPptxGenerator側で
     * まとめの帯の実際の行数(可変)から動的に計算する(このクラスはレイアウト
     * を一切知らない、依頼BM由来の役割分担を維持)。ここでは上限を超えた分を
     * 「ほかN件」1件に畳んで、Generatorが常に「項目N件+ほか1件」以下の
     * 固定件数だけを受け取れば済むようにする。
     *
     * @param  list<array{axis_name: string, sub_name: string, competitor_matched_count: int}>  $missingFromSelf  件数降順で既に並んでいる(BrandWheelMultiSiteComparisonComposer::extractMissingFromSelf())
     * @return array{heading: string, empty_text: string, items: list<array{axis_name: string, sub_name: string}>, others_count: int}
     */
    private function buildMissingItems(array $missingFromSelf): array
    {
        $maxCount = (int) config('admin_comparison_pptx.missing_items_max_count');

        $items = array_map(fn (array $item) => [
            'axis_name' => $item['axis_name'],
            'sub_name' => $item['sub_name'],
        ], $missingFromSelf);

        $othersCount = 0;
        if (count($items) > $maxCount) {
            $othersCount = count($items) - ($maxCount - 1);
            $items = array_slice($items, 0, max(0, $maxCount - 1));
        }

        return [
            'heading' => (string) config('admin_comparison_pptx.missing_items_heading'),
            'empty_text' => (string) config('admin_comparison_pptx.missing_items_empty_text'),
            'items' => $items,
            'others_count' => $othersCount,
        ];
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

    /**
     * 依頼BM-2で導入、依頼BN-1(2026-09-09)で全面的に書き直した。まとめの
     * 帯を、網かけ件数・総合順位から機械的に組み立てる(固定文にしない、
     * 依頼者指定)。2文構成にする(総合の文+領域の文) ―― 読点でつないだ
     * 1文だと、終止形へ読点を続ける不自然な文になっていた(実機画像化で
     * 発覚、依頼BN-1の報告参照)。領域名は表に既に出ているaxis_captions
     * を繰り返さず、名前も「と」でつなげず「」を隣接させる。件数が
     * gap_axis_list_thresholdを超えるときは名前を列挙せず件数だけ述べ、
     * 6領域全てが該当するときは専用の文言(6領域すべて)にする。
     *
     * @param  list<array{name: string, matched: int, total: int, is_self: bool}>  $companies
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     */
    private function buildSummary(array $companies, array $axes): string
    {
        $selfTotal = $companies[0]['matched'];
        $competitorTotals = array_column(array_slice($companies, 1), 'matched');
        $maxCompetitorTotal = $competitorTotals === [] ? 0 : max($competitorTotals);

        $templates = (array) config('admin_comparison_pptx.summary_templates');

        $isBehind = $selfTotal < $maxCompetitorTotal;
        $rankSentence = match (true) {
            $selfTotal > $maxCompetitorTotal => $templates['total_rank_ahead'],
            $selfTotal === $maxCompetitorTotal => $templates['total_rank_tied'],
            default => $templates['total_rank_behind'],
        };

        $gapAxes = array_values(array_filter($axes, fn (array $axis) => $axis['self_gap']));

        if ($gapAxes === []) {
            return $rankSentence.$templates['no_gap_axes'];
        }

        if (count($gapAxes) === count($axes)) {
            return $rankSentence.$templates['gap_axes_all'];
        }

        $listThreshold = (int) config('admin_comparison_pptx.gap_axis_list_threshold');

        if (count($gapAxes) <= $listThreshold) {
            $namesJoined = implode('', array_map(fn (array $axis) => "「{$axis['name']}」", $gapAxes));
            $template = $isBehind ? $templates['gap_axes_named_behind'] : $templates['gap_axes_named_other'];

            return $rankSentence.sprintf($template, $namesJoined, count($gapAxes));
        }

        $template = $isBehind ? $templates['gap_axes_unnamed_behind'] : $templates['gap_axes_unnamed_other'];

        return $rankSentence.sprintf($template, count($gapAxes));
    }
}
