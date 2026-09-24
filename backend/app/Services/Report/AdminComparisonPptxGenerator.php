<?php

namespace App\Services\Report;

use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\AutoShape;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Border;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Writer\PowerPoint2007;

/**
 * 依頼AT(2026-09-03)の検証用スパイク実装から発展。座標・色は共有された
 * 比較スライド_モックアップ.pptxのXMLを実測した値をベースに、依頼BMで
 * 「6領域×各社のマトリクス」構成へ作り直した(comparison_slide_v2.html)。
 *
 * 依頼BZ(2026-09-15): 差し込みが比較ページ1枚だけだと、ブランド・ホイールを
 * 知らない商談相手には「活動的魅力」等の軸名が何を指すか伝わらない
 * (依頼者指摘、実物のPPTXを確認して判明)。説明ページ
 * (generateExplanationSlide())を追加した。説明ページの内容はlead-pdf.
 * blade.php「採用ブランドの捉え方 ―― ブランド・ホイール」ページと同じ
 * 情報源(config('brand_wheel.axes.*')・axis_unread_caveat)を使い、2つの
 * 資料が同じ枠組みについて違うことを言わないようにする ―― 3領域の区分・
 * 一文説明はlead-pdf.blade.phpの該当箇所をそのまま踏襲する(config化
 * されていないため直書きだが、由来は同じ、EXPLANATION_REGIONS)。画像
 * (brand-wheel-framework.png)は使わない ―― extractSingleSlide()がr:embed
 * 等の外部参照を検知して差し込みを拒否するため、画像パーツの追加は行わない
 * (依頼者指定)。分析結果に依存しない固定内容のため、会社名・スコアは
 * 一切載せない。
 *
 * 依頼CB-4(2026-09-24): 差し込みを「説明→比較→足りないもの→階層図→
 * 参照元」の4枚構成に作り直した。旧「6領域×各社のマトリクス+まとめの帯」
 * (依頼BM〜BQ、旧generate()の内容)は、商談で使った結果「何が足りないか」が
 * 伝わらないとの指摘を受け、以下の3枚に置き換わった(依頼者指定):
 *   - generate(): ブランド・ホイール比較(各社のヘキサゴン+領域別の数値表、
 *     旧マトリクス表は「絵だけで数字が分からない状態にしない」ための
 *     数値表としてそのまま流用し、ヘキサゴンの下に小さく配置し直した)
 *   - generateMissingItemsSlide(): 「足りないもの」(旧まとめの帯・
 *     addSummaryAndMissingItemsCard・buildSummary()は削除。候補者調査の
 *     対応(config('brand_wheel_candidate_survey'))を新たに載せる)
 *   - generateSiteHierarchySlide(): 自社サイトの階層図(新規。
 *     AdminComparisonSiteHierarchyBuilderが渡すデータをそのまま描画する
 *     だけで、集計はこのクラスでは行わない)
 * 抽出条件(過半数)・24項目/6軸の定義は変更していない。
 *
 * 【差し込みの前提、依頼BK/BL/BG由来・変更禁止】
 * - スライドサイズは12192000×6858000EMU固定(setCXにUNIT_INCHで13.333を
 *   渡すと丸め誤差で不正なXMLになるため、EMUを直接指定する)。
 * - schemeClr(テーマ色)を使わない。色は全てColor()経由のsrgbClr(明示RGB)。
 * - フォントはMeiryoを明示指定する(font()参照)。
 * - 画像・グラフ・埋め込みオブジェクトを使わない
 *   (AdminComparisonPptxInserter::extractSingleSlide()が
 *   r:id/r:embed/r:linkの出現を検知して差し込みを中止する)。ヘキサゴン
 *   (依頼CB-1)・階層図(依頼CB-3)は、PhpOffice\PhpPresentationがcustGeom
 *   (任意多角形の塗り)を書き出せないため、Shape\Line(p:cxnSp、r:id等の
 *   外部参照を持たない直線コネクタ)を6本つないで多角形の輪郭を描く
 *   ―― 塗りつぶしは行わず、線のみ(依頼者指定の制約内で「多角形・矩形・
 *   直線で描けば足りる」を満たす)。
 */
class AdminComparisonPptxGenerator
{
    private const PX_PER_INCH = 96;

    private const SLIDE_WIDTH_IN = 13.333;

    private const SLIDE_HEIGHT_IN = 7.5;

    private const LEFT_IN = 0.9;

    private const CONTENT_WIDTH_IN = 11.5;

    private const NAVY = '12243F';

    private const COPPER = 'C8763C';

    private const BODY_TEXT = '0C1726';

    private const MUTED = '5A6B82';

    private const DIM = '9AA6B4';

    private const RULE = 'D9DFE7';

    private const BAND = 'FAFBFC';

    private const WHITE = 'FFFFFF';

    private const SELF_TINT = 'EEF1F5';

    private const GAP_BG = 'FBEEE3';

    private const GAP_TEXT = 'A85B1E';

    // ------------------------------------------------------------------
    // 依頼CB-1: ブランド・ホイール比較(各社のヘキサゴン)。
    // ------------------------------------------------------------------

    /** 自社ヘキサゴンの名前・総合点ラベルの上端。 */
    private const WHEEL_SELF_LABEL_TOP_IN = 1.5;

    private const WHEEL_SELF_NAME_HEIGHT_IN = 0.32;

    private const WHEEL_SELF_SCORE_HEIGHT_IN = 0.22;

    /**
     * 0.7inにすると、自社ラベル(0.54in)+ヘキサゴン(直径1.4in)+競合行
     * (ラベル0.42in+直径0.8in)+領域別数値表(見出し0.28in+ヘッダー0.24in+
     * 6行×0.24in=1.44in)の合計がfooterの出典行(y=6.62in)ちょうどに達し、
     * 余白ゼロで接してしまう(競合社数に関わらず高さの合計は一定 ――
     * 競合行は横幅だけがN社で変わり、縦の高さは変わらないため)。0.62inに
     * 縮め、footerまで約0.16inの余白を持たせる(実機画像化で確認)。
     */
    private const WHEEL_SELF_RADIUS_IN = 0.62;

    private const WHEEL_SELF_TILE_WIDTH_IN = 3.6;

    /** 自社ヘキサゴンと競合ヘキサゴン列との縦の間隔。 */
    private const WHEEL_ROW_GAP_IN = 0.14;

    private const WHEEL_COMPETITOR_NAME_HEIGHT_IN = 0.24;

    private const WHEEL_COMPETITOR_SCORE_HEIGHT_IN = 0.18;

    private const WHEEL_COMPETITOR_RADIUS_IN = 0.4;

    /** ヘキサゴン列と、その下の領域別数値表との間隔。 */
    private const WHEEL_TABLE_GAP_IN = 0.14;

    // ------------------------------------------------------------------
    // 依頼CB-1: 領域別の数値表(旧「領域別の発信量」マトリクス、依頼BM〜BQ)。
    // ヘキサゴンの下に小さく置く凡例として引き続き使う
    // (「絵だけで数字が分からない状態にしない」、CB-1必須要件)。
    // ------------------------------------------------------------------

    /** ヘッダー行の高さ。社名は1行運用にする(表を小さくするため2行は許さない)。 */
    private const TABLE_HEADER_HEIGHT_IN = 0.24;

    private const TABLE_ROW_HEIGHT_IN = 0.24;

    private const AREA_COL_WIDTH_IN = 2.4;

    // ------------------------------------------------------------------
    // 依頼CB-2: 「足りないもの」スライド。
    // ------------------------------------------------------------------

    private const MISSING_HEADING_TOP_IN = 1.5;

    private const MISSING_ROWS_TOP_IN = 1.9;

    /**
     * 1件あたりの高さ(項目名+領域タグ0.2in/一文(最大2行)0.28in/候補者調査行
     * 0.14in、計0.62in)。missing_items_max_count(既定6)×(0.62+0.08)を
     * MISSING_ROWS_TOP_INに足しても、footer(citation、6.42in開始)より
     * 上で終わる(1.9+6×0.7=6.1in)ことを実機画像化で確認した。
     */
    private const MISSING_ROW_HEIGHT_IN = 0.62;

    private const MISSING_ROW_GAP_IN = 0.08;

    // ------------------------------------------------------------------
    // 依頼CB-3: 「自社サイトの階層図」スライド。
    // ------------------------------------------------------------------

    private const HIERARCHY_ORIGIN_TOP_IN = 1.5;

    private const HIERARCHY_BRANCHES_TOP_IN = 2.05;

    private const HIERARCHY_ROW_HEIGHT_IN = 0.42;

    private const HIERARCHY_ROW_GAP_IN = 0.06;

    private const HIERARCHY_RECOMMENDED_GAP_IN = 0.18;

    // ------------------------------------------------------------------
    // 依頼BZ-1: 説明ページ(ブランド・ホイールの前置き)。分析結果に依存
    // しない固定レイアウトのため、比較スライドのような可変高計算は行わない
    // ―― 一度収まる値を決めれば実データによって再びあふれることはない
    // (lead-pdf.blade.phpの前置きページと同じ考え方、同ファイルのコメント
    // 参照)。
    // ------------------------------------------------------------------

    private const EXPL_LEAD_TOP_IN = 1.5;

    private const EXPL_GROUPS_TOP_IN = 1.85;

    private const EXPL_HEADER_HEIGHT_IN = 0.4;

    private const EXPL_DESC_HEIGHT_IN = 0.5;

    private const EXPL_COMPOSITION_TOP_IN = 4.95;

    private const EXPL_CAVEAT_TOP_IN = 5.55;

    /**
     * 3領域の区分・色・一文説明。lead-pdf.blade.php「採用ブランドの捉え方
     * ―― ブランド・ホイール」ページ(grouptbl)の直書き文言と、依頼者確定の
     * 配色(#1D2088/#2C7F96/#C03A28)をそのまま使う ―― config('brand_wheel')
     * には3領域そのもののレコードが無い(groupはaxes.*.groupの値としてのみ
     * 存在する)ため、blade側と同じ直書きにする。
     *
     * @var list<array{group: string, name: string, description: string, color: string}>
     */
    private const EXPLANATION_REGIONS = [
        ['group' => 'company_appeal', 'name' => '会社の魅力', 'description' => 'その会社が何を目指し、どれだけの実績・規模を持っているか。', 'color' => '1D2088'],
        ['group' => 'company_distance', 'name' => '会社との距離', 'description' => 'どんな経営で、どんな人たちが、どんな環境で働いているか。', 'color' => '2C7F96'],
        ['group' => 'job_appeal', 'name' => '仕事の魅力', 'description' => 'その仕事に就くと、何が得られるか。', 'color' => 'C03A28'],
    ];

    /**
     * 依頼CB-1(2026-09-24): ブランド・ホイール比較。自社を大きく、競合を
     * 小さく並べたヘキサゴン(6軸レーダー、外周=4/4)+その下に領域別の
     * 数値表(旧マトリクスを縮小して流用)。値は既存の$data['companies']/
     * $data['axes'](AdminComparisonPptxDataBuilderが既に計算済み)を
     * そのまま使う ―― ここで新しい集計は行わない(依頼者指定)。
     *
     * @param  array{
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
     *     source_note: string,
     *     page_number: ?string,
     * } $data
     */
    public function generate(array $data): string
    {
        return $this->renderSingleSlide(function (Slide $slide) use ($data): void {
            $this->addKicker($slide);
            $this->addTitle($slide, 'ブランド・ホイール比較');
            $tableTop = $this->addWheelHexagons($slide, $data['companies'], $data['axes']);
            $this->addMatrixSection($slide, $data['companies'], $data['axes'], $tableTop);
            $this->addFooter($slide, $data['source_note'], $data['page_number']);
        });
    }

    /**
     * @param  list<array{name: string, matched: int, total: int, is_self: bool}>  $companies
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     * @return float  この下に描く領域別数値表の上端y(in)
     */
    private function addWheelHexagons(Slide $slide, array $companies, array $axes): float
    {
        $selfCompany = $companies[0];
        $selfCx = self::LEFT_IN + self::CONTENT_WIDTH_IN / 2;

        $selfHexTop = $this->addWheelCompanyTile(
            $slide, $selfCompany, $axes, null, $selfCx,
            self::WHEEL_SELF_LABEL_TOP_IN, self::WHEEL_SELF_TILE_WIDTH_IN,
            self::WHEEL_SELF_NAME_HEIGHT_IN, self::WHEEL_SELF_SCORE_HEIGHT_IN, self::WHEEL_SELF_RADIUS_IN,
            true,
        );
        $selfHexBottom = $selfHexTop + 2 * self::WHEEL_SELF_RADIUS_IN;

        $competitors = array_slice($companies, 1);
        $n = count($competitors);
        $tileWidth = $n > 0 ? self::CONTENT_WIDTH_IN / $n : self::CONTENT_WIDTH_IN;
        $rowTop = $selfHexBottom + self::WHEEL_ROW_GAP_IN;

        $competitorHexBottom = $rowTop;
        foreach ($competitors as $i => $company) {
            $cx = self::LEFT_IN + $tileWidth * $i + $tileWidth / 2;
            $hexTop = $this->addWheelCompanyTile(
                $slide, $company, $axes, $i, $cx,
                $rowTop, $tileWidth - 0.1,
                self::WHEEL_COMPETITOR_NAME_HEIGHT_IN, self::WHEEL_COMPETITOR_SCORE_HEIGHT_IN, self::WHEEL_COMPETITOR_RADIUS_IN,
                false,
            );
            $competitorHexBottom = max($competitorHexBottom, $hexTop + 2 * self::WHEEL_COMPETITOR_RADIUS_IN);
        }

        return $competitorHexBottom + self::WHEEL_TABLE_GAP_IN;
    }

    /**
     * 1社ぶんの[名前+総合点ラベル]と、その下のヘキサゴン(依頼CB-1)を描く。
     * 社名の折り返しは依頼BN由来のwrapOrEllipsizeForLines/
     * renderBalancedLines(mb_strwidth基準)をそのまま使う(新しい折り返し
     * 処理を作らない、依頼者指定)。
     *
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     * @return float  ヘキサゴンの上端y(in)
     */
    private function addWheelCompanyTile(
        Slide $slide,
        array $company,
        array $axes,
        ?int $competitorIndex,
        float $cx,
        float $labelTop,
        float $tileWidth,
        float $nameHeight,
        float $scoreHeight,
        float $radius,
        bool $isSelf,
    ): float {
        $nameSize = $isSelf ? 11.0 : 8.0;
        $scoreSize = $isSelf ? 13.0 : 9.5;
        $left = $cx - $tileWidth / 2;

        $nameBox = $slide->createRichTextShape();
        $this->position($nameBox, $left, $labelTop, $tileWidth, $nameHeight);
        $nameBox->setWrap(RichText::WRAP_SQUARE);
        $nameBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $nameText = $this->wrapOrEllipsizeForLines($company['name'], $tileWidth, $nameSize, true, 2);
        $this->renderBalancedLines($nameBox->getActiveParagraph(), $nameText, $tileWidth, $nameSize, true, $isSelf ? self::NAVY : self::MUTED);

        $scoreTop = $labelTop + $nameHeight;
        $scoreBox = $slide->createRichTextShape();
        $this->position($scoreBox, $left, $scoreTop, $tileWidth, $scoreHeight);
        $scoreBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $scoreRun = $scoreBox->getActiveParagraph()->createTextRun("{$company['matched']} / {$company['total']}");
        $this->font($scoreRun, $scoreSize, true, $isSelf ? self::COPPER : self::MUTED);

        $hexTop = $scoreTop + $scoreHeight;
        $hexCenterY = $hexTop + $radius;

        $axisCounts = array_map(fn (array $axis) => [
            $isSelf ? $axis['self_count'] : ($axis['competitor_counts'][$competitorIndex] ?? 0),
            $axis['denominator'],
        ], $axes);

        $this->drawBrandWheelHexagon($slide, $cx, $hexCenterY, $radius, $axisCounts, $isSelf ? self::NAVY : self::COPPER, $isSelf ? 2.0 : 1.0);

        return $hexTop;
    }

    /**
     * 依頼CB-1: 6軸のヘキサゴン(レーダー図)。外周(薄いグレー、半径=$radius)
     * =4項目すべて確認できた状態、内側の実線が実際の値
     * (半径=$radius×count/denominator)。PhpOffice\PhpPresentationは
     * 任意多角形の塗り(custGeom)を書き出せないため、Shape\Line(直線の
     * コネクタ、r:id等の外部参照を持たない)を6本つないで輪郭を描く
     * ―― 塗りつぶしはしない。
     *
     * @param  list<array{0: int, 1: int}>  $axisCounts  6軸ぶんの[count, denominator](config('brand_wheel.axes')の順)
     */
    private function drawBrandWheelHexagon(Slide $slide, float $cx, float $cy, float $radius, array $axisCounts, string $color, float $lineWidthPt): void
    {
        $referenceVertices = array_map(fn (int $k) => $this->hexVertex($cx, $cy, $radius, $k), range(0, 5));
        $this->drawPolygon($slide, $referenceVertices, self::RULE, 0.5);

        $dataVertices = [];
        foreach ($axisCounts as $k => [$count, $denominator]) {
            $r = $denominator > 0 ? $radius * ($count / $denominator) : 0.0;
            $dataVertices[] = $this->hexVertex($cx, $cy, $r, $k);
        }
        $this->drawPolygon($slide, $dataVertices, $color, $lineWidthPt);
    }

    /**
     * 正六角形の頂点(k=0が真上、時計回りに60°ずつ)。config('brand_wheel.
     * axes')の並び順をそのまま角度の割り当てに使う(軸の定義・並びは
     * 変更しない、依頼者指定)。
     *
     * @return array{0: float, 1: float}  [x, y](in)
     */
    private function hexVertex(float $cx, float $cy, float $radius, int $k): array
    {
        $theta = deg2rad(-90 + 60 * $k);

        return [$cx + $radius * cos($theta), $cy + $radius * sin($theta)];
    }

    /**
     * @param  list<array{0: float, 1: float}>  $vertices  閉路を成す頂点列(始点=終点は自動で結ぶ)
     */
    private function drawPolygon(Slide $slide, array $vertices, string $colorRgb, float $lineWidthPt): void
    {
        $count = count($vertices);
        for ($k = 0; $k < $count; $k++) {
            $this->drawLine($slide, $vertices[$k], $vertices[($k + 1) % $count], $colorRgb, $lineWidthPt);
        }
    }

    private function drawLine(Slide $slide, array $from, array $to, string $colorRgb, float $lineWidthPt): void
    {
        $line = $slide->createLineShape(
            (int) round($from[0] * self::PX_PER_INCH),
            (int) round($from[1] * self::PX_PER_INCH),
            (int) round($to[0] * self::PX_PER_INCH),
            (int) round($to[1] * self::PX_PER_INCH),
        );
        $line->getBorder()->setLineWidth($lineWidthPt)->setColor(new Color('FF'.$colorRgb));
    }

    /**
     * 依頼CB-1: 会社の3領域の区分名(会社の魅力/会社との距離/仕事の魅力)。
     * AdminComparisonPptxDataBuilderが「足りないもの」スライド(CB-2)の
     * 各行に添える領域タグにも使う ―― lead-pdf.blade.phpとこのクラスの
     * 2箇所にある3領域の重複表(依頼BZの既知の課題、config化はこの依頼の
     * 対象外)を、これ以上増やさないため、EXPLANATION_REGIONSをこの1箇所に
     * 保ち、両方の呼び出し元から参照する。
     */
    public static function regionName(string $group): string
    {
        foreach (self::EXPLANATION_REGIONS as $region) {
            if ($region['group'] === $group) {
                return $region['name'];
            }
        }

        return '';
    }

    /**
     * 依頼BZ-1: ブランド・ホイールの説明ページ。分析結果に依存しない固定の
     * 内容(会社名・スコアを含まない)。lead-pdf.blade.php「採用ブランドの
     * 捉え方 ―― ブランド・ホイール」ページと同じ情報源を使う ―― 3領域の
     * 区分・一文説明はblade側の直書き文言をそのまま踏襲し(config化されて
     * いない)、6軸の名前・定義はconfig('brand_wheel.axes.*.name_ja'/
     * 'definition')、24項目の構成の説明はblade側のintrobody文言、
     * 注意書きはconfig('brand_wheel.axis_unread_caveat')を、いずれも
     * 文言を書き換えずそのまま使う。
     */
    public function generateExplanationSlide(): string
    {
        return $this->renderSingleSlide(function (Slide $slide): void {
            $this->addKicker($slide);
            $this->addTitle($slide, '採用ブランドの捉え方 ―― ブランド・ホイール');
            $this->addExplanationLead($slide);
            $this->addExplanationGroups($slide);
            $this->addExplanationComposition($slide);
            $this->addExplanationCaveat($slide);
            $this->addFooter($slide, 'Leggenda 採用ブランド・ホイール診断', null);
        });
    }

    private function renderSingleSlide(callable $buildSlide): string
    {
        $presentation = new PhpPresentation();
        $presentation->removeSlideByIndex(0);
        $layout = $presentation->getLayout();
        $layout->setDocumentLayout($layout::LAYOUT_CUSTOM);
        // 依頼AT-4検証で判明: setCX/setCYにUNIT_INCHで13.333を渡すと、内部で
        // 914400倍した非整数EMU(12191695.2)がそのままsldSz cxに書き出され、
        // 実PowerPointが「ファイルが破損しています」として開けなくなる
        // (ライブラリ側は例外を出さず沈黙して不正なXMLを書く ―― テキスト抽出や
        // XMLの妥当性検証だけでは気づけない、実際にPowerPointで開いて初めて
        // 判明した不具合)。13.333inは16:9標準の40/3inの近似値のため、EMUを
        // 直接指定して丸め誤差を避ける(標準的な16:9スライドのEMU値と一致)。
        $layout->setCX(12192000, $layout::UNIT_EMU);
        $layout->setCY(6858000, $layout::UNIT_EMU);

        $slide = $presentation->createSlide();
        $slide->getBackground();

        $buildSlide($slide);

        $writer = new PowerPoint2007($presentation);
        $tmpPath = tempnam(sys_get_temp_dir(), 'pptx');
        $writer->save($tmpPath);
        $bytes = (string) file_get_contents($tmpPath);
        unlink($tmpPath);

        return $bytes;
    }

    private function addKicker(Slide $slide): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, 0.5, self::CONTENT_WIDTH_IN, 0.34);
        $box->setWrap(RichText::WRAP_SQUARE);
        $run = $box->getActiveParagraph()->createTextRun('EMPLOYER BRAND BENCHMARK');
        $this->font($run, 12, true, self::COPPER);
    }

    private function addTitle(Slide $slide, string $text): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, 0.86, self::CONTENT_WIDTH_IN, 0.55);
        $run = $box->getActiveParagraph()->createTextRun($text);
        $this->font($run, 25, true, self::NAVY);
    }

    /**
     * @param  list<array{name: string, matched: int, total: int, is_self: bool}>  $companies
     */
    /**
     * 依頼CB-1(2026-09-24): 旧「領域別の発信量」マトリクス(依頼BM〜BQ)を
     * 縮小し、ヘキサゴンの下に置く数値の凡例として使う ―― 6領域
     * (config('brand_wheel.axes')順)×各社、分母4固定のため必ず埋まる。
     * $tableTopは、上のヘキサゴン列の実際の高さ(自社・競合の半径やラベル
     * 行数で変わりうる)に応じてaddWheelHexagons()が返す値をそのまま使う。
     *
     * @param  list<array{name: string, matched: int, total: int, is_self: bool}>  $companies
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     */
    private function addMatrixSection(Slide $slide, array $companies, array $axes, float $tableTop): void
    {
        $heading = $slide->createRichTextShape();
        $this->position($heading, self::LEFT_IN, $tableTop - 0.28, self::CONTENT_WIDTH_IN, 0.24);
        $run = $heading->getActiveParagraph()->createTextRun('領域別の発信量');
        $this->font($run, 10.5, true, self::NAVY);
        $noteRun = $heading->getActiveParagraph()->createTextRun('　各領域4項目・○と判定できた数');
        $this->font($noteRun, 8, false, self::MUTED);

        $this->addLegend($slide, $tableTop - 0.28);

        $companyCount = count($companies);
        $colWidth = ($companyCount > 0) ? (self::CONTENT_WIDTH_IN - self::AREA_COL_WIDTH_IN) / $companyCount : 0;

        $this->addMatrixHeader($slide, $companies, $colWidth, $tableTop);

        foreach ($axes as $i => $axis) {
            $top = $tableTop + self::TABLE_HEADER_HEIGHT_IN + $i * self::TABLE_ROW_HEIGHT_IN;
            $this->addMatrixRow($slide, $axis, $companies, $colWidth, $top, $i % 2 === 1);
        }
    }

    /**
     * 依頼BN-3(2026-09-09): オレンジの網かけ・競合内の最高値の太字が
     * 何を意味するか、スライドのどこにも説明が無かった(初見の商談相手には
     * 伝わらない、依頼者指摘)。見出しと同じ行の右側に凡例を置く。
     *
     * 濃淡(競合内の最高値を太字にする表現)は残す判断とした(依頼者の
     * 推し・依頼BN-3参照)。全社が同値の行では該当する競合全員が太字に
     * なる(例: 情緒的便益で競合3社が同値)が、これは「その領域の競合内
     * 最高値」という凡例の説明どおりの正しい表示であり、誤りではないため。
     */
    private function addLegend(Slide $slide, float $top): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN + 6.6, $top, self::CONTENT_WIDTH_IN - 6.6, 0.24);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $para = $box->getActiveParagraph();
        $para->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->font($para->createTextRun('■'), 7, false, self::GAP_TEXT);
        $this->font($para->createTextRun(' 自社が競合の最高値未達　'), 7, false, self::MUTED);
        $this->font($para->createTextRun('■'), 7, true, self::NAVY);
        $this->font($para->createTextRun(' 競合内の最高値'), 7, false, self::MUTED);
    }

    /**
     * @param  list<array{name: string, matched: int, total: int, is_self: bool}>  $companies
     */
    private function addMatrixHeader(Slide $slide, array $companies, float $colWidth, float $top): void
    {
        $band = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($band, self::LEFT_IN, $top, self::CONTENT_WIDTH_IN, self::TABLE_HEADER_HEIGHT_IN);
        $band->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::NAVY));
        $band->getBorder()->setLineStyle(Border::LINE_NONE);

        $areaBox = $slide->createRichTextShape();
        $this->position($areaBox, self::LEFT_IN + 0.1, $top, self::AREA_COL_WIDTH_IN - 0.1, self::TABLE_HEADER_HEIGHT_IN);
        $areaBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($areaBox->getActiveParagraph()->createTextRun('領域'), 9, true, self::WHITE);

        foreach ($companies as $i => $company) {
            $left = self::LEFT_IN + self::AREA_COL_WIDTH_IN + $i * $colWidth;
            $box = $slide->createRichTextShape();
            $this->position($box, $left, $top, $colWidth, self::TABLE_HEADER_HEIGHT_IN);
            $box->setWrap(RichText::WRAP_SQUARE);
            $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $box->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            // 依頼BM-5: ヘッダーは1行運用(表を小さくしたため2行は許さない)。
            // 収まらない極端に長い社名だけ省略記号にする。
            $text = $this->wrapOrEllipsizeForLines($company['name'], $colWidth, 8.5, true, 1);
            $this->font($box->getActiveParagraph()->createTextRun($text), 8.5, true, self::WHITE);
        }
    }

    /**
     * @param  array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}  $axis
     * @param  list<array{name: string, matched: int, total: int, is_self: bool}>  $companies
     */
    private function addMatrixRow(Slide $slide, array $axis, array $companies, float $colWidth, float $top, bool $isBanded): void
    {
        if ($isBanded) {
            $band = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
            $this->position($band, self::LEFT_IN, $top, self::CONTENT_WIDTH_IN, self::TABLE_ROW_HEIGHT_IN);
            $band->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::BAND));
            $band->getBorder()->setLineStyle(Border::LINE_NONE);
        }

        $rule = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($rule, self::LEFT_IN, $top + self::TABLE_ROW_HEIGHT_IN - 0.006, self::CONTENT_WIDTH_IN, 0.006);
        $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::RULE));
        $rule->getBorder()->setLineStyle(Border::LINE_NONE);

        $areaNameBox = $slide->createRichTextShape();
        $this->position($areaNameBox, self::LEFT_IN + 0.1, $top, self::AREA_COL_WIDTH_IN - 0.1, self::TABLE_ROW_HEIGHT_IN);
        $areaNameBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($areaNameBox->getActiveParagraph()->createTextRun($axis['name']), 9, true, self::BODY_TEXT);

        $maxCompetitor = $axis['competitor_counts'] === [] ? 0 : max($axis['competitor_counts']);

        foreach ($companies as $i => $company) {
            $left = self::LEFT_IN + self::AREA_COL_WIDTH_IN + $i * $colWidth;
            $count = $company['is_self'] ? $axis['self_count'] : ($axis['competitor_counts'][$i - 1] ?? 0);

            if ($company['is_self'] && $axis['self_gap']) {
                [$bg, $fg] = [self::GAP_BG, self::GAP_TEXT];
            } elseif ($company['is_self']) {
                [$bg, $fg] = [self::SELF_TINT, self::NAVY];
            } elseif ($count === $maxCompetitor && $maxCompetitor > 0) {
                [$bg, $fg] = [null, self::NAVY];
            } else {
                [$bg, $fg] = [null, self::DIM];
            }

            if ($bg !== null) {
                $cellBg = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
                $this->position($cellBg, $left, $top, $colWidth, self::TABLE_ROW_HEIGHT_IN);
                $cellBg->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$bg));
                $cellBg->getBorder()->setLineStyle(Border::LINE_NONE);
            }

            $cell = $slide->createRichTextShape();
            $this->position($cell, $left, $top, $colWidth, self::TABLE_ROW_HEIGHT_IN);
            $cell->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $this->font($cell->getActiveParagraph()->createTextRun("{$count} / {$axis['denominator']}"), 9, true, $fg);
        }
    }

    private function addFooter(Slide $slide, string $sourceNote, ?string $pageNumber): void
    {
        $source = $slide->createRichTextShape();
        $this->position($source, self::LEFT_IN, 6.62, self::CONTENT_WIDTH_IN, 0.3);
        $this->font($source->getActiveParagraph()->createTextRun($sourceNote), 8.5, false, self::MUTED);

        $logo = $slide->createRichTextShape();
        $this->position($logo, self::LEFT_IN, 6.98, 3.0, 0.3);
        $this->font($logo->getActiveParagraph()->createTextRun('LEGGENDA'), 9, true, self::MUTED);

        $page = $slide->createRichTextShape();
        $this->position($page, 12.33, 6.98, 0.6, 0.3);
        $page->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $this->font($page->getActiveParagraph()->createTextRun((string) $pageNumber), 10, false, self::MUTED);
    }

    /**
     * 依頼CB-4: footerの出典行に、1行固定のsource_note(addFooter())では
     * なく、より長い注記文(候補者調査の出典・URLを含む、または巡回範囲の
     * 注記)を使うスライド専用のfooter(LEGGENDAロゴの位置はaddFooter()と
     * 同じにする)。2行までの折り返しを許す(addFooter()の1行固定とは異なる)。
     * CB-2(候補者調査の出典)・CB-3(巡回範囲の注記)の両方から使う ――
     * どちらも「この1枚の内容の前提・出典を、footerに必ず明記する」という
     * 同じ役割のため、共通化した。
     */
    private function addNoteFooter(Slide $slide, string $note): void
    {
        $source = $slide->createRichTextShape();
        $this->position($source, self::LEFT_IN, 6.42, self::CONTENT_WIDTH_IN, 0.4);
        $source->setWrap(RichText::WRAP_SQUARE);
        $this->font($source->getActiveParagraph()->createTextRun($note), 8, false, self::MUTED);

        $logo = $slide->createRichTextShape();
        $this->position($logo, self::LEFT_IN, 6.98, 3.0, 0.3);
        $this->font($logo->getActiveParagraph()->createTextRun('LEGGENDA'), 9, true, self::MUTED);
    }

    /**
     * 依頼CB-2(2026-09-24): 「足りないもの」スライド。既存の抽出条件
     * (競合の過半数、BrandWheelMultiSiteComparisonComposer::
     * extractMissingFromSelf())・上限を超えた分の「ほかN件」畳み方は
     * 変更しない(AdminComparisonPptxDataBuilder::buildMissingItems()を
     * そのまま使う)。0件のときはセクションごと消さず、見出し+空欄時の
     * 文言を出す(依頼BO由来の既存方針を維持)。
     *
     * @param  array{
     *     missing_items: array{
     *         heading: string,
     *         empty_text: string,
     *         items: list<array{axis_name: string, sub_name: string, region: string, impact: string, candidate_survey: array{item: ?string, percentage: ?float}}>,
     *         others_count: int,
     *     },
     *     candidate_survey_source_note: string,
     *     source_note: string,
     *     page_number: ?string,
     * } $data
     */
    public function generateMissingItemsSlide(array $data): string
    {
        return $this->renderSingleSlide(function (Slide $slide) use ($data): void {
            $this->addKicker($slide);
            $this->addTitle($slide, '足りないもの');
            $this->addMissingItemsHeading($slide, $data['missing_items']);
            $this->addMissingItemsRows($slide, $data['missing_items']);
            $this->addNoteFooter($slide, $data['candidate_survey_source_note']);
        });
    }

    /**
     * @param  array{heading: string, empty_text: string, items: list<mixed>, others_count: int}  $missingItems
     */
    private function addMissingItemsHeading(Slide $slide, array $missingItems): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, self::MISSING_HEADING_TOP_IN, self::CONTENT_WIDTH_IN, 0.32);
        $box->setWrap(RichText::WRAP_SQUARE);
        $para = $box->getActiveParagraph();
        $this->font($para->createTextRun($missingItems['heading'].'：'), 13, true, self::NAVY);

        if ($missingItems['items'] === []) {
            $this->font($para->createTextRun($missingItems['empty_text']), 13, false, self::MUTED);
        }
    }

    /**
     * @param  array{items: list<array{axis_name: string, sub_name: string, region: string, impact: string, candidate_survey: array{item: ?string, percentage: ?float}}>, others_count: int}  $missingItems
     */
    private function addMissingItemsRows(Slide $slide, array $missingItems): void
    {
        $rowStep = self::MISSING_ROW_HEIGHT_IN + self::MISSING_ROW_GAP_IN;
        $textWidth = self::CONTENT_WIDTH_IN - 0.1;

        foreach ($missingItems['items'] as $i => $item) {
            $top = self::MISSING_ROWS_TOP_IN + $i * $rowStep;

            $rule = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
            $this->position($rule, self::LEFT_IN, $top + self::MISSING_ROW_HEIGHT_IN - 0.008, self::CONTENT_WIDTH_IN, 0.008);
            $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::RULE));
            $rule->getBorder()->setLineStyle(Border::LINE_NONE);

            $nameBox = $slide->createRichTextShape();
            $this->position($nameBox, self::LEFT_IN, $top, $textWidth, 0.2);
            $namePara = $nameBox->getActiveParagraph();
            $this->font($namePara->createTextRun($item['sub_name']), 11.5, true, self::NAVY);
            $this->font($namePara->createTextRun('　［'.$item['region'].'］'), 8.5, false, self::MUTED);

            $impactBox = $slide->createRichTextShape();
            $this->position($impactBox, self::LEFT_IN, $top + 0.2, $textWidth, 0.28);
            $impactBox->setWrap(RichText::WRAP_SQUARE);
            $this->font($impactBox->getActiveParagraph()->createTextRun($item['impact']), 9, false, self::BODY_TEXT);

            $surveyBox = $slide->createRichTextShape();
            $this->position($surveyBox, self::LEFT_IN, $top + 0.48, $textWidth, 0.14);
            $surveyText = $item['candidate_survey']['item'] !== null
                ? sprintf('候補者調査：「%s」を重視する求職者　%s%%', $item['candidate_survey']['item'], rtrim(rtrim(number_format((float) $item['candidate_survey']['percentage'], 1), '0'), '.'))
                : '候補者調査：対応する項目なし';
            $this->font($surveyBox->getActiveParagraph()->createTextRun($surveyText), 8.5, false, self::COPPER);
        }

        if ($missingItems['others_count'] > 0) {
            $top = self::MISSING_ROWS_TOP_IN + count($missingItems['items']) * $rowStep;
            $othersBox = $slide->createRichTextShape();
            $this->position($othersBox, self::LEFT_IN, $top, $textWidth, 0.2);
            $this->font($othersBox->getActiveParagraph()->createTextRun("ほか{$missingItems['others_count']}件"), 10, false, self::MUTED);
        }
    }

    /**
     * 依頼CB-3(2026-09-24): 「自社サイトの階層図」スライド。
     * AdminComparisonSiteHierarchyBuilderが渡す$hierarchy(URLのパス階層
     * から組み立てた1階層目の枝)をそのまま描画するだけで、集計はこの
     * クラスでは一切行わない。「ありません」と断定する文言は使わない
     * (依頼者指定、必須) ―― 0件のときも
     * config('admin_comparison_pptx.site_hierarchy_empty_text')
     * (「巡回した範囲では...見つかりませんでした」)を使う。
     *
     * @param  array{recommended_site_flow_names: list<string>}  $data
     * @param  array{origin_url: string, branches: list<array{name: string, page_count: int, sample_pages: list<string>}>, other_branch_count: int}  $hierarchy
     */
    public function generateSiteHierarchySlide(array $data, array $hierarchy): string
    {
        return $this->renderSingleSlide(function (Slide $slide) use ($data, $hierarchy): void {
            $this->addKicker($slide);
            $this->addTitle($slide, '自社サイトの階層図');
            $this->addHierarchyOrigin($slide, $hierarchy['origin_url']);
            $bottom = $this->addHierarchyBranches($slide, $hierarchy['branches'], $hierarchy['other_branch_count'], $hierarchy['origin_url']);
            $this->addHierarchyRecommendations($slide, $data['recommended_site_flow_names'], $bottom);
            // 依頼CB-3必須: footerには、通常の出典行(addFooter())ではなく
            // 巡回範囲についての注記を出す ―― この1枚の内容が「巡回できた
            // 範囲」に限られることを、必ず読める位置に置くため
            // (addNoteFooter()、CB-2のaddNoteFooter呼び出しと同じ仕組み)。
            $note = sprintf((string) config('admin_comparison_pptx.site_hierarchy_crawl_scope_note'), (int) config('brand_wheel.crawl_max_pages'));
            $this->addNoteFooter($slide, $note);
        });
    }

    private function addHierarchyOrigin(Slide $slide, string $originUrl): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, self::HIERARCHY_ORIGIN_TOP_IN, self::CONTENT_WIDTH_IN, 0.28);
        $box->setWrap(RichText::WRAP_SQUARE);
        $para = $box->getActiveParagraph();
        $this->font($para->createTextRun('TOP　'), 11, true, self::NAVY);
        $this->font($para->createTextRun($originUrl), 10, false, self::MUTED);
    }

    /**
     * @param  list<array{name: string, page_count: int, sample_pages: list<string>}>  $branches
     * @return float  この下に描く「追加を検討したい導線」の上端y(in)
     */
    private function addHierarchyBranches(Slide $slide, array $branches, int $otherBranchCount, string $originUrl): float
    {
        if ($branches === []) {
            $box = $slide->createRichTextShape();
            $this->position($box, self::LEFT_IN, self::HIERARCHY_BRANCHES_TOP_IN, self::CONTENT_WIDTH_IN, 0.3);
            $box->setWrap(RichText::WRAP_SQUARE);
            $text = sprintf((string) config('admin_comparison_pptx.site_hierarchy_empty_text'), $originUrl);
            $this->font($box->getActiveParagraph()->createTextRun($text), 11, false, self::MUTED);

            return self::HIERARCHY_BRANCHES_TOP_IN + 0.3 + self::HIERARCHY_RECOMMENDED_GAP_IN;
        }

        $rowStep = self::HIERARCHY_ROW_HEIGHT_IN + self::HIERARCHY_ROW_GAP_IN;

        foreach ($branches as $i => $branch) {
            $top = self::HIERARCHY_BRANCHES_TOP_IN + $i * $rowStep;

            $rule = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
            $this->position($rule, self::LEFT_IN, $top + self::HIERARCHY_ROW_HEIGHT_IN - 0.008, self::CONTENT_WIDTH_IN, 0.008);
            $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::RULE));
            $rule->getBorder()->setLineStyle(Border::LINE_NONE);

            $nameBox = $slide->createRichTextShape();
            $this->position($nameBox, self::LEFT_IN, $top, self::CONTENT_WIDTH_IN, 0.22);
            $namePara = $nameBox->getActiveParagraph();
            $this->font($namePara->createTextRun('├ '.$branch['name']), 11, true, self::NAVY);
            $this->font($namePara->createTextRun("　（{$branch['page_count']}ページ）"), 9, false, self::MUTED);

            if ($branch['sample_pages'] !== []) {
                $sampleBox = $slide->createRichTextShape();
                $this->position($sampleBox, self::LEFT_IN + 0.2, $top + 0.22, self::CONTENT_WIDTH_IN - 0.2, 0.18);
                $sampleBox->setWrap(RichText::WRAP_SQUARE);
                $sampleText = implode('　/　', $branch['sample_pages']);
                $this->font($sampleBox->getActiveParagraph()->createTextRun($sampleText), 8.5, false, self::MUTED);
            }
        }

        $bottom = self::HIERARCHY_BRANCHES_TOP_IN + count($branches) * $rowStep;

        if ($otherBranchCount > 0) {
            $othersBox = $slide->createRichTextShape();
            $this->position($othersBox, self::LEFT_IN, $bottom, self::CONTENT_WIDTH_IN, 0.2);
            $this->font($othersBox->getActiveParagraph()->createTextRun("ほか{$otherBranchCount}"), 9.5, false, self::MUTED);
            $bottom += 0.2;
        }

        return $bottom + self::HIERARCHY_RECOMMENDED_GAP_IN;
    }

    /**
     * 依頼CB-3必須: 「ありません」と断定しない ―― 「足りないもの」(CB-2)に
     * 対応するサイトの導線名を「追加を検討したい導線」として並べるだけで、
     * 巡回した範囲に無いと断定はしない。0件(足りない項目が無い)のときは
     * 何も描かない ―― 「無かった」ことを積極的に述べる文言は不要なため。
     * 巡回の範囲についての注記は、この下のfooter(addNoteFooter())で
     * 別途必ず出す(generateSiteHierarchySlide()参照)。
     *
     * @param  list<string>  $recommendedSiteFlowNames
     */
    private function addHierarchyRecommendations(Slide $slide, array $recommendedSiteFlowNames, float $top): void
    {
        if ($recommendedSiteFlowNames === []) {
            return;
        }

        $headingBox = $slide->createRichTextShape();
        $this->position($headingBox, self::LEFT_IN, $top, self::CONTENT_WIDTH_IN, 0.22);
        $this->font($headingBox->getActiveParagraph()->createTextRun((string) config('admin_comparison_pptx.site_hierarchy_recommended_heading')), 11, true, self::NAVY);

        $listBox = $slide->createRichTextShape();
        $this->position($listBox, self::LEFT_IN, $top + 0.24, self::CONTENT_WIDTH_IN, 0.3);
        $listBox->setWrap(RichText::WRAP_SQUARE);
        $listText = implode('　/　', $recommendedSiteFlowNames);
        $this->font($listBox->getActiveParagraph()->createTextRun($listText), 10, false, self::GAP_TEXT);
    }

    /**
     * 依頼BZ-1: 「採用ブランドは、大きく3つの領域に分けて捉えます。」
     * (lead-pdf.blade.phpのintrolead、直書き文言をそのまま踏襲)。
     */
    private function addExplanationLead(Slide $slide): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, self::EXPL_LEAD_TOP_IN, self::CONTENT_WIDTH_IN, 0.28);
        $run = $box->getActiveParagraph()->createTextRun('採用ブランドは、大きく3つの領域に分けて捉えます。');
        $this->font($run, 13, false, self::NAVY);
    }

    /**
     * 依頼BZ-1: 3領域を横3列で並べる(依頼者提案のレイアウト、縦積みでは
     * なくこちらを採用 ―― 横に並べたほうが「3つに分けて捉える」という
     * リード文とレイアウトが素直に対応するため)。各列は
     * [色付きヘッダー(領域名) → 一文説明 → 軸1(name_ja+definition) →
     * 軸2(name_ja+definition)]。画像(brand-wheel-framework.png)は使わず、
     * 色の区別は塗りの矩形(header)だけで表現する(依頼者指定、BZ-1参照)。
     */
    private function addExplanationGroups(Slide $slide): void
    {
        $axesByGroup = [];
        foreach ((array) config('brand_wheel.axes') as $axisConfig) {
            $axesByGroup[$axisConfig['group']][] = $axisConfig;
        }

        $gap = 0.3;
        $width = (self::CONTENT_WIDTH_IN - 2 * $gap) / 3;

        foreach (self::EXPLANATION_REGIONS as $i => $region) {
            $left = self::LEFT_IN + $i * ($width + $gap);
            $this->addExplanationGroupColumn($slide, $region, $axesByGroup[$region['group']] ?? [], $left, $width);
        }
    }

    /**
     * @param  array{group: string, name: string, description: string, color: string}  $region
     * @param  list<array{name_ja: string, definition: string}>  $axes  config('brand_wheel.axes')のうちこのgroupに属する2件(config側の並び順)
     */
    private function addExplanationGroupColumn(Slide $slide, array $region, array $axes, float $left, float $width): void
    {
        $top = self::EXPL_GROUPS_TOP_IN;

        $header = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($header, $left, $top, $width, self::EXPL_HEADER_HEIGHT_IN);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$region['color']));
        $header->getBorder()->setLineStyle(Border::LINE_NONE);

        $headerBox = $slide->createRichTextShape();
        $this->position($headerBox, $left, $top, $width, self::EXPL_HEADER_HEIGHT_IN);
        $headerBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $headerBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->font($headerBox->getActiveParagraph()->createTextRun($region['name']), 13, true, self::WHITE);

        $descTop = $top + self::EXPL_HEADER_HEIGHT_IN + 0.08;
        $descBox = $slide->createRichTextShape();
        $this->position($descBox, $left, $descTop, $width, self::EXPL_DESC_HEIGHT_IN);
        $descBox->setWrap(RichText::WRAP_SQUARE);
        $this->font($descBox->getActiveParagraph()->createTextRun($region['description']), 9, false, self::MUTED);

        $axisTop = $descTop + self::EXPL_DESC_HEIGHT_IN + 0.06;
        foreach ($axes as $axisConfig) {
            $axisTop = $this->addExplanationAxisBlock($slide, $axisConfig, $left, $axisTop, $width);
        }
    }

    /**
     * config('brand_wheel.axes.*.name_ja')・definitionを、文言を書き換えず
     * そのまま表示する。
     *
     * @param  array{name_ja: string, definition: string}  $axisConfig
     * @return float  次の軸ブロックが使える先頭のy座標(in)
     */
    private function addExplanationAxisBlock(Slide $slide, array $axisConfig, float $left, float $top, float $width): float
    {
        $nameBox = $slide->createRichTextShape();
        $this->position($nameBox, $left, $top, $width, 0.2);
        $this->font($nameBox->getActiveParagraph()->createTextRun((string) $axisConfig['name_ja']), 10.5, true, self::NAVY);

        $defTop = $top + 0.21;
        $defHeight = 0.62;
        $defBox = $slide->createRichTextShape();
        $this->position($defBox, $left, $defTop, $width, $defHeight);
        $defBox->setWrap(RichText::WRAP_SQUARE);
        $this->font($defBox->getActiveParagraph()->createTextRun((string) $axisConfig['definition']), 8, false, self::BODY_TEXT);

        return $defTop + $defHeight + 0.1;
    }

    /**
     * 依頼BZ-1: 24項目の構成の説明。lead-pdf.blade.phpのintrobody文言
     * (Core Valueの説明を含む段落)をそのまま踏襲する。
     */
    private function addExplanationComposition(Slide $slide): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, self::EXPL_COMPOSITION_TOP_IN, self::CONTENT_WIDTH_IN, 0.5);
        $box->setWrap(RichText::WRAP_SQUARE);
        $run = $box->getActiveParagraph()->createTextRun(
            '6つの項目にはそれぞれ4つの下位要素があり、合計24項目です。中心のCore Value(約束する価値)は、その24項目を貫く「この会社が候補者に約束するもの」にあたります。'
        );
        $this->font($run, 11, false, self::BODY_TEXT);
    }

    /**
     * 依頼BZ-1(必須): config('brand_wheel.axis_unread_caveat')
     * (「読み取れなかった＝魅力が無い、ではない」の主旨)を、文言を一切
     * 書き換えずそのまま表示する。lead-pdf.blade.phpのコメントに「この一文は
     * 短縮・削除しない」「ユーザー指定の絶対に消してはいけない文言」と
     * 明記されており、商談で他社の点数を見せる資料である以上、この但し書き
     * はPDFよりむしろ必要(依頼者指定)。
     */
    private function addExplanationCaveat(Slide $slide): void
    {
        $rule = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($rule, self::LEFT_IN, self::EXPL_CAVEAT_TOP_IN, self::CONTENT_WIDTH_IN, 0.01);
        $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::RULE));
        $rule->getBorder()->setLineStyle(Border::LINE_NONE);

        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, self::EXPL_CAVEAT_TOP_IN + 0.1, self::CONTENT_WIDTH_IN, 0.65);
        $box->setWrap(RichText::WRAP_SQUARE);
        $run = $box->getActiveParagraph()->createTextRun((string) config('brand_wheel.axis_unread_caveat'));
        $this->font($run, 9, false, self::MUTED);
    }

    /**
     * wrapOrEllipsizeForLines・estimateLineCount・splitBalancedForTwoLinesが
     * 共通で使う、1行に収まる表示幅(mb_strwidth基準)の見積もり(依頼AT-1由来、
     * 依頼BO-3で共通化のため抽出。計算式そのものは変更していない)。
     */
    private function maxUnitsPerLine(float $widthIn, float $sizePt, bool $bold): int
    {
        $insetPt = 9.0;
        // 全角1文字(表示幅2)がおおよそ1em(=$sizePt)になるよう、
        // 表示幅1単位あたりの幅を$sizePt/2とする。
        $unitWidthPt = $sizePt * ($bold ? 1.28 : 1.18) / 2;
        $usableWidthPt = $widthIn * 72 - $insetPt;

        return max(1, (int) floor($usableWidthPt / $unitWidthPt));
    }

    /**
     * 依頼BO-2: まとめの帯の高さ・不足項目一覧の使える行数を決めるための、
     * 折り返し後の行数の見積もり。総表示幅を1行あたりの許容表示幅で割って
     * 行数だけを概算する(1文字ずつ改行位置を計算するのではない ――
     * PowerPointの実描画(wrap="square")が実際の折り返しを行うため、
     * ここでは行数の見積もりのみで足りる)。
     *
     * maxUnitsPerLine()(1.18/1.28倍、依頼BM-5/BN-2で社名の「切り詰めるか
     * 否か」の安全側判定用に実機画像化で調整された値)をそのまま流用せず、
     * 専用の係数(1.02)を使う ―― 実機画像化(LibreOffice)で検証した結果、
     * まとめの帯(11pt非太字・幅11.0in)は表示幅140単位、不足項目一覧
     * (10pt太字・幅11.5in)は表示幅156単位まで実際には1行に収まっていたが、
     * maxUnitsPerLine()の係数で計算すると119/127単位までしか1行と判定
     * されず、本当は1行に収まる文言を2行分の高さで確保してしまい
     * (依頼BM-2の旧まとめ帯が3行固定だった問題の再発)、不足項目一覧に
     * 回るはずの余白を無駄に消費していた。1.02はキャリブレーション実測値
     * (140/156単位の実測)に対し、太字・非太字のどちらでも安全側(実測を
     * 上回らない)に倒るよう選んだ小さな安全マージン ―― 太字と非太字で
     * 実測値の差がほぼ無かった(156 vs 140は主にフォントサイズ差
     * 10pt/11ptによるもの)ため、boldによる係数の出し分けをしていない。
     */
    private function estimateLineCount(string $text, float $widthIn, float $sizePt): int
    {
        $insetPt = 9.0;
        $unitWidthPt = $sizePt * 1.02 / 2;
        $usableWidthPt = $widthIn * 72 - $insetPt;
        $maxUnitsPerLine = max(1, (int) floor($usableWidthPt / $unitWidthPt));

        return max(1, (int) ceil(mb_strwidth($text, 'UTF-8') / $maxUnitsPerLine));
    }

    /**
     * 依頼BO-3: 「株式会社マネーフォワード」のような社名で、PowerPointの
     * 自動折り返し(wrap="square"、行を貪欲に埋める)が「ド」1文字だけを
     * 2行目に取り残す不具合が実機画像化で見つかった。2行に折り返す必要が
     * ある($textの表示幅が1行に収まらない)場合だけ、表示幅のほぼ半分の
     * 位置で明示的に改行(createBreak())を入れて描画し、PowerPointの
     * 自動折り返しに委ねない ―― 1行に収まる社名(大多数)はこのメソッドを
     * 経由せず、従来どおり自動折り返しのみで済ませる。
     */
    private function renderBalancedLines(RichText\Paragraph $para, string $text, float $widthIn, float $sizePt, bool $bold, string $color): void
    {
        $maxUnitsPerLine = $this->maxUnitsPerLine($widthIn, $sizePt, $bold);

        if (mb_strwidth($text, 'UTF-8') <= $maxUnitsPerLine) {
            $this->font($para->createTextRun($text), $sizePt, $bold, $color);

            return;
        }

        [$line1, $line2] = $this->splitBalancedForTwoLines($text, $maxUnitsPerLine);
        $this->font($para->createTextRun($line1), $sizePt, $bold, $color);
        $para->createBreak();
        $this->font($para->createTextRun($line2), $sizePt, $bold, $color);
    }

    /**
     * 表示幅のほぼ半分(端数は1行目に寄せる)を境目に、1文字単位で分割する
     * (バイト単位や文字数単位ではなく表示幅基準 ―― 依頼BN-2と同じ理由)。
     * 1行目の許容表示幅($maxUnitsPerLine)を超えないよう上限をかける。
     *
     * @return array{0: string, 1: string}
     */
    private function splitBalancedForTwoLines(string $text, int $maxUnitsPerLine): array
    {
        $targetFirstLineUnits = min($maxUnitsPerLine, (int) ceil(mb_strwidth($text, 'UTF-8') / 2));

        $chars = mb_str_split($text);
        $line1 = '';
        $units = 0;
        $splitIndex = 0;

        foreach ($chars as $i => $char) {
            $charUnits = mb_strwidth($char, 'UTF-8');
            if ($units > 0 && $units + $charUnits > $targetFirstLineUnits) {
                break;
            }
            $line1 .= $char;
            $units += $charUnits;
            $splitIndex = $i + 1;
        }

        return [$line1, implode('', array_slice($chars, $splitIndex))];
    }

    /**
     * 依頼AT-1由来、依頼BM-5で2行対応に拡張。1行に収まる長さの見積もりは
     * 従来と同じ(Meiryoの日本語フルwidth文字は概ね1em幅、実PowerPoint
     * 画像化で目視確認済みの係数)。$maxLines行に収まる文字数までは
     * そのまま返し(PowerPoint本体のwrap="square"が実際の折り返しを行う
     * ため、ここでは改行位置を計算しない)、収まらない場合だけ$maxLines
     * 行ぶんの文字数で省略記号にする(社名を切り詰めない、が極端に長い
     * 社名でヘッダーの固定高さを崩さないための最終手段、依頼BM-5の
     * 「対処の提案」への回答)。
     */
    /**
     * 依頼BN-2(2026-09-09): 文字数(mb_strlen)だけで見積もっていたため、
     * 半角(英数・スペース)の実際の幅を全角と同じとして過大評価し、2行に
     * 収まる社名(例:「株式会社Fuji of Innovation」、mb_strlen=22だが
     * 実際の表示幅はmb_strwidthで26半角ぶんしかない)まで省略記号で
     * 切ってしまっていた(実機画像化で発覚)。文字数ではなく、全角=2・
     * 半角=1の表示幅(mb_strwidth、東アジアの文字幅の広さの慣習に基づく
     * PHP標準の見積もり方)で見積もり直す。
     */
    private function wrapOrEllipsizeForLines(string $text, float $widthIn, float $sizePt, bool $bold, int $maxLines): string
    {
        $maxUnits = $this->maxUnitsPerLine($widthIn, $sizePt, $bold) * $maxLines;

        if (mb_strwidth($text, 'UTF-8') <= $maxUnits) {
            return $text;
        }

        // 省略記号(全角相当、表示幅2)ぶんを差し引いた表示幅まで、
        // 1文字ずつ表示幅を積算して切り詰める(文字数ではなく表示幅基準)。
        return $this->truncateToDisplayWidth($text, max(0, $maxUnits - 2)).'…';
    }

    private function truncateToDisplayWidth(string $text, int $maxUnits): string
    {
        $result = '';
        $usedUnits = 0;

        foreach (mb_str_split($text) as $char) {
            $charUnits = mb_strwidth($char, 'UTF-8');
            if ($usedUnits + $charUnits > $maxUnits) {
                break;
            }
            $result .= $char;
            $usedUnits += $charUnits;
        }

        return $result;
    }

    private function position($shape, float $leftIn, float $topIn, float $widthIn, float $heightIn): void
    {
        $shape->setOffsetX((int) round($leftIn * self::PX_PER_INCH));
        $shape->setOffsetY((int) round($topIn * self::PX_PER_INCH));
        $shape->setWidth((int) round($widthIn * self::PX_PER_INCH));
        $shape->setHeight((int) round($heightIn * self::PX_PER_INCH));
    }

    /**
     * 依頼AT-4検証で判明: phpoffice/phppresentationのFont::setSize()はint
     * 引数のみ受け付ける(OOXML自体はsz="850"のような0.5pt単位=センチポイント
     * に対応しているが、このライブラリの公開APIでは表現できない)。8.5pt/9.5pt
     * を四捨五入して整数ptに丸める(round()は正の数を.5から遠ざかる方向に
     * 丸めるため8.5→9pt、9.5→10ptになる ―― int型引数への暗黙変換が常に
     * 切り捨てる(9.5→9pt)よりは、指定値に近い丸め方になる)。この0.5pt差は
     * 報告済み(依頼AT報告事項1)。
     */
    private function font(RichText\Run $run, float $sizePt, bool $bold, string $rgb): void
    {
        $run->getFont()
            ->setName('Meiryo')
            ->setSize((int) round($sizePt))
            ->setBold($bold)
            ->setColor(new Color('FF'.$rgb));
    }
}
