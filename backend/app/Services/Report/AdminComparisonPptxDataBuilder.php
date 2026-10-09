<?php

namespace App\Services\Report;

use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelMultiSiteComparisonComposer;
use App\Services\TopMessageInsight\TopMessageInsightStore;
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
    public function __construct(
        private readonly CandidateSurveyCatalog $surveyCatalog = new CandidateSurveyCatalog,
        private readonly ?TopMessageInsightStore $topMessageStore = null,
    ) {}

    /**
     * 依頼CQ-4: 「トップメッセージ × 人事制度」のページ(1社1ページ)のデータ。並びは自社 → 競合
     * (競合は入力順 = websites.display_order、MultiSiteReportViewModelBuilderと同じ)。
     * 結果のファイル(TopMessageInsightStore、サーバー側の確認を通ったものだけが入っている)を
     * 読むだけで、AIは呼ばない。
     *
     *  - status=created               → pagesに加える。
     *  - status=not_created           → 作られなかった会社として、missing_note(比較のページの注記1行)に載せる。
     *  - ファイルが無い(まだ/対象外)  → 何も出さない(作られなかったとは言い切れないため)。
     *
     * @return array{pages: list<array{company_name: string, quote: string, keywords: list<array{keyword: string, programs: list<array{name: string, detail: string}>}>, sources: list<string>}>, missing_note: ?string}
     */
    public function buildTopMessageData(Analysis $analysis, MultiSiteReportViewModel $viewModel): array
    {
        $analysis->loadMissing('websiteAnalyses.website');
        $store = $this->topMessageStore ?? app(TopMessageInsightStore::class);

        $self = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        $competitors = $analysis->websiteAnalyses
            ->filter(fn (WebsiteAnalysis $wa) => ! (bool) $wa->website?->is_primary)
            ->sortBy(fn (WebsiteAnalysis $wa) => $wa->website?->display_order ?? PHP_INT_MAX)
            ->values();

        $entries = [];
        if ($self !== null) {
            $entries[] = [$viewModel->selfCompanyDisplayName, $self];
        }
        foreach ($competitors as $index => $wa) {
            $entries[] = [(string) ($viewModel->competitors[$index]['name'] ?? $wa->website?->name ?? ''), $wa];
        }

        $pages = [];
        $missing = [];
        foreach ($entries as [$name, $wa]) {
            $result = $store->read((int) $wa->analysis_id, (int) $wa->id);
            if ($result === null) {
                continue;
            }

            $page = $this->topMessagePageFromResult($name, $result);
            if ($page !== null) {
                $pages[] = $page;
            } else {
                $missing[] = $name;
            }
        }

        return [
            'pages' => $pages,
            'missing_note' => $missing === []
                ? null
                : sprintf((string) config('admin_comparison_pptx.top_message_missing_note'), implode((string) config('admin_comparison_pptx.top_message_missing_note_separator'), $missing)),
        ];
    }

    /**
     * 結果のファイル(status=created)を、スライド1枚ぶんのデータにする。作られていない/壊れている
     * ときはnull。ここでは文章を作らない(ファイルにある値をそのまま渡す)。
     *
     * @param  array<string, mixed>  $result
     * @return ?array{company_name: string, quote: string, keywords: list<array{keyword: string, programs: list<array{name: string, detail: string}>}>, sources: list<string>}
     */
    public function topMessagePageFromResult(string $companyName, array $result): ?array
    {
        if (($result['status'] ?? null) !== 'created' || ! is_string($result['quote'] ?? null) || trim($result['quote']) === '') {
            return null;
        }

        $keywords = [];
        foreach ((array) ($result['keywords'] ?? []) as $keyword) {
            $programs = [];
            foreach ((array) ($keyword['programs'] ?? []) as $program) {
                if (is_string($program['name'] ?? null) && $program['name'] !== '') {
                    $programs[] = ['name' => $program['name'], 'detail' => (string) ($program['detail'] ?? '')];
                }
            }
            if (is_string($keyword['keyword'] ?? null) && $keyword['keyword'] !== '' && $programs !== []) {
                $keywords[] = ['keyword' => $keyword['keyword'], 'programs' => $programs];
            }
        }

        if ($keywords === []) {
            return null;
        }

        return [
            'company_name' => $companyName,
            'quote' => $result['quote'],
            'keywords' => $keywords,
            'sources' => array_values(array_filter(array_map(fn ($source) => is_string($source['title'] ?? null) ? $source['title'] : null, (array) ($result['sources'] ?? [])))),
        ];
    }

    /**
     * @return array{
     *     self_company_name: string,
     *     self_readable: bool,
     *     self_material_sufficient: bool,
     *     companies: list<array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}>,
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
     *     survey_comparison: array{rows: list<array{rank: int, key: string, name: string, percentage: float, self_state: string, mapped_count: int, self_matched_count: int, competitor_count: ?int}>, competitor_total: int, excluded_competitor_count: int},
     *     candidate_survey_source_note: string,
     *     recommended_site_flow_names: list<string>,
     *     recommended_site_flows: list<array{name: string, option: ?string}>,
     *     source_note: string,
     *     page_number: ?string,
     * }
     */
    public function build(MultiSiteReportViewModel $viewModel): array
    {
        $totalItems = count($viewModel->comparisonTable);

        $companies = [];
        $companies[] = [
            // 依頼CD-2: 自社の'total'は$viewModel->selfTotalMax(自社の
            // ブランド・ホイール判定が空のとき0になる値)ではなく、競合と
            // 同じ$totalItems(config('brand_wheel.axes')の項目数、常に24)を
            // 使う。同じスライドの下段マトリクス(buildAxisMatrix()、
            // 分母は必ずconfigのsub_elements件数=24から出す)と分母の
            // 出どころを1つに揃えることで、「総合0/0・表側0/4×6行」という
            // 食い違いが自社データの状態によらず構造的に起こらないようにする
            // (依頼者指定 ―― 原因を直せば自然に解消する場合でも、食い違いが
            // 起きうる構造そのものを潰すこと)。
            'name' => $viewModel->selfCompanyDisplayName,
            'matched' => $viewModel->selfTotalMatched,
            'total' => $totalItems,
            'is_self' => true,
            // 依頼CH-1b(2026-10-01): selfReadable(status不成立)とは独立の
            // 軸 ―― 既にMultiSiteReportViewModelBuilderが算出済みの値を
            // そのまま通すだけ(このクラスの既存方針、self_readableと同じ扱い)。
            'material_sufficient' => $viewModel->selfMaterialSufficient,
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
                'material_sufficient' => $viewModel->competitorsMaterialSufficient[$index] ?? true,
            ];
        }

        $axes = $this->buildAxisMatrix($viewModel->comparisonTable, count($viewModel->competitors));
        $missingItems = $this->buildMissingItems($viewModel->missingFromSelf, count($viewModel->competitors), $this->comparisonByName($viewModel));

        return [
            'self_company_name' => $viewModel->selfCompanyDisplayName,
            // 依頼CD-3: 自社のブランド・ホイール判定が実際には行えていない
            // (status!=='success'またはaxesが空)状態を、Generator側が
            // 「0/24」等の数字ではなく専用の文言として扱えるようにする。
            // 新しい判定ロジックをここで作らず、既にMultiSiteReportViewModel
            // Builderが算出済みのselfReadable(status==='success' &&
            // selfAxes!==[])をそのまま通すだけ(唯一の情報源を保つ、
            // このクラスの既存方針)。
            'self_readable' => $viewModel->selfReadable,
            // 依頼CH-1b(2026-10-01): 「足りないもの」スライド
            // (generateMissingItemsSlide())が、自社が材料不足のときも
            // self_readable===falseと同じ専用文言へ切り替えられるようにする。
            'self_material_sufficient' => $viewModel->selfMaterialSufficient,
            'companies' => $companies,
            'axes' => $axes,
            'missing_items' => $missingItems,
            'survey_comparison' => $this->buildSurveyComparison($viewModel),
            'candidate_survey_source_note' => (string) config('brand_wheel_candidate_survey.source_note'),
            'recommended_site_flow_names' => $this->buildRecommendedSiteFlowNames($viewModel->missingFromSelf),
            // 依頼CN-A3: 導線名と、それが対応する調査の選択肢(点線の枝から、実線の枝に
            // 同じ主題があるものを除くための照合用、RecommendedFlowFilter)。
            'recommended_site_flows' => $this->buildRecommendedSiteFlows($viewModel->missingFromSelf),
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
     * 依頼CO-2: candidate_survey.self_stateは、その調査の選択肢に対応する24項目全体の自社の状態
     * (表「求職者が知りたい情報と、自社サイト」と同じ計算 ―― selfStateOfOption())。項目の選び方・並び順は変えない。
     *
     * @param  list<array{axis_name: string, sub_name: string, competitor_matched_count: int}>  $missingFromSelf  件数降順で既に並んでいる(BrandWheelMultiSiteComparisonComposer::extractMissingFromSelf())
     * @param  array<string, array<string, mixed>>  $comparisonByName  comparisonByName()の戻り値
     * @return array{heading: string, empty_text: string, items: list<array{axis_name: string, sub_name: string, region: string, impact: string, candidate_survey: array{item: ?string, percentage: ?float, self_state: ?string}}>, others_count: int}
     */
    private function buildMissingItems(array $missingFromSelf, int $competitorCount, array $comparisonByName): array
    {
        $maxCount = (int) config('admin_comparison_pptx.missing_items_max_count');
        // 依頼CF-4: 超過時に実際に表示する件数を、$maxCount-1という暗黙の
        // 計算式ではなく、config側の別の値としてそのまま読む(config上の
        // 数値が実際の挙動と食い違わないようにする、config
        // ('admin_comparison_pptx.missing_items_overflow_display_count')
        // docblock参照)。
        $overflowDisplayCount = (int) config('admin_comparison_pptx.missing_items_overflow_display_count');

        $items = array_map(fn (array $item) => $this->enrichMissingItem($item['axis_name'], $item['sub_name'], $comparisonByName), $missingFromSelf);

        $othersCount = 0;
        if (count($items) > $maxCount) {
            $othersCount = count($items) - $overflowDisplayCount;
            $items = array_slice($items, 0, max(0, $overflowDisplayCount));
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
     * @param  array<string, array<string, mixed>>  $comparisonByName
     * @return array{axis_name: string, sub_name: string, region: string, impact: string, candidate_survey: array{item: ?string, percentage: ?float, self_state: ?string}}
     */
    private function enrichMissingItem(string $axisName, string $subName, array $comparisonByName): array
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
                'candidate_survey' => ['item' => null, 'percentage' => null, 'self_state' => null],
            ];
        }

        [$axisKey, $subKey] = $keys;
        $axisConfig = (array) config("brand_wheel.axes.{$axisKey}");
        $definition = (string) ($axisConfig['sub_element_definitions'][$subKey] ?? '');
        // 依頼CL-2: 調査の選択肢の名前・割合はoptions(1か所)から引く
        // (24項目の側には持たない)。「該当なし」はnull(従来と同じ)。
        $surveyOption = $this->surveyCatalog->optionForSubElement($axisKey, $subKey);

        return [
            'axis_name' => $axisName,
            'sub_name' => $subName,
            'region' => AdminComparisonPptxGenerator::regionName((string) ($axisConfig['group'] ?? '')),
            'impact' => sprintf((string) config('admin_comparison_pptx.missing_item_impact_template'), $definition),
            'candidate_survey' => [
                'item' => $surveyOption['name'] ?? null,
                'percentage' => $surveyOption['percentage'] ?? null,
                'self_state' => $surveyOption !== null ? $this->selfStateOfOption($surveyOption['key'], $comparisonByName)['state'] : null,
            ],
        ];
    }

    /**
     * 比較表(24項目)を「領域名::項目名」で引けるようにしたもの。
     *
     * @return array<string, array<string, mixed>>
     */
    private function comparisonByName(MultiSiteReportViewModel $viewModel): array
    {
        $byName = [];
        foreach ($viewModel->comparisonTable as $item) {
            $byName[$item['axis_name'].'::'.$item['sub_name']] = $item;
        }

        return $byName;
    }

    /**
     * 依頼CO-2: 調査の選択肢1つに対する自社の状態(対応する24項目のうち○の数で決める)の、
     * 唯一の計算。「足りないもの」の文と「求職者が知りたい情報と、自社サイト」の表の両方がこれを使う。
     *   すべて○=confirmed / 一部○=partial / すべて×=unconfirmed / 対応する24項目が無い=not_applicable。
     *
     * @param  array<string, array<string, mixed>>  $comparisonByName
     * @return array{state: string, mapped: int, self_matched: int, items: list<array<string, mixed>>} items=対応する24項目の比較表の行
     */
    private function selfStateOfOption(string $optionKey, array $comparisonByName): array
    {
        $mapped = 0;
        $selfMatched = 0;
        $items = [];
        foreach ($this->surveyCatalog->subElementsForOption($optionKey) as $ref) {
            $axisConfig = (array) config("brand_wheel.axes.{$ref['axis_key']}");
            $item = $comparisonByName[($axisConfig['name_ja'] ?? '').'::'.($axisConfig['sub_elements'][$ref['sub_key']] ?? '')] ?? null;
            if ($item === null) {
                continue;
            }

            $mapped++;
            $items[] = $item;
            if ($item['self_matched']) {
                $selfMatched++;
            }
        }

        $state = match (true) {
            $mapped === 0 => 'not_applicable',
            $selfMatched === $mapped => 'confirmed',
            $selfMatched === 0 => 'unconfirmed',
            default => 'partial',
        };

        return ['state' => $state, 'mapped' => $mapped, 'self_matched' => $selfMatched, 'items' => $items];
    }

    /**
     * 依頼CL-2(2026-10-05): 「求職者が知りたい情報と、自社サイト」。
     * 「足りないもの」(競合との比較で項目を選ぶ)とは逆に、調査の選択肢
     * (config('brand_wheel_candidate_survey.options')、割合の高い順)を軸に
     * 1行ずつ並べ、対応する24項目の判定を添える。判定そのものは
     * viewModel->comparisonTable(多社比較PDFと同じ唯一の情報源)から読み、
     * ここで新しい判定は行わない。
     *
     * 自社の状態(対応する24項目のうち○の数で決める):
     *   すべて○=confirmed / 一部○=partial / すべて×=unconfirmed /
     *   対応する24項目が無い=not_applicable。
     * 競合の掲載(competitor_count)は、対応する24項目のうち「1つでも○」の
     * 会社を数える(自社の状態の決め方とは数え方が違う ―― スライド側の
     * 注記で示す)。材料不足の競合(competitorsMaterialSufficient=false)は
     * 判定が信頼できないため、分子にも分母(competitor_total)にも含めず、
     * 除外した社数をexcluded_competitor_countで返す。
     *
     * @return array{
     *     rows: list<array{
     *         rank: int,
     *         key: string,
     *         name: string,
     *         percentage: float,
     *         self_state: string,
     *         mapped_count: int,
     *         self_matched_count: int,
     *         competitor_count: ?int,
     *     }>,
     *     competitor_total: int,
     *     excluded_competitor_count: int,
     * }
     */
    private function buildSurveyComparison(MultiSiteReportViewModel $viewModel): array
    {
        $comparable = [];
        foreach (array_keys($viewModel->competitors) as $index) {
            if ($viewModel->competitorsMaterialSufficient[$index] ?? true) {
                $comparable[] = $index;
            }
        }

        $byName = $this->comparisonByName($viewModel);

        $rows = [];
        foreach ($this->surveyCatalog->options() as $i => $option) {
            $own = $this->selfStateOfOption($option['key'], $byName);
            $mapped = $own['mapped'];
            $selfMatched = $own['self_matched'];
            $state = $own['state'];

            $competitorHasAny = array_fill_keys($comparable, false);
            foreach ($own['items'] as $item) {
                foreach ($comparable as $index) {
                    if ($item['competitor_matched'][$index] ?? false) {
                        $competitorHasAny[$index] = true;
                    }
                }
            }

            $rows[] = [
                'rank' => $i + 1,
                'key' => $option['key'],
                'name' => $option['name'],
                'percentage' => $option['percentage'],
                'self_state' => $state,
                'mapped_count' => $mapped,
                'self_matched_count' => $selfMatched,
                'competitor_count' => ($mapped === 0 || $comparable === []) ? null : count(array_filter($competitorHasAny)),
            ];
        }

        return [
            'rows' => $rows,
            'competitor_total' => count($comparable),
            'excluded_competitor_count' => count($viewModel->competitors) - count($comparable),
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
     * 依頼CN-A3: buildRecommendedSiteFlowNames()と同じ導線名(同じ順・重複除去)に、
     * その導線が対応する調査の選択肢のキー(対応しなければnull)を添える。
     *
     * @param  list<array{axis_name: string, sub_name: string}>  $missingFromSelf
     * @return list<array{name: string, option: ?string}>
     */
    private function buildRecommendedSiteFlows(array $missingFromSelf): array
    {
        $flows = [];
        $seen = [];
        foreach ($missingFromSelf as $item) {
            $keys = $this->resolveAxisSubKeys($item['axis_name'], $item['sub_name']);
            if ($keys === null) {
                continue;
            }
            [$axisKey, $subKey] = $keys;
            $siteFlowName = config("brand_wheel_candidate_survey.mapping.{$axisKey}.{$subKey}.site_flow_name");
            if (is_string($siteFlowName) && $siteFlowName !== '' && ! isset($seen[$siteFlowName])) {
                $seen[$siteFlowName] = true;
                $flows[] = ['name' => $siteFlowName, 'option' => $this->surveyCatalog->optionForSubElement($axisKey, $subKey)['key'] ?? null];
            }
        }

        return $flows;
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
