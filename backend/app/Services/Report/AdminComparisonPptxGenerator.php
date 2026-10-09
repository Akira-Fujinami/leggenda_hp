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

    // 依頼CQ-4: 「トップメッセージと人事制度」のページの配置(in)。
    private const TM_SUBTITLE_TOP_IN = 1.4;

    private const TM_BODY_TOP_IN = 1.95;

    private const TM_BODY_BOTTOM_IN = 6.25;

    private const TM_LEFT_WIDTH_IN = 4.2;

    private const TM_RIGHT_LEFT_IN = 5.4;

    private const TM_KEYWORD_WIDTH_IN = 1.75;

    private const TM_KEYWORD_GAP_IN = 0.12;

    private const TM_ROW_GAP_IN = 0.1;

    private const TM_ROW_MAX_HEIGHT_IN = 1.5;

    private const TM_CELL_GAP_IN = 0.1;

    private const TM_CELL_PADDING_IN = 0.14;

    // 作られなかった会社の注記(比較のページの、ヘキサゴンの下・出典行の上)。
    private const TM_MISSING_NOTE_TOP_IN = 6.34;

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
    // 依頼CL-1(2026-10-05): ブランド・ホイール比較。横3列で組む ――
    // 左=自社(大きなヘキサゴン)、中央=競合(縦に並べる)、右=領域別の
    // 発信量の表。依頼CF-3で自社を大きくするために縦の余白を詰めた結果、
    // 競合のヘキサゴンが点数(18 / 24)や「領域別の発信量」の見出し・凡例に
    // 重なっていた(実物で確認)。縦に積む構成をやめ、列に分けることで
    // 解消した。
    //
    // 図形同士・図形と文字が重ならないことは自動テストでは見つけられない
    // (依頼CC-2・CF-3の前例)。寸法を変えたときは必ず実機で画像化して
    // 確認すること(競合1〜3社=中央を縦1列、4〜5社=2列)。
    //
    // 依頼CM-4(2026-10-06): 競合が1〜3社(config: wheel_names_max_competitors)の
    // ときは、表の見出しに企業名を出す(列の幅に余裕があるため、右の表を広げ、
    // 中央の列を狭める)。4〜5社のときは記号(自社/A〜E)。列の寸法はこの2通りを
    // WHEEL_LAYOUT_NAMES / WHEEL_LAYOUT_SYMBOLSにまとめてある。
    // ------------------------------------------------------------------

    /** 3列の見出し行の上端と高さ。見出しの下に細い罫線を引く。 */
    private const WHEEL_HEADING_TOP_IN = 1.52;

    private const WHEEL_HEADING_HEIGHT_IN = 0.28;

    /** 3列の本体(見出しの下)の上端と下端。ここに収まるように各列を組む。 */
    private const WHEEL_BODY_TOP_IN = 1.95;

    private const WHEEL_BODY_BOTTOM_IN = 6.3;

    /**
     * 中央の列と右の表の寸法(in)。names=表の見出しが企業名(競合1〜3社)、
     * symbols=表の見出しが記号(競合4〜5社)。
     *
     * @var array<string, array{names: bool, competitor_left: float, competitor_width: float, table_left: float, table_width: float, area_width: float, area_font: int, header_height: float}>
     */
    private const WHEEL_LAYOUT_NAMES = [
        'names' => true,
        'competitor_left' => 5.3,
        'competitor_width' => 1.6,
        'table_left' => 7.05,
        'table_width' => 5.35,
        'area_width' => 0.85,
        'area_font' => 8,
        'header_height' => 0.56,
    ];

    private const WHEEL_LAYOUT_SYMBOLS = [
        'names' => false,
        'competitor_left' => 5.3,
        'competitor_width' => 2.8,
        'table_left' => 8.25,
        'table_width' => 4.15,
        'area_width' => 1.15,
        'area_font' => 9,
        'header_height' => 0.34,
    ];

    /**
     * 左の列(自社)。ヘキサゴンの半径は、依頼CF-3の0.65inから1.1inへ
     * 拡大した(列の幅4.2inの中に、ヘキサゴンの幅(2×r×cos30°=1.9in)と
     * 左右の頂点ラベル(各1.15in)がちょうど収まる大きさ)。正六角形の比例は
     * 保つ ―― X/Yで異なる半径に引き伸ばす案は採らない(同じ4/4でも軸に
     * よって突き出方が変わって見え、形状の歪みで差があるかのように誤解
     * させるため、依頼CF-3の判断のまま)。
     */
    private const WHEEL_SELF_LEFT_IN = 0.9;

    private const WHEEL_SELF_WIDTH_IN = 4.2;

    private const WHEEL_SELF_RADIUS_IN = 1.1;

    /** 企業名(最大2行)と合計点の高さ。 */
    private const WHEEL_SELF_NAME_HEIGHT_IN = 0.5;

    private const WHEEL_SELF_SCORE_HEIGHT_IN = 0.4;

    /**
     * 頂点ラベル(領域名＋数値、例「活動的魅力 4/4」)。頂点から半径方向に
     * WHEEL_LABEL_MARGIN_INだけ外側へ置く。自社のヘキサゴンにだけ置く
     * (競合の小さい図には置かない ―― 軸の並びが自社の図と同じであることを
     * 凡例の一文で示す)。
     */
    private const WHEEL_LABEL_MARGIN_IN = 0.14;

    private const WHEEL_LABEL_WIDTH_IN = 1.15;

    private const WHEEL_LABEL_HEIGHT_IN = 0.2;

    private const WHEEL_LABEL_FONT_SIZE = 9;

    /**
     * 自社ヘキサゴンの上下に、頂点ラベル1行ぶんを確保するための予約高
     * (margin+ラベル高さ+安全マージン)。上頂点ラベルが合計点に重ならない
     * ように、上頂点自体をこの高さだけ合計点の下に置く(依頼CC-1の不具合の
     * 再発防止)。
     */
    private const WHEEL_LABEL_RESERVE_IN = self::WHEEL_LABEL_MARGIN_IN + self::WHEEL_LABEL_HEIGHT_IN + 0.08;

    /**
     * 中央の列(競合)の1行の高さの上限と、ヘキサゴンの半径。競合が少ない
     * (企業名を表の見出しに出す)ときは縦1列で、企業名を上に、その下に
     * ヘキサゴンと合計点を並べる。多い(記号)ときは2列で、企業名・ヘキサゴン・
     * 合計点を縦に積む。
     */
    private const WHEEL_COMPETITOR_ROW_MAX_HEIGHT_IN = 1.5;

    private const WHEEL_COMPETITOR_SINGLE_RADIUS_IN = 0.4;

    private const WHEEL_COMPETITOR_DOUBLE_RADIUS_IN = 0.3;

    private const WHEEL_COMPETITOR_DOUBLE_TILE_WIDTH_IN = 1.4;

    private const TABLE_ROW_HEIGHT_IN = 0.4;

    // ------------------------------------------------------------------
    // 依頼CB-2/CC-2: 「足りないもの」スライド。
    // ------------------------------------------------------------------

    private const MISSING_HEADING_TOP_IN = 1.5;

    /** 依頼CC-2②: 冒頭の説明(missing_items_intro)の高さ。最大2行を見込む。 */
    private const MISSING_INTRO_HEIGHT_IN = 0.42;

    private const MISSING_ROWS_TOP_IN = 2.34;

    /**
     * 依頼CC-2①(必須): 本番の出力で、候補者調査の行(3行目)に区切り線が
     * 突き抜けて見える不具合があった(依頼者指摘、実機画像化で確認して
     * 特定 ―― 自動テストでは検知できない)。原因は各行内3ブロックの高さの
     * 見積もり(旧: 名前0.2in/一文0.28in/候補者調査0.14in)が、Meiryoの
     * 実際の行送りより小さすぎたこと。estimateLineCount/
     * SUMMARY_LINE_HEIGHT_IN(依頼BO由来、11ptで実測0.23in/行=0.021in/pt)
     * と同じ換算で引き直した:
     *   - 名前+領域タグ(11.5pt、1行): 0.021×11.5≒0.24in → 0.26in
     *   - 一文(9pt、最大2行): 0.021×9×2≒0.38in → 0.40in
     *   - 候補者調査(8.5pt、1行): 0.021×8.5≒0.18in → 0.22in
     *   計0.88in(旧0.62inから+0.26in)。
     * さらに、区切り線を行の直下(旧: 行末-0.008in、実質すきま無し)では
     * なく、行間の余白の中央に置き直した(addMissingItemsRows参照) ――
     * 万一テキストが1〜2pt分オーバーフローしても線と重ならないための
     * 二重の安全策。
     */
    private const MISSING_ROW_HEIGHT_IN = 0.88;

    private const MISSING_ROW_GAP_IN = 0.1;

    // ------------------------------------------------------------------
    // 依頼CL-2(2026-10-05): 「求職者が知りたい情報と、自社サイト」スライド。
    // 調査の選択肢14件を1枚に収める(footerの注記ぶんの高さも確保する)。
    // 行の高さを変えたときは、14行＋注記が重ならないことを実機で画像化して
    // 確認すること。
    // ------------------------------------------------------------------

    private const SURVEY_INTRO_TOP_IN = 1.46;

    private const SURVEY_TABLE_TOP_IN = 1.85;

    private const SURVEY_HEADER_HEIGHT_IN = 0.28;

    private const SURVEY_ROW_HEIGHT_IN = 0.25;

    /**
     * 列の[左端, 幅](in)。合計はCONTENT_WIDTH_IN(11.5in)。
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const SURVEY_COLUMNS = [
        'rank' => [0.9, 0.55],
        'name' => [1.65, 3.3],
        'percentage' => [5.1, 3.1],
        'self' => [8.35, 1.95],
        'competitor' => [10.35, 2.05],
    ];

    // ------------------------------------------------------------------
    // 依頼CL-3(2026-10-05): 「自社サイトの階層図」スライド(木の形)。
    // 左から右へ TOP → 第1階層 → 第2階層。第2階層の行数で第1階層の行の
    // 高さが決まるため、最大(第1階層の上限×第2階層の上限)でも、固定位置の
    // axis_unread_caveat(HIERARCHY_AXIS_CAVEAT_RULE_TOP_IN)の手前に収まる
    // 上限をconfigに置いてある(site_hierarchy_tree_first_level_limit等)。
    // 寸法・上限を変えたときは、最大ケースを実機で画像化して確認すること。
    // ------------------------------------------------------------------

    /** 上部の注記(巡回件数の事実＋木の作り方の説明)。 */
    private const TREE_NOTES_TOP_IN = 1.46;

    private const TREE_NOTES_HEIGHT_IN = 0.62;

    /** 木の上端。TOPの箱も第1階層の最初の行もここから始まる。 */
    private const TREE_TOP_IN = 2.55;

    // 依頼CR-3: 列の上の見出し(階層名と説明)の位置と、コーポレートTOPが決まったときの4列の[左, 幅](in)。
    private const TREE_HEADINGS_TOP_IN = 2.06;

    private const TREE4_COLUMNS = [[0.9, 1.9], [3.1, 2.3], [5.75, 2.65], [8.7, 3.7]];

    private const TREE_TOP_LEFT_IN = 0.9;

    private const TREE_TOP_WIDTH_IN = 3.0;

    private const TREE_TOP_HEIGHT_IN = 1.4;

    /** 依頼CP-3: 中身がURLだけ(ページ名・見出しが無い)のときの箱の高さと、転送の一行ぶんの加算。 */
    private const TREE_TOP_COMPACT_HEIGHT_IN = 0.5;

    private const TREE_TOP_NOTE_LINE_IN = 0.2;

    /** TOPから第1階層への幹の位置と、第1階層の箱。 */
    private const TREE_TRUNK_X_IN = 4.2;

    private const TREE_BRANCH_LEFT_IN = 4.5;

    private const TREE_BRANCH_WIDTH_IN = 3.0;

    private const TREE_BRANCH_BOX_HEIGHT_IN = 0.44;

    /** 第1階層の箱から第2階層への括弧線の位置と、第2階層のページ名の列。 */
    private const TREE_BRACKET_X_IN = 7.8;

    private const TREE_PAGE_LEFT_IN = 8.0;

    private const TREE_PAGE_WIDTH_IN = 4.4;

    /** 第2階層の1行の高さ(8ptの文字を行間固定せず、実機で重ならない値)。 */
    private const TREE_LINE_PITCH_IN = 0.135;

    private const TREE_ROW_GAP_IN = 0.03;

    /** 点線の枝(追加を検討したい導線)の1件の枠の高さと間隔。 */
    private const TREE_RECOMMENDED_ITEM_HEIGHT_IN = 0.22;

    private const TREE_RECOMMENDED_PITCH_IN = 0.28;

    /**
     * 依頼CF追補(2026-09-30、必須修正): axis_unread_caveat
     * (config('brand_wheel.axis_unread_caveat')、4か所で共有している
     * 「絶対に消してはいけない文言」)は、可変レイアウト(枝・推奨導線・
     * 参考内訳)の後ろに続けて描く方式だと、それらが多いときに描画自体を
     * 省略せざるを得なくなり、「枝が多い(=サイトが充実している健全な
     * ケース)ほど免責文が消える」という逆向きの不具合になっていた
     * (依頼者指摘)。実データによらず必ず描かれるよう、footer
     * (site_hierarchy_crawl_scope_note、y6.42)の直前の固定位置に置き、
     * 可変コンテンツがどれだけ増えても動かない・省略されないようにした。
     *
     * 収まらない場合は、可変コンテンツ側(site_hierarchy_branch_limit)を
     * 6→4へ引き下げて場所を確保した ―― 枝は「ほかN」に畳めるので情報が
     * 失われないが、この注意書きは畳めない・省略できないため、削るべきは
     * 枝の表示数の方(依頼者指定の方針)。4件までなら、枝の上限超過
     * (otherBranchCount>0による「ほかN」行、+0.2in)・推奨導線
     * (addHierarchyRecommendations、+0.68in)が両方とも最大の場合でも、
     * 2.05(HIERARCHY_BRANCHES_TOP_IN)+4×0.56(rowStep)+0.2+0.18
     * (HIERARCHY_RECOMMENDED_GAP_IN)+0.68=5.35inで、この固定位置(5.75)の
     * 手前0.40inの余白を残して収まることを計算・実機画像化で確認した
     * (旧6件では最大5.91inとなり、5.75inのこの位置と衝突していた)。
     */
    private const HIERARCHY_AXIS_CAVEAT_RULE_TOP_IN = 5.75;

    private const HIERARCHY_AXIS_CAVEAT_BOX_TOP_IN = 5.85;

    private const HIERARCHY_AXIS_CAVEAT_BOX_HEIGHT_IN = 0.5;

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
     * 依頼CL-1(2026-10-05): ブランド・ホイール比較。横3列 ――
     * 左=自社(企業名・合計点・大きなヘキサゴン、頂点に領域名と数値)、
     * 中央=競合(1社ごとに小さなヘキサゴン＋企業名＋合計点)、右=領域別の
     * 発信量の表(6領域＋合計)。値は既存の$data['companies']/$data['axes']
     * (AdminComparisonPptxDataBuilderが計算済み)をそのまま使い、ここで新しい
     * 集計は行わない(依頼者指定)。
     *
     * 依頼CM-4: 競合が1〜3社のときは表の見出しに企業名を出し、中央の列にも
     * 記号を付けない。4〜5社のときは記号(自社/A〜E)で、中央の列に同じ記号を
     * 付ける。
     *
     * @param  array{
     *     self_company_name: string,
     *     self_readable: bool,
     *     companies: list<array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}>,
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
        // 依頼CD-3: self_readable(MultiSiteReportViewModel::selfReadable、
        // 唯一の情報源)がfalseのとき、自社のヘキサゴン・数値表セルを
        // 「0/24」等の数字ではなく専用の文言に差し替える(config
        // ('admin_comparison_pptx.self_data_unavailable_notice')docblock参照)。
        $selfReadable = $data['self_readable'] ?? true;
        $layout = $this->wheelLayout(count($data['companies']) - 1);

        return $this->renderSingleSlide(function (Slide $slide) use ($data, $selfReadable, $layout): void {
            $this->addKicker($slide);
            $this->addTitle($slide, 'ブランド・ホイール比較');
            $this->addWheelColumnHeadings($slide, $layout);
            $this->addWheelSelfColumn($slide, $data['companies'][0], $data['axes'], $selfReadable);
            $this->addWheelCompetitorColumn($slide, array_slice($data['companies'], 1), $data['axes'], $layout);
            $this->addMatrixSection($slide, $data['companies'], $data['axes'], $selfReadable, $layout);
            if (($data['top_message_missing_note'] ?? null) !== null) {
                $this->addTopMessageMissingNote($slide, (string) $data['top_message_missing_note']);
            }
            $this->addFooter($slide, $data['source_note'], $data['page_number']);
        });
    }

    /**
     * 競合の社数に応じた列の寸法。config('admin_comparison_pptx.
     * wheel_names_max_competitors')社以下なら表の見出しに企業名を出す配置。
     *
     * @return array{names: bool, competitor_left: float, competitor_width: float, table_left: float, table_width: float, area_width: float, header_height: float}
     */
    private function wheelLayout(int $competitorCount): array
    {
        return $competitorCount <= (int) config('admin_comparison_pptx.wheel_names_max_competitors')
            ? self::WHEEL_LAYOUT_NAMES
            : self::WHEEL_LAYOUT_SYMBOLS;
    }

    /**
     * 3列それぞれの見出しと、その下の細い罫線。
     *
     * @param  array{competitor_left: float, competitor_width: float, table_left: float, table_width: float}  $layout
     */
    private function addWheelColumnHeadings(Slide $slide, array $layout): void
    {
        $columns = [
            [self::WHEEL_SELF_LEFT_IN, self::WHEEL_SELF_WIDTH_IN, (string) config('admin_comparison_pptx.wheel_self_heading'), ''],
            [$layout['competitor_left'], $layout['competitor_width'], (string) config('admin_comparison_pptx.wheel_competitor_heading'), ''],
            [$layout['table_left'], $layout['table_width'], (string) config('admin_comparison_pptx.wheel_table_heading'), (string) config('admin_comparison_pptx.wheel_table_note')],
        ];

        foreach ($columns as [$left, $width, $heading, $note]) {
            $box = $slide->createRichTextShape();
            $this->position($box, $left, self::WHEEL_HEADING_TOP_IN, $width, self::WHEEL_HEADING_HEIGHT_IN);
            $box->setInsetLeft(0)->setInsetRight(0);
            $box->setWrap(RichText::WRAP_SQUARE);
            $para = $box->getActiveParagraph();
            $this->font($para->createTextRun($heading), 10.5, true, self::NAVY);
            if ($note !== '') {
                $this->font($para->createTextRun('　'.$note), 8, false, self::MUTED);
            }

            $rule = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
            $this->position($rule, $left, self::WHEEL_HEADING_TOP_IN + self::WHEEL_HEADING_HEIGHT_IN, $width, 0.012);
            $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::RULE));
            $rule->getBorder()->setLineStyle(Border::LINE_NONE);
        }
    }

    /**
     * 左の列: 自社。企業名(最大2行)→合計点→大きなヘキサゴン(頂点に領域名と
     * 数値)。依頼CD-3(判定不成立)・依頼CH-1b(材料不足)のときは、合計点・
     * ヘキサゴン・頂点ラベルを描かず、この領域全体を専用の文言1つに置き換える
     * (0という数字を判定結果であるかのように見せない)。
     *
     * @param  array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}  $company
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     */
    private function addWheelSelfColumn(Slide $slide, array $company, array $axes, bool $selfReadable): void
    {
        $left = self::WHEEL_SELF_LEFT_IN;
        $width = self::WHEEL_SELF_WIDTH_IN;
        $cx = $left + $width / 2;
        $radius = self::WHEEL_SELF_RADIUS_IN;

        $nameBox = $slide->createRichTextShape();
        $this->position($nameBox, $left, self::WHEEL_BODY_TOP_IN, $width, self::WHEEL_SELF_NAME_HEIGHT_IN);
        $nameBox->setWrap(RichText::WRAP_SQUARE);
        $nameBox->setInsetLeft(0)->setInsetRight(0);
        $nameBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $nameBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->renderCompanyName($nameBox->getActiveParagraph(), $company['name'], $width, 13.0, true, self::NAVY);

        $scoreTop = self::WHEEL_BODY_TOP_IN + self::WHEEL_SELF_NAME_HEIGHT_IN;
        $scoreBottom = $scoreTop + self::WHEEL_SELF_SCORE_HEIGHT_IN;
        $hexCenterY = $scoreBottom + self::WHEEL_LABEL_RESERVE_IN + $radius;
        $bottom = $hexCenterY + $radius + self::WHEEL_LABEL_RESERVE_IN;

        $unavailable = ! $selfReadable || ! ($company['material_sufficient'] ?? true);
        if ($unavailable) {
            $noticeText = ! $selfReadable
                ? (string) config('admin_comparison_pptx.self_data_unavailable_notice')
                : (string) config('brand_wheel.insufficient_material_notice');
            $this->addCenteredNotice($slide, $noticeText, $left, $scoreTop, $width, $bottom - $scoreTop, 12.0);

            return;
        }

        $scoreBox = $slide->createRichTextShape();
        $this->position($scoreBox, $left, $scoreTop, $width, self::WHEEL_SELF_SCORE_HEIGHT_IN);
        $scoreBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $scoreBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->font($scoreBox->getActiveParagraph()->createTextRun("{$company['matched']} / {$company['total']}"), 20, true, self::COPPER);

        $axisCounts = array_map(fn (array $axis) => [$axis['self_count'], $axis['denominator']], $axes);
        $this->drawBrandWheelHexagon($slide, $cx, $hexCenterY, $radius, $axisCounts, self::NAVY, 2.0);
        $this->addWheelAxisLabels($slide, $cx, $hexCenterY, $radius, $axes);
    }

    /**
     * 中央の列: 競合。1〜3社(wheel_names_max_competitors以下)は縦1列(企業名を上に、
     * その下にヘキサゴンと合計点)、4〜5社は2列(記号つきの企業名→ヘキサゴン→
     * 合計点を縦に積む)。4〜5社の記号(A〜E)は右の表のヘッダーと同じ。
     *
     * @param  list<array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}>  $competitors
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     * @param  array{names: bool, competitor_left: float, competitor_width: float}  $layout
     */
    private function addWheelCompetitorColumn(Slide $slide, array $competitors, array $axes, array $layout): void
    {
        $count = count($competitors);
        if ($count === 0) {
            return;
        }

        $twoColumns = ! $layout['names'];
        $rows = $twoColumns ? (int) ceil($count / 2) : $count;
        $rowHeight = min(self::WHEEL_COMPETITOR_ROW_MAX_HEIGHT_IN, (self::WHEEL_BODY_BOTTOM_IN - self::WHEEL_BODY_TOP_IN) / $rows);

        foreach ($competitors as $i => $company) {
            $row = $twoColumns ? intdiv($i, 2) : $i;
            $top = self::WHEEL_BODY_TOP_IN + $row * $rowHeight;
            $left = $layout['competitor_left'] + ($twoColumns ? ($i % 2) * self::WHEEL_COMPETITOR_DOUBLE_TILE_WIDTH_IN : 0.0);

            if ($twoColumns) {
                $this->addCompactCompetitorTile($slide, $company, $axes, $i, $left, $top, $rowHeight);
            } else {
                $this->addNamedCompetitorTile($slide, $company, $axes, $i, $left, $top, $rowHeight, $layout['competitor_width']);
            }
        }
    }

    /**
     * 縦1列のとき: 企業名(記号は付けない、表の見出しも企業名)を上に、その下に
     * ヘキサゴンと合計点を並べる。材料不足(依頼CH-1b)のときは、ヘキサゴンと
     * 合計点を描かず、企業名の下に専用の文言を置く。
     *
     * @param  array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}  $company
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     */
    private function addNamedCompetitorTile(Slide $slide, array $company, array $axes, int $index, float $left, float $top, float $rowHeight, float $width): void
    {
        $nameHeight = 0.5;
        $this->addCompetitorNameBox($slide, $company['name'], '', $left, $top, $width, $nameHeight, 9.0, Alignment::HORIZONTAL_LEFT);

        if (! ($company['material_sufficient'] ?? true)) {
            $this->addCenteredNotice($slide, (string) config('brand_wheel.insufficient_material_notice'), $left, $top + $nameHeight + 0.05, $width, max(0.3, $rowHeight - $nameHeight - 0.1), 8.0, Alignment::HORIZONTAL_LEFT);

            return;
        }

        $radius = self::WHEEL_COMPETITOR_SINGLE_RADIUS_IN;
        $centerY = $top + $nameHeight + 0.05 + $radius;
        $axisCounts = array_map(fn (array $axis) => [$axis['competitor_counts'][$index] ?? 0, $axis['denominator']], $axes);
        $this->drawBrandWheelHexagon($slide, $left + $radius + 0.05, $centerY, $radius, $axisCounts, self::COPPER, 1.25);

        $scoreBox = $slide->createRichTextShape();
        $this->position($scoreBox, $left + 0.95, $centerY - 0.15, $width - 0.95, 0.3);
        $scoreBox->setInsetLeft(0)->setInsetRight(0);
        $scoreBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->font($scoreBox->getActiveParagraph()->createTextRun("{$company['matched']} / {$company['total']}"), 11, true, self::NAVY);
    }

    /**
     * 2列のとき(4〜5社): 記号＋企業名(最大2行)→ヘキサゴン→合計点を縦に積む。
     *
     * @param  array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}  $company
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     */
    private function addCompactCompetitorTile(Slide $slide, array $company, array $axes, int $index, float $left, float $top, float $rowHeight): void
    {
        $width = self::WHEEL_COMPETITOR_DOUBLE_TILE_WIDTH_IN - 0.05;
        $radius = self::WHEEL_COMPETITOR_DOUBLE_RADIUS_IN;
        $nameHeight = 0.5;

        $this->addCompetitorNameBox($slide, $company['name'], $this->competitorSymbol($index), $left, $top, $width, $nameHeight, 8.0, Alignment::HORIZONTAL_CENTER);

        if (! ($company['material_sufficient'] ?? true)) {
            $this->addCenteredNotice($slide, (string) config('brand_wheel.insufficient_material_notice'), $left, $top + $nameHeight + 0.02, $width, max(0.3, $rowHeight - $nameHeight - 0.1), 8.0);

            return;
        }

        $centerY = $top + $nameHeight + 0.04 + $radius;
        $axisCounts = array_map(fn (array $axis) => [$axis['competitor_counts'][$index] ?? 0, $axis['denominator']], $axes);
        $this->drawBrandWheelHexagon($slide, $left + $width / 2, $centerY, $radius, $axisCounts, self::COPPER, 1.0);

        $scoreBox = $slide->createRichTextShape();
        $this->position($scoreBox, $left, $centerY + $radius + 0.02, $width, 0.24);
        $scoreBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->font($scoreBox->getActiveParagraph()->createTextRun("{$company['matched']} / {$company['total']}"), 11, true, self::NAVY);
    }

    /**
     * 競合の企業名(記号を使うときは先頭に「A　」)。企業名は語の切れ目で最大3行に
     * 折り(renderCompanyName、依頼CM-3)、収まらない極端に長い名前だけ省略記号。
     */
    private function addCompetitorNameBox(Slide $slide, string $name, string $symbol, float $left, float $top, float $width, float $height, float $sizePt, string $horizontal): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, $left, $top, $width, $height);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setInsetLeft(0)->setInsetRight(0);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $para = $box->getActiveParagraph();
        $para->getAlignment()->setHorizontal($horizontal);

        $this->renderCompanyName($para, $name, $width, $sizePt, true, self::NAVY, $symbol === '' ? '' : $symbol.'　', self::COPPER, 3);
    }

    /** 競合の記号(0番目=A)。表のヘッダーと中央の列で共通。 */
    private function competitorSymbol(int $index): string
    {
        $symbols = (string) config('admin_comparison_pptx.wheel_competitor_symbols');

        return mb_substr($symbols, $index, 1) !== '' ? mb_substr($symbols, $index, 1) : (string) ($index + 1);
    }

    /**
     * 数字の代わりに出す文言(判定不成立・材料不足)を、指定の領域の中央に置く。
     */
    private function addCenteredNotice(Slide $slide, string $text, float $left, float $top, float $width, float $height, float $sizePt, string $horizontal = Alignment::HORIZONTAL_CENTER): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, $left, $top, $width, $height);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $para = $box->getActiveParagraph();
        $para->getAlignment()->setHorizontal($horizontal);
        $this->font($para->createTextRun($text), $sizePt, true, self::GAP_TEXT);
    }

    /**
     * 依頼CC-1(必須)・依頼CL-1: 六角形の外側に6領域のラベル(領域名と数値、
     * 例「活動的魅力 4/4」)を置く。領域名はconfig('brand_wheel.axes.*.name_ja')
     * 由来($axes[*]['name'])で、頂点の並び順はdrawBrandWheelHexagon()の
     * hexVertex()と全く同じ($axes[k]が頂点kに対応する)ため、右の表の行順
     * (同じ$axes)と必ず一致する。
     *
     * @param  list<array{name: string, denominator: int, self_count: int}>  $axes  6領域ぶん、config('brand_wheel.axes')の順
     */
    private function addWheelAxisLabels(Slide $slide, float $cx, float $cy, float $radius, array $axes): void
    {
        $labelRadius = $radius + self::WHEEL_LABEL_MARGIN_IN;
        $w = self::WHEEL_LABEL_WIDTH_IN;
        $h = self::WHEEL_LABEL_HEIGHT_IN;

        foreach ($axes as $k => $axis) {
            [$x, $y] = $this->hexVertex($cx, $cy, $labelRadius, $k);
            $box = $slide->createRichTextShape();
            $box->setInsetLeft(0)->setInsetRight(0);

            // k=0(真上)は中央揃えで上、k=3(真下)は中央揃えで下、それ以外は
            // 頂点が左右どちら側にあるかで揃えを変え、文字が図に重ならず
            // 外側へ伸びるようにする。
            if ($k === 0) {
                $this->position($box, $x - $w / 2, $y - $h, $w, $h);
                $align = Alignment::HORIZONTAL_CENTER;
            } elseif ($k === 3) {
                $this->position($box, $x - $w / 2, $y, $w, $h);
                $align = Alignment::HORIZONTAL_CENTER;
            } elseif ($x > $cx) {
                $this->position($box, $x, $y - $h / 2, $w, $h);
                $align = Alignment::HORIZONTAL_LEFT;
            } else {
                $this->position($box, $x - $w, $y - $h / 2, $w, $h);
                $align = Alignment::HORIZONTAL_RIGHT;
            }

            $box->getActiveParagraph()->getAlignment()->setHorizontal($align);
            $this->font($box->getActiveParagraph()->createTextRun($axis['name'].' '), self::WHEEL_LABEL_FONT_SIZE, true, self::NAVY);
            $this->font($box->getActiveParagraph()->createTextRun("{$axis['self_count']}/{$axis['denominator']}"), self::WHEEL_LABEL_FONT_SIZE, true, self::COPPER);
        }
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

    /**
     * 長方形の枠線。PhpPresentationのAutoShapeは枠線の色・太さ・点線を書き出さない
     * (常に枠なし)ため、直線4本で描く(外部参照を持たない)。
     */
    private function drawRectOutline(Slide $slide, float $left, float $top, float $width, float $height, string $colorRgb, float $lineWidthPt, ?string $dashStyle = null): void
    {
        $tl = [$left, $top];
        $tr = [$left + $width, $top];
        $br = [$left + $width, $top + $height];
        $bl = [$left, $top + $height];
        $this->drawLine($slide, $tl, $tr, $colorRgb, $lineWidthPt, $dashStyle);
        $this->drawLine($slide, $tr, $br, $colorRgb, $lineWidthPt, $dashStyle);
        $this->drawLine($slide, $br, $bl, $colorRgb, $lineWidthPt, $dashStyle);
        $this->drawLine($slide, $bl, $tl, $colorRgb, $lineWidthPt, $dashStyle);
    }

    private function drawLine(Slide $slide, array $from, array $to, string $colorRgb, float $lineWidthPt, ?string $dashStyle = null): void
    {
        $line = $slide->createLineShape(
            (int) round($from[0] * self::PX_PER_INCH),
            (int) round($from[1] * self::PX_PER_INCH),
            (int) round($to[0] * self::PX_PER_INCH),
            (int) round($to[1] * self::PX_PER_INCH),
        );
        $line->getBorder()->setLineWidth($lineWidthPt)->setColor(new Color('FF'.$colorRgb));
        if ($dashStyle !== null) {
            $line->getBorder()->setDashStyle($dashStyle);
        }
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
     * 'definition')、24項目の構成の説明はblade側のintrobody文言を、
     * 文言を書き換えずそのまま使う。
     *
     * 依頼CF-5②(2026-09-29): 注意書き(config('brand_wheel.
     * axis_unread_caveat'))は、以前このページの末尾に置いていたが、
     * 「24項目の説明ページ」という文脈でこの一文(診断結果に対する断り書き)
     * を読んでも何の話か伝わらなかった(依頼者指摘、実機画像化で確認)。
     * generateSiteHierarchySlide()(4枚目、実質最後の内容ページ)の末尾へ
     * 移した(addAxisUnreadCaveat()参照) ―― 文言自体は変更していない。
     */
    public function generateExplanationSlide(): string
    {
        return $this->renderSingleSlide(function (Slide $slide): void {
            $this->addKicker($slide);
            $this->addTitle($slide, '採用ブランドの捉え方 ―― ブランド・ホイール');
            $this->addExplanationLead($slide);
            $this->addExplanationGroups($slide);
            $this->addExplanationComposition($slide);
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
        $this->normalizeVerticalCentering($slide);

        $writer = new PowerPoint2007($presentation);
        $tmpPath = tempnam(sys_get_temp_dir(), 'pptx');
        $writer->save($tmpPath);
        $bytes = (string) file_get_contents($tmpPath);
        unlink($tmpPath);

        return $bytes;
    }

    /**
     * 依頼CL(2026-10-05): RichText::setVerticalAlignCenter()は、書き出し時に
     * anchorCtr="1"(テキストのかたまりを水平方向にも中央に置く)になり、
     * 左揃え・右揃えの文字まで枠の中央へ寄ってしまう(実機画像化で発覚)。
     * 縦中央だけが欲しいので、書き出し前に、縦の位置はanchor="ctr"
     * (段落の縦揃え)で表し、anchorCtrは0に戻す。文字の左右の位置は各段落の
     * 水平揃え(明示的に指定したもの)だけで決まる。
     */
    private function normalizeVerticalCentering(Slide $slide): void
    {
        foreach ($slide->getShapeCollection() as $shape) {
            if (! $shape instanceof RichText || $shape->getVerticalAlignCenter() !== RichText::VALIGN_CENTER) {
                continue;
            }

            $shape->setVerticalAlignCenter(RichText::VALIGN_NOTCENTER);
            foreach ($shape->getParagraphs() as $paragraph) {
                $paragraph->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            }
        }
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
     * 依頼CL-1(2026-10-05): 右の列の「領域別の発信量」表。6領域
     * (config('brand_wheel.axes')順)×(自社＋競合)に、末尾の「合計」の行
     * (新規、各社の○の数/24)を加える。分母は領域ごとに4固定のため必ず
     * 埋まる。
     *
     * 依頼CM-4(2026-10-06): ヘッダーは、競合が1〜3社のとき企業名(語の切れ目で
     * 折る、renderCompanyName)、4〜5社のとき記号(自社/A〜E ―― 列が最大6に
     * なっても幅が崩れないようにするため、中央の列に同じ記号を付ける)。
     *
     * @param  list<array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}>  $companies
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     * @param  array{names: bool, table_left: float, table_width: float, area_width: float, header_height: float}  $layout
     */
    private function addMatrixSection(Slide $slide, array $companies, array $axes, bool $selfReadable, array $layout): void
    {
        $tableTop = self::WHEEL_BODY_TOP_IN + 0.2;
        $companyCount = count($companies);
        $colWidth = ($companyCount > 0) ? ($layout['table_width'] - $layout['area_width']) / $companyCount : 0;

        $this->addMatrixHeader($slide, $companies, $colWidth, $tableTop, $layout);

        $rowsTop = $tableTop + $layout['header_height'];
        foreach ($axes as $i => $axis) {
            $top = $rowsTop + $i * self::TABLE_ROW_HEIGHT_IN;
            $this->addMatrixRow($slide, $axis, $companies, $colWidth, $top, $i % 2 === 1, $selfReadable, $layout);
        }

        $totalTop = $rowsTop + count($axes) * self::TABLE_ROW_HEIGHT_IN;
        $this->addMatrixTotalRow($slide, $companies, $colWidth, $totalTop, $selfReadable, $layout);

        $this->addLegend($slide, $totalTop + self::TABLE_ROW_HEIGHT_IN + 0.15, $companyCount - 1, $layout);
    }

    /**
     * 依頼BN-3(2026-09-09): オレンジの網かけ・競合内の最高値の太字が
     * 何を意味するか、スライドのどこにも説明が無かった(初見の商談相手には
     * 伝わらない、依頼者指摘)。表の下に凡例を置く。依頼CL-1で、列が記号
     * (自社/A〜)になったため、記号の説明を加えた。依頼CM-4で、記号を使う
     * (4〜5社の)ときだけ、その注記を出す(企業名を見出しにするときは出さない)。
     *
     * 濃淡(競合内の最高値を太字にする表現)は残す判断とした(依頼者の
     * 推し・依頼BN-3参照)。全社が同値の行では該当する競合全員が太字に
     * なるが、これは「その領域の競合内最高値」という凡例の説明どおりの
     * 正しい表示であり、誤りではないため。
     *
     * @param  array{names: bool, table_left: float, table_width: float}  $layout
     */
    private function addLegend(Slide $slide, float $top, int $competitorCount, array $layout): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, $layout['table_left'], $top, $layout['table_width'], 0.75);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setInsetLeft(0)->setInsetRight(0);

        $para = $box->getActiveParagraph();
        $this->font($para->createTextRun('■'), 8, false, self::GAP_TEXT);
        $this->font($para->createTextRun((string) config('admin_comparison_pptx.wheel_legend_gap')), 8, false, self::MUTED);

        $para2 = $box->createParagraph();
        $this->font($para2->createTextRun('■'), 8, true, self::NAVY);
        $this->font($para2->createTextRun((string) config('admin_comparison_pptx.wheel_legend_max')), 8, false, self::MUTED);

        if ($competitorCount > 0) {
            $para3 = $box->createParagraph();
            if (! $layout['names']) {
                $range = $this->competitorSymbol(0).($competitorCount > 1 ? '〜'.$this->competitorSymbol($competitorCount - 1) : '');
                $this->font($para3->createTextRun(sprintf((string) config('admin_comparison_pptx.wheel_legend_symbols'), $range)), 8, false, self::MUTED);
            }
            $this->font($para3->createTextRun((string) config('admin_comparison_pptx.wheel_legend_axis_order')), 8, false, self::MUTED);
        }
    }

    /**
     * @param  list<array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}>  $companies
     * @param  array{names: bool, table_left: float, table_width: float, area_width: float, header_height: float}  $layout
     */
    private function addMatrixHeader(Slide $slide, array $companies, float $colWidth, float $top, array $layout): void
    {
        $band = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($band, $layout['table_left'], $top, $layout['table_width'], $layout['header_height']);
        $band->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::NAVY));
        $band->getBorder()->setLineStyle(Border::LINE_NONE);

        $areaBox = $slide->createRichTextShape();
        $this->position($areaBox, $layout['table_left'] + 0.1, $top, $layout['area_width'] - 0.1, $layout['header_height']);
        $areaBox->setInsetLeft(0)->setInsetRight(0);
        $areaBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($areaBox->getActiveParagraph()->createTextRun('領域'), 9, true, self::WHITE);

        foreach ($companies as $i => $company) {
            $left = $layout['table_left'] + $layout['area_width'] + $i * $colWidth;
            $box = $slide->createRichTextShape();
            $this->position($box, $left, $top, $colWidth, $layout['header_height']);
            $box->setInsetLeft(0)->setInsetRight(0);
            $box->setWrap(RichText::WRAP_SQUARE);
            $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $para = $box->getActiveParagraph();
            $para->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            if ($company['is_self']) {
                $this->font($para->createTextRun((string) config('admin_comparison_pptx.wheel_table_self_header')), 9, true, self::WHITE);
            } elseif ($layout['names']) {
                // 列の幅に収まる大きさ(下限まで)で、語の切れ目で折る。
                $this->renderCompanyName($para, $company['name'], $colWidth, 8.0, true, self::WHITE, '', null, 3);
            } else {
                $this->font($para->createTextRun($this->competitorSymbol($i - 1)), 9, true, self::WHITE);
            }
        }
    }

    /**
     * @param  array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}  $axis
     * @param  list<array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}>  $companies
     * @param  array{table_left: float, table_width: float, area_width: float, area_font: int}  $layout
     */
    private function addMatrixRow(Slide $slide, array $axis, array $companies, float $colWidth, float $top, bool $isBanded, bool $selfReadable, array $layout): void
    {
        if ($isBanded) {
            $band = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
            $this->position($band, $layout['table_left'], $top, $layout['table_width'], self::TABLE_ROW_HEIGHT_IN);
            $band->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::BAND));
            $band->getBorder()->setLineStyle(Border::LINE_NONE);
        }

        $rule = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($rule, $layout['table_left'], $top + self::TABLE_ROW_HEIGHT_IN - 0.006, $layout['table_width'], 0.006);
        $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::RULE));
        $rule->getBorder()->setLineStyle(Border::LINE_NONE);

        $areaNameBox = $slide->createRichTextShape();
        $this->position($areaNameBox, $layout['table_left'] + 0.1, $top, $layout['area_width'] - 0.1, self::TABLE_ROW_HEIGHT_IN);
        $areaNameBox->setInsetLeft(0)->setInsetRight(0);
        $areaNameBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($areaNameBox->getActiveParagraph()->createTextRun($axis['name']), $layout['area_font'], true, self::BODY_TEXT);

        $maxCompetitor = $axis['competitor_counts'] === [] ? 0 : max($axis['competitor_counts']);

        foreach ($companies as $i => $company) {
            $left = $layout['table_left'] + $layout['area_width'] + $i * $colWidth;
            $count = $company['is_self'] ? $axis['self_count'] : ($axis['competitor_counts'][$i - 1] ?? 0);

            // 依頼CD-3(必須): 自社が判定不能(selfReadable===false)のとき、
            // このセルは「0 / 4」という数字ではなく「－」を出す ―― 0を
            // 判定結果であるかのように見せないため。自社が競合の最高値
            // 未達を示すオレンジの網かけ(self_gap)も、実際には判定していない
            // ため付けない(「未達」自体が判定結果の一種であり、判定不能とは
            // 意味が異なる)。
            //
            // 依頼CH-1b(2026-10-01): status不成立(上記)とは独立に、材料
            // (company['material_sufficient'])が閾値未満のときも同じく
            // 「－」にする ―― 自社・競合の両方が対象。
            $unavailable = ($company['is_self'] && ! $selfReadable) || ! ($company['material_sufficient'] ?? true);

            if ($unavailable) {
                [$bg, $fg] = [null, self::DIM];
            } elseif ($company['is_self'] && $axis['self_gap']) {
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
            $cell->setInsetLeft(0)->setInsetRight(0);
            $cell->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $cellText = $unavailable ? '－' : "{$count}/{$axis['denominator']}";
            $this->font($cell->getActiveParagraph()->createTextRun($cellText), 9, true, $fg);
        }
    }

    /**
     * 依頼CL-1(新規): 表の末尾の「合計」の行(各社の○の数/24
     * (company['matched']/['total'] ―― 左の列・中央の列の合計点と同じ値))。
     * 判定不成立・材料不足の会社は「－」(領域別のセルと同じ扱い)。
     *
     * @param  list<array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}>  $companies
     * @param  array{table_left: float, table_width: float, area_width: float}  $layout
     */
    private function addMatrixTotalRow(Slide $slide, array $companies, float $colWidth, float $top, bool $selfReadable, array $layout): void
    {
        $rule = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($rule, $layout['table_left'], $top, $layout['table_width'], 0.012);
        $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::NAVY));
        $rule->getBorder()->setLineStyle(Border::LINE_NONE);

        $labelBox = $slide->createRichTextShape();
        $this->position($labelBox, $layout['table_left'] + 0.1, $top, $layout['area_width'] - 0.1, self::TABLE_ROW_HEIGHT_IN);
        $labelBox->setInsetLeft(0)->setInsetRight(0);
        $labelBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($labelBox->getActiveParagraph()->createTextRun((string) config('admin_comparison_pptx.wheel_table_total_label')), 9, true, self::NAVY);

        foreach ($companies as $i => $company) {
            $left = $layout['table_left'] + $layout['area_width'] + $i * $colWidth;
            $unavailable = ($company['is_self'] && ! $selfReadable) || ! ($company['material_sufficient'] ?? true);

            if ($company['is_self'] && ! $unavailable) {
                $cellBg = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
                $this->position($cellBg, $left, $top + 0.012, $colWidth, self::TABLE_ROW_HEIGHT_IN - 0.012);
                $cellBg->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::SELF_TINT));
                $cellBg->getBorder()->setLineStyle(Border::LINE_NONE);
            }

            $cell = $slide->createRichTextShape();
            $this->position($cell, $left, $top, $colWidth, self::TABLE_ROW_HEIGHT_IN);
            $cell->setInsetLeft(0)->setInsetRight(0);
            $cell->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $text = $unavailable ? '－' : "{$company['matched']}/{$company['total']}";
            $color = $unavailable ? self::DIM : ($company['is_self'] ? self::COPPER : self::NAVY);
            $this->font($cell->getActiveParagraph()->createTextRun($text), 9, true, $color);
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
     *     self_readable: bool,
     *     self_material_sufficient: bool,
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

            // 依頼CD-3(必須): missing_items['items']はBrandWheelMultiSite
            // ComparisonComposer::extractMissingFromSelf()が
            // 「!self_matched && 競合が過半数一致」で抽出したものだが、
            // 自社が判定不能(self_readable===false)のときself_matchedは
            // 24項目すべてfalseになるため、この抽出条件をそのまま表示すると
            // 「自社に足りない項目」として実質ほぼ全項目が並ぶ、意味の異なる
            // 誤解を招く一覧になってしまう(抽出条件自体は変更しない、
            // 依頼者指定 ―― ここでは単に表示しないだけ)。専用の文言に
            // 差し替える。
            //
            // 依頼CH-1b(2026-10-01): 自社が材料不足(self_material_sufficient
            // ===false)のときも同様 ―― status=successで抽出条件自体は動く
            // ものの、材料が空同然で抽出結果の信頼性が無いため。「足りない
            // もの」は自社視点の一覧のため対象は自社のみ(競合が材料不足でも
            // このスライドの前提(自社から見て何が足りないか)は崩れない)。
            if (! ($data['self_readable'] ?? true)) {
                $this->addSelfUnavailableNotice($slide, (string) config('admin_comparison_pptx.self_data_unavailable_notice'));
                $this->addNoteFooter($slide, $data['candidate_survey_source_note']);

                return;
            }

            if (! ($data['self_material_sufficient'] ?? true)) {
                $this->addSelfUnavailableNotice($slide, (string) config('brand_wheel.insufficient_material_notice'));
                $this->addNoteFooter($slide, $data['candidate_survey_source_note']);

                return;
            }

            $this->addMissingItemsHeading($slide, $data['missing_items']);
            $this->addMissingItemsRows($slide, $data['missing_items']);
            $this->addNoteFooter($slide, $data['candidate_survey_source_note']);
        });
    }

    /**
     * 依頼CD-3: 自社のブランド・ホイール判定が成立していないことを伝える
     * 文言を、タイトル下いっぱいに1つだけ表示する。
     *
     * 依頼CH-1b(2026-10-01): status不成立(config('admin_comparison_pptx.
     * self_data_unavailable_notice'))と材料不足(config('brand_wheel.
     * insufficient_material_notice'))の2条件で文言が異なるため、呼び出し元が
     * 条件に応じた文言を渡す(このメソッド自体は表示だけを担う)。
     */
    private function addSelfUnavailableNotice(Slide $slide, string $notice): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, 2.6, self::CONTENT_WIDTH_IN, 1.2);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $para = $box->getActiveParagraph();
        $para->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->font($para->createTextRun($notice), 14, true, self::GAP_TEXT);
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

        // 依頼CC-2②(必須): 何と何を突き合わせているかが分かる説明を冒頭に
        // 置く(依頼者指摘、旧版は各行にconfigの数字だけが並び、突き合わせの
        // 主旨が書かれていなかった)。
        $introBox = $slide->createRichTextShape();
        $this->position($introBox, self::LEFT_IN, self::MISSING_HEADING_TOP_IN + 0.32, self::CONTENT_WIDTH_IN, self::MISSING_INTRO_HEIGHT_IN);
        $introBox->setWrap(RichText::WRAP_SQUARE);
        $this->font($introBox->getActiveParagraph()->createTextRun((string) config('admin_comparison_pptx.missing_items_intro')), 10, false, self::MUTED);
    }

    /**
     * @param  array{items: list<array{axis_name: string, sub_name: string, region: string, impact: string, candidate_survey: array{item: ?string, percentage: ?float}}>, others_count: int}  $missingItems
     */
    private function addMissingItemsRows(Slide $slide, array $missingItems): void
    {
        $rowStep = self::MISSING_ROW_HEIGHT_IN + self::MISSING_ROW_GAP_IN;
        $textWidth = self::CONTENT_WIDTH_IN - 0.1;

        // 依頼CC-2①: 行内の3ブロックの高さ配分(名前0.26in/一文0.40in/
        // 候補者調査0.22in、計0.88in=MISSING_ROW_HEIGHT_IN)。区切り線は
        // 行の直下ではなく、行間の余白(MISSING_ROW_GAP_IN)の中央に置く
        // ―― 万一テキストがブロックの高さを超えて伸びても、線までの
        // 距離だけ余分に確保できる(定数のdocblock参照)。
        $nameHeight = 0.26;
        $impactTop = 0.28;
        $impactHeight = 0.4;
        $surveyTop = $impactTop + $impactHeight;
        $surveyHeight = 0.22;

        foreach ($missingItems['items'] as $i => $item) {
            $top = self::MISSING_ROWS_TOP_IN + $i * $rowStep;

            $ruleY = $top + self::MISSING_ROW_HEIGHT_IN + self::MISSING_ROW_GAP_IN / 2;
            $rule = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
            $this->position($rule, self::LEFT_IN, $ruleY, self::CONTENT_WIDTH_IN, 0.008);
            $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::RULE));
            $rule->getBorder()->setLineStyle(Border::LINE_NONE);

            $nameBox = $slide->createRichTextShape();
            $this->position($nameBox, self::LEFT_IN, $top, $textWidth, $nameHeight);
            $namePara = $nameBox->getActiveParagraph();
            $this->font($namePara->createTextRun($item['sub_name']), 11.5, true, self::NAVY);
            $this->font($namePara->createTextRun('　［'.$item['region'].'］'), 8.5, false, self::MUTED);

            $impactBox = $slide->createRichTextShape();
            $this->position($impactBox, self::LEFT_IN, $top + $impactTop, $textWidth, $impactHeight);
            $impactBox->setWrap(RichText::WRAP_SQUARE);
            $this->font($impactBox->getActiveParagraph()->createTextRun($item['impact']), 9, false, self::BODY_TEXT);

            $surveyBox = $slide->createRichTextShape();
            $this->position($surveyBox, self::LEFT_IN, $top + $surveyTop, $textWidth, $surveyHeight);
            $surveyBox->setWrap(RichText::WRAP_SQUARE);
            // 依頼CC-2①②: 「〇〇%の求職者が求めているが、自社サイトでは
            // 確認できなかった」の順で因果が分かる形にする(依頼者指定)。
            // 該当なし(該当する候補者調査項目が無い)の行は、重要でないという
            // 意味ではないことを添える一言(missing_item_survey_none_text)。
            // 依頼CO-2: 文は、表と同じ計算の「調査の選択肢」単位の自社の状態に合わせる
            // (一部○なら「一部しか確認できませんでした」。×の1項目だけを見て言い切らない)。
            // 依頼CO-3: 割合は表と同じ整形(小数1桁)。
            $surveyTemplate = ($item['candidate_survey']['self_state'] ?? null) === 'partial'
                ? 'missing_item_survey_partial_template'
                : 'missing_item_survey_template';
            $surveyText = $item['candidate_survey']['item'] !== null
                ? sprintf(
                    (string) config("admin_comparison_pptx.{$surveyTemplate}"),
                    $item['candidate_survey']['item'],
                    $this->formatSurveyPercentage((float) $item['candidate_survey']['percentage']),
                )
                : (string) config('admin_comparison_pptx.missing_item_survey_none_text');
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
     * 依頼CL-2(2026-10-05): 「求職者が知りたい情報と、自社サイト」。
     * 調査の選択肢(割合の高い順)を1行ずつ並べ、自社サイトの状態(確認できた
     * /一部確認できた/確認できず/判定の対象外)と、競合の掲載社数を添える。
     * 「足りないもの」(競合との比較で項目を選ぶ)とは逆に、アンケートを軸に
     * する。「確認できず」の行は色で強調する。割合は長方形の横棒で示す
     * (画像は使わない ―― 差し込みの仕組みが外部参照を拒否するため)。
     *
     * 依頼CD-3/CH-1b: 自社が判定不成立・材料不足のときは、表の代わりに
     * 既存の専用文言を出す(「足りないもの」と同じ扱い)。
     *
     * @param  array{
     *     self_readable: bool,
     *     self_material_sufficient: bool,
     *     survey_comparison: array{rows: list<array{rank: int, key: string, name: string, percentage: float, self_state: string, mapped_count: int, self_matched_count: int, competitor_count: ?int}>, competitor_total: int, excluded_competitor_count: int},
     *     candidate_survey_source_note: string,
     * } $data
     */
    public function generateSurveyComparisonSlide(array $data): string
    {
        return $this->renderSingleSlide(function (Slide $slide) use ($data): void {
            $this->addKicker($slide);
            $this->addTitle($slide, (string) config('admin_comparison_pptx.survey_comparison_title'));

            if (! ($data['self_readable'] ?? true)) {
                $this->addSelfUnavailableNotice($slide, (string) config('admin_comparison_pptx.self_data_unavailable_notice'));
                $this->addNoteFooter($slide, $data['candidate_survey_source_note']);

                return;
            }

            if (! ($data['self_material_sufficient'] ?? true)) {
                $this->addSelfUnavailableNotice($slide, (string) config('brand_wheel.insufficient_material_notice'));
                $this->addNoteFooter($slide, $data['candidate_survey_source_note']);

                return;
            }

            $comparison = $data['survey_comparison'];

            $intro = $slide->createRichTextShape();
            $this->position($intro, self::LEFT_IN, self::SURVEY_INTRO_TOP_IN, self::CONTENT_WIDTH_IN, 0.3);
            $intro->setInsetLeft(0)->setInsetRight(0);
            $intro->setWrap(RichText::WRAP_SQUARE);
            $this->font($intro->getActiveParagraph()->createTextRun((string) config('admin_comparison_pptx.survey_comparison_intro')), 10, false, self::MUTED);

            $this->addSurveyHeader($slide);
            $this->addSurveyRows($slide, $comparison);
            $this->addSurveyNotes($slide, $comparison, $data['candidate_survey_source_note']);
        });
    }

    private function addSurveyHeader(Slide $slide): void
    {
        $top = self::SURVEY_TABLE_TOP_IN;
        $band = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($band, self::LEFT_IN, $top, self::CONTENT_WIDTH_IN, self::SURVEY_HEADER_HEIGHT_IN);
        $band->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::NAVY));
        $band->getBorder()->setLineStyle(Border::LINE_NONE);

        $headers = (array) config('admin_comparison_pptx.survey_comparison_headers');
        foreach (['rank', 'name', 'percentage', 'self', 'competitor'] as $key) {
            [$left, $width] = self::SURVEY_COLUMNS[$key];
            $box = $slide->createRichTextShape();
            $this->position($box, $left, $top, $width, self::SURVEY_HEADER_HEIGHT_IN);
            $box->setInsetLeft(0)->setInsetRight(0);
            $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            if ($key === 'rank') {
                $box->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $this->font($box->getActiveParagraph()->createTextRun((string) ($headers[$key] ?? '')), 9, true, self::WHITE);
        }
    }

    /**
     * @param  array{rows: list<array{rank: int, key: string, name: string, percentage: float, self_state: string, mapped_count: int, self_matched_count: int, competitor_count: ?int}>, competitor_total: int, excluded_competitor_count: int}  $comparison
     */
    private function addSurveyRows(Slide $slide, array $comparison): void
    {
        $rows = $comparison['rows'];
        $maxPercentage = $rows === [] ? 0.0 : max(array_column($rows, 'percentage'));
        $labels = (array) config('admin_comparison_pptx.survey_comparison_state_labels');
        $symbols = (array) config('admin_comparison_pptx.survey_comparison_state_symbols');
        [$barLeft, $barAreaWidth] = self::SURVEY_COLUMNS['percentage'];
        $barMaxWidth = $barAreaWidth - 0.85;

        foreach ($rows as $i => $row) {
            $top = self::SURVEY_TABLE_TOP_IN + self::SURVEY_HEADER_HEIGHT_IN + $i * self::SURVEY_ROW_HEIGHT_IN;
            $isGap = $row['self_state'] === 'unconfirmed';

            if ($isGap) {
                $this->addFilledRect($slide, self::LEFT_IN, $top, self::CONTENT_WIDTH_IN, self::SURVEY_ROW_HEIGHT_IN, self::GAP_BG);
            } elseif ($i % 2 === 1) {
                $this->addFilledRect($slide, self::LEFT_IN, $top, self::CONTENT_WIDTH_IN, self::SURVEY_ROW_HEIGHT_IN, self::BAND);
            }
            $this->addFilledRect($slide, self::LEFT_IN, $top + self::SURVEY_ROW_HEIGHT_IN - 0.006, self::CONTENT_WIDTH_IN, 0.006, self::RULE);

            $this->addSurveyCell($slide, 'rank', $top, (string) $row['rank'], 9, false, self::MUTED, Alignment::HORIZONTAL_CENTER);
            $name = $this->wrapOrEllipsizeForLines($row['name'], self::SURVEY_COLUMNS['name'][1], 9.5, false, 1);
            $this->addSurveyCell($slide, 'name', $top, $name, 9.5, $isGap, $isGap ? self::GAP_TEXT : self::BODY_TEXT);

            $barWidth = $maxPercentage > 0 ? max(0.02, $barMaxWidth * ($row['percentage'] / $maxPercentage)) : 0.02;
            $this->addFilledRect($slide, $barLeft, $top + 0.06, $barWidth, self::SURVEY_ROW_HEIGHT_IN - 0.12, $isGap ? self::GAP_TEXT : self::COPPER);
            // 依頼CM-5/CO-3: 調査の割合は小数1桁(17.0%)。「足りないもの」の文中も同じ整形。
            $percentLabel = $this->formatSurveyPercentage($row['percentage']).'%';
            $pctBox = $slide->createRichTextShape();
            $this->position($pctBox, $barLeft + $barWidth + 0.06, $top, 0.8, self::SURVEY_ROW_HEIGHT_IN);
            $pctBox->setInsetLeft(0)->setInsetRight(0);
            $pctBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $this->font($pctBox->getActiveParagraph()->createTextRun($percentLabel), 9, true, $isGap ? self::GAP_TEXT : self::NAVY);

            $state = $row['self_state'];
            $stateText = trim(($symbols[$state] ?? '').' '.($labels[$state] ?? ''));
            $stateColor = match ($state) {
                'confirmed' => self::NAVY,
                'partial' => self::MUTED,
                'unconfirmed' => self::GAP_TEXT,
                default => self::DIM,
            };
            $this->addSurveyCell($slide, 'self', $top, $stateText, 9, $state !== 'not_applicable', $stateColor);

            if ($row['competitor_count'] === null) {
                $competitorText = (string) config('admin_comparison_pptx.survey_comparison_competitor_none');
                $competitorColor = self::DIM;
            } else {
                $competitorText = sprintf((string) config('admin_comparison_pptx.survey_comparison_competitor_template'), $row['competitor_count'], $comparison['competitor_total']);
                $competitorColor = self::BODY_TEXT;
            }
            $this->addSurveyCell($slide, 'competitor', $top, $competitorText, 9, false, $competitorColor);
        }
    }

    /** 調査の割合の整形(小数1桁、単位なし)。表と「足りないもの」の文の両方がこれを使う。 */
    private function formatSurveyPercentage(float $percentage): string
    {
        return number_format($percentage, 1);
    }

    private function addSurveyCell(Slide $slide, string $column, float $rowTop, string $text, float $sizePt, bool $bold, string $color, string $horizontal = Alignment::HORIZONTAL_LEFT): void
    {
        [$left, $width] = self::SURVEY_COLUMNS[$column];
        $box = $slide->createRichTextShape();
        $this->position($box, $left, $rowTop, $width, self::SURVEY_ROW_HEIGHT_IN);
        $box->setInsetLeft(0)->setInsetRight(0);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $box->getActiveParagraph()->getAlignment()->setHorizontal($horizontal);
        $this->font($box->getActiveParagraph()->createTextRun($text), $sizePt, $bold, $color);
    }

    private function addFilledRect(Slide $slide, float $left, float $top, float $width, float $height, string $rgb): void
    {
        $rect = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($rect, $left, $top, $width, $height);
        $rect->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$rgb));
        $rect->getBorder()->setLineStyle(Border::LINE_NONE);
    }

    /**
     * 表の下の注記: 出典(OTOGI調べ)・巡回の範囲の断り・自社と競合の数え方の
     * 違い・「判定の対象外」の意味・材料不足の競合を除いたこと(該当時のみ)。
     * 1つの本文ボックスに段落で並べる(行数が変わっても重ならない)。
     *
     * @param  array{rows: list<array<string, mixed>>, competitor_total: int, excluded_competitor_count: int}  $comparison
     */
    private function addSurveyNotes(Slide $slide, array $comparison, string $sourceNote): void
    {
        $notes = [
            $sourceNote,
            sprintf((string) config('admin_comparison_pptx.survey_comparison_note_scope'), (int) config('brand_wheel.crawl_max_pages')),
            (string) config('admin_comparison_pptx.survey_comparison_note_counting'),
            (string) config('admin_comparison_pptx.survey_comparison_note_not_applicable'),
        ];
        if (($comparison['excluded_competitor_count'] ?? 0) > 0) {
            $notes[] = sprintf((string) config('admin_comparison_pptx.survey_comparison_note_excluded'), $comparison['excluded_competitor_count']);
        }

        $box = $slide->createRichTextShape();
        $top = self::SURVEY_TABLE_TOP_IN + self::SURVEY_HEADER_HEIGHT_IN + count($comparison['rows']) * self::SURVEY_ROW_HEIGHT_IN + 0.08;
        $this->position($box, self::LEFT_IN, $top, self::CONTENT_WIDTH_IN, 6.93 - $top);
        $box->setInsetLeft(0)->setInsetRight(0);
        $box->setWrap(RichText::WRAP_SQUARE);

        foreach ($notes as $i => $note) {
            $para = $i === 0 ? $box->getActiveParagraph() : $box->createParagraph();
            $this->font($para->createTextRun($note), 8, false, self::MUTED);
        }

        $logo = $slide->createRichTextShape();
        $this->position($logo, self::LEFT_IN, 6.98, 3.0, 0.3);
        $this->font($logo->getActiveParagraph()->createTextRun('LEGGENDA'), 9, true, self::MUTED);
    }

    /**
     * 依頼CL-3(2026-10-05): 「自社サイトの階層図」を、左から右へ
     * TOP → 第1階層(メニューの項目) → 第2階層(その先のページ)と線でつなぐ
     * 木の形にした。AdminComparisonSiteHierarchyBuilder::buildTree()が渡す
     * $treeをそのまま描画するだけで、集計はこのクラスでは行わない。
     * 「ありません」と断定する文言は使わない(依頼者指定、必須) ――
     * 巡回できたページの範囲で読み取れたことだけを書く。
     *
     * 点線の枝=既存の「追加を検討したい導線」(足りないものに対応するサイトの
     * 導線名)。TOPから点線で出す。axis_unread_caveatと巡回範囲の注記は、
     * 枝・点線の枝の量に関わらず固定位置に必ず描く(依頼CF追補)。
     *
     * @param  array{recommended_site_flow_names: list<string>}  $data
     * @param  array{
     *     mode: string,
     *     origin_url: string,
     *     origin_widened?: bool,
     *     top: array{url: string, title: ?string, headings: list<string>, menu_item_count: int},
     *     branches: list<array{name: string, url: ?string, page_count: int, pages: list<string>, other_page_count: int}>,
     *     other_branch_count: int,
     *     total_fetched_pages: int,
     *     pages_within_origin: int,
     *     outside_origin_count: int,
     * } $tree
     */
    public function generateSiteHierarchySlide(array $data, array $tree): string
    {
        return $this->renderSingleSlide(function (Slide $slide) use ($data, $tree): void {
            $this->addKicker($slide);
            $this->addTitle($slide, '自社サイトの階層図');
            $this->addTreeNotes($slide, $tree, $data['recommended_site_flow_names'] !== []);

            if (($tree['corporate'] ?? null) !== null) {
                // 依頼CR-3: コーポレートTOPが決まったときは4列(コーポレートTOP → メニュー → 採用サイトの区分 → 各ページ)。
                $tree['recommended_names'] = $data['recommended_site_flow_names'];
                $this->addCorporateTree($slide, $tree);
            } else {
                // コーポレートTOPが決まらなかったときは、いまの3列(TOP = 採用サイトの起点)。見出しも3列ぶん。
                $this->addTreeColumnHeadings(
                    $slide,
                    [[self::TREE_TOP_LEFT_IN, self::TREE_TOP_WIDTH_IN], [self::TREE_BRANCH_LEFT_IN, self::TREE_BRANCH_WIDTH_IN], [self::TREE_PAGE_LEFT_IN, self::TREE_PAGE_WIDTH_IN]],
                    (array) config('admin_comparison_pptx.site_hierarchy_columns_recruit'),
                    [0, 1, 2],
                );
                $topBottom = $this->addTreeTop($slide, $tree['top'], $tree['mode'] === 'menu');
                $this->addTreeBranches($slide, $tree);
                $this->addTreeRecommendations($slide, $data['recommended_site_flow_names'], $topBottom);
            }

            // 依頼CF-5②/CF追補: 固定位置に必ず描く(可変コンテンツの量に
            // 一切左右されない)。
            $this->addAxisUnreadCaveat($slide);
            // 依頼CB-3必須: footerには、通常の出典行(addFooter())ではなく
            // 巡回範囲についての注記を出す。
            $note = sprintf((string) config('admin_comparison_pptx.site_hierarchy_crawl_scope_note'), (int) config('brand_wheel.crawl_max_pages'));
            $this->addNoteFooter($slide, $note);
        });
    }

    /**
     * 上部の注記: 「巡回したN件のうち、この起点URL配下にあったのはM件でした。」
     * (依頼CC-3①)＋起点URL配下でないページが1件以上あるときだけ「残りは
     * 同じドメインの別のセクションです。」(依頼CL-3)。次の行に、木の作り方
     * (メニューにもとづく/URLの階層にもとづく)と点線の説明。
     *
     * @param  array{mode: string, total_fetched_pages: int, pages_within_origin: int, outside_origin_count: int}  $tree
     */
    private function addTreeNotes(Slide $slide, array $tree, bool $hasRecommendations): void
    {
        $scope = sprintf((string) config('admin_comparison_pptx.site_hierarchy_scope_note'), $tree['total_fetched_pages'], $tree['pages_within_origin']);
        if ($tree['outside_origin_count'] > 0) {
            $scope .= (string) config('admin_comparison_pptx.site_hierarchy_scope_outside_suffix');
        }

        $mode = (string) config($tree['mode'] === 'menu'
            ? 'admin_comparison_pptx.site_hierarchy_tree_mode_note_menu'
            : 'admin_comparison_pptx.site_hierarchy_tree_mode_note_url');
        if ($hasRecommendations) {
            $mode .= (string) config('admin_comparison_pptx.site_hierarchy_tree_recommended_note');
        }

        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, self::TREE_NOTES_TOP_IN, self::CONTENT_WIDTH_IN, self::TREE_NOTES_HEIGHT_IN);
        $box->setInsetLeft(0)->setInsetRight(0);
        $box->setWrap(RichText::WRAP_SQUARE);
        $this->font($box->getActiveParagraph()->createTextRun($scope), 9, false, self::MUTED);
        $this->font($box->createParagraph()->createTextRun($mode), 8, false, self::MUTED);
        // 依頼CM-2: 起点をサイトの一番上まで広げて描いたときは、そのことを図の中で示す。
        if ($tree['origin_widened'] ?? false) {
            $widened = sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_widened_note'), $tree['origin_url']);
            $this->font($box->createParagraph()->createTextRun($widened), 8, true, self::COPPER);
        }
        // 依頼CR-3: コーポレートTOPから採用サイトへのリンクを確認できず、採用サイトを起点に描いているとき。
        if ($tree['corporate_missing'] ?? false) {
            $this->font($box->createParagraph()->createTextRun((string) config('admin_comparison_pptx.site_hierarchy_corporate_missing_note')), 8, true, self::COPPER);
        }
    }

    /**
     * 左の列: TOP(起点)の箱。URL・ページ名・主な見出し・メニューの項目数。
     * 取れていないもの(ページ名・見出し)は出さない(捏造しない)。
     *
     * @param  array{url: string, title: ?string, headings: list<string>, menu_item_count: int}  $top
     * @return float  箱の下端y(in)
     */
    private function addTreeTop(Slide $slide, array $top, bool $showMenuCount): float
    {
        $left = self::TREE_TOP_LEFT_IN;
        $width = self::TREE_TOP_WIDTH_IN;
        $boxTop = self::TREE_TOP_IN;
        $height = $this->treeTopHeight($top);

        $rect = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($rect, $left, $boxTop, $width, $height);
        $rect->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::BAND));
        $rect->getBorder()->setLineStyle(Border::LINE_NONE);
        $this->drawRectOutline($slide, $left, $boxTop, $width, $height, self::NAVY, 1.25);

        $box = $slide->createRichTextShape();
        $this->position($box, $left, $boxTop, $width, $height);
        $box->setWrap(RichText::WRAP_SQUARE);

        $innerWidth = $width - 0.2;
        $labelPara = $box->getActiveParagraph();
        $this->font($labelPara->createTextRun((string) config('admin_comparison_pptx.site_hierarchy_tree_top_label')), 11, true, self::NAVY);
        // メニューの項目数は、メニューから木を作ったときだけ添える(代替の
        // URL階層で描くときは、読み取れた件数が0〜1でも添えない)。
        if ($showMenuCount) {
            $this->font($labelPara->createTextRun('　'.sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_menu_count_template'), $top['menu_item_count'])), 8, false, self::COPPER);
        }

        $this->font($box->createParagraph()->createTextRun($this->truncateToWidth($top['url'], 84)), 8, false, self::MUTED);

        if ($top['title'] !== null) {
            $title = $this->wrapOrEllipsizeForLines($top['title'], $innerWidth, 9.0, true, 2);
            $this->font($box->createParagraph()->createTextRun($title), 9, true, self::BODY_TEXT);
        }

        foreach ($top['headings'] as $heading) {
            $line = $this->wrapOrEllipsizeForLines('・'.$heading, $innerWidth, 8.0, false, 1);
            $this->font($box->createParagraph()->createTextRun($line), 8, false, self::MUTED);
        }

        // 依頼CP-3: 入力したURLが別のページへ転送されていたときだけ1行添える。
        // 転送先のURL・ページ名は出さない。
        if ($top['input_redirected'] ?? false) {
            $this->font($box->createParagraph()->createTextRun((string) config('admin_comparison_pptx.site_hierarchy_tree_top_redirected_note')), 8, false, self::COPPER);
        }

        return $boxTop + $height;
    }

    /**
     * 依頼CP-3: TOPの箱の高さ。ページ名・見出しが無く中身がURLだけのときは、中身に合わせて縮める
     * (転送の一行があれば、その一行ぶんだけ足す)。ページ名か見出しがあるときは従来の高さ。
     * 幹の位置(addTreeBranches)と点線の欄の位置(addTreeRecommendations)も、この高さから決まる。
     *
     * @param  array{title: ?string, headings: list<string>, input_redirected?: bool}  $top
     */
    private function treeTopHeight(array $top): float
    {
        if ($top['title'] !== null || $top['headings'] !== []) {
            return self::TREE_TOP_HEIGHT_IN;
        }

        return self::TREE_TOP_COMPACT_HEIGHT_IN + (($top['input_redirected'] ?? false) ? self::TREE_TOP_NOTE_LINE_IN : 0.0);
    }

    /**
     * 中央の列(第1階層)と右の列(第2階層)。行の高さは第2階層の行数で決まる。
     * 0件のときは、巡回した範囲では配下にページが見つからなかったことを
     * 事実として書く(「ありません」と断定しない)。
     *
     * @param  array{origin_url: string, top: array{url: string, title: ?string, headings: list<string>, input_redirected?: bool}, branches: list<array{name: string, url: ?string, page_count: int, pages: list<string>, other_page_count: int}>, other_branch_count: int, unplaced_page_count?: int}  $tree
     */
    private function addTreeBranches(Slide $slide, array $tree): void
    {
        $branches = $tree['branches'];

        if ($branches === []) {
            $box = $slide->createRichTextShape();
            $this->position($box, self::TREE_BRANCH_LEFT_IN, self::TREE_TOP_IN, self::TREE_PAGE_LEFT_IN + self::TREE_PAGE_WIDTH_IN - self::TREE_BRANCH_LEFT_IN, 0.5);
            $box->setWrap(RichText::WRAP_SQUARE);
            $text = sprintf((string) config('admin_comparison_pptx.site_hierarchy_empty_text'), $tree['origin_url']);
            $this->font($box->getActiveParagraph()->createTextRun($text), 11, false, self::MUTED);

            return;
        }

        $pitch = self::TREE_LINE_PITCH_IN;
        $y = self::TREE_TOP_IN;
        $centers = [];

        foreach ($branches as $branch) {
            $lineCount = count($branch['pages']) + ($branch['other_page_count'] > 0 ? 1 : 0);
            $rowHeight = max(self::TREE_BRANCH_BOX_HEIGHT_IN + 0.04, $lineCount * $pitch + 0.02);
            $center = $y + $rowHeight / 2;
            $centers[] = $center;

            $this->addTreeBranchBox($slide, $branch, $center);
            $this->addTreeSecondLevel($slide, $branch, $center, $lineCount);

            $y += $rowHeight + self::TREE_ROW_GAP_IN;
        }

        // 依頼CN-A2: 畳んだ枝の「ほかN」と、どのメニューにも属さなかったページの件数を、
        // 同じ1行に出す(行を増やさない ―― 免責文の固定位置より上に収めるため)。
        $unplaced = (int) ($tree['unplaced_page_count'] ?? 0);
        if ($tree['other_branch_count'] > 0 || $unplaced > 0) {
            $parts = [];
            if ($tree['other_branch_count'] > 0) {
                $parts[] = sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_other_branches_template'), $tree['other_branch_count']);
            }
            if ($unplaced > 0) {
                $parts[] = sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_unplaced_template'), $unplaced);
            }
            $box = $slide->createRichTextShape();
            $this->position($box, self::TREE_BRANCH_LEFT_IN, $y, self::TREE_PAGE_LEFT_IN + self::TREE_PAGE_WIDTH_IN - self::TREE_BRANCH_LEFT_IN, 0.22);
            $box->setInsetLeft(0);
            $this->font($box->getActiveParagraph()->createTextRun(implode('　／　', $parts)), 9, false, self::MUTED);
        }

        // TOP → 第1階層の幹と枝(直線だけで描く)。
        $topMid = self::TREE_TOP_IN + $this->treeTopHeight($tree['top']) / 2;
        $trunkX = self::TREE_TRUNK_X_IN;
        $this->drawLine($slide, [self::TREE_TOP_LEFT_IN + self::TREE_TOP_WIDTH_IN, $topMid], [$trunkX, $topMid], self::NAVY, 1.25);
        $this->drawLine($slide, [$trunkX, min($topMid, $centers[0])], [$trunkX, max($topMid, $centers[count($centers) - 1])], self::NAVY, 1.25);
        foreach ($centers as $center) {
            $this->drawLine($slide, [$trunkX, $center], [self::TREE_BRANCH_LEFT_IN, $center], self::NAVY, 1.25);
        }
    }

    /**
     * @param  array{name: string, page_count: int, path?: ?string}  $branch
     */
    private function addTreeBranchBox(Slide $slide, array $branch, float $center): void
    {
        $top = $center - self::TREE_BRANCH_BOX_HEIGHT_IN / 2;

        $rect = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($rect, self::TREE_BRANCH_LEFT_IN, $top, self::TREE_BRANCH_WIDTH_IN, self::TREE_BRANCH_BOX_HEIGHT_IN);
        $rect->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::WHITE));
        $rect->getBorder()->setLineStyle(Border::LINE_NONE);
        $this->drawRectOutline($slide, self::TREE_BRANCH_LEFT_IN, $top, self::TREE_BRANCH_WIDTH_IN, self::TREE_BRANCH_BOX_HEIGHT_IN, self::NAVY, 1.0);

        $box = $slide->createRichTextShape();
        $this->position($box, self::TREE_BRANCH_LEFT_IN, $top, self::TREE_BRANCH_WIDTH_IN, self::TREE_BRANCH_BOX_HEIGHT_IN);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);

        $name = $this->wrapOrEllipsizeForLines($branch['name'], self::TREE_BRANCH_WIDTH_IN - 0.1, 10.0, true, 1);
        $this->font($box->getActiveParagraph()->createTextRun($name), 10, true, self::NAVY);
        $this->font($box->createParagraph()->createTextRun($this->branchSubLine($branch)), 8, false, self::MUTED);
    }

    /**
     * 第2階層: 枝の右に、ページ名(無ければURLをデコードしたもの)を数件並べ、
     * 残りは「ほかNページ」。枝から幹→各行へ直線でつなぐ。
     *
     * @param  array{pages: list<string>, other_page_count: int}  $branch
     */
    private function addTreeSecondLevel(Slide $slide, array $branch, float $center, int $lineCount): void
    {
        if ($lineCount === 0) {
            return;
        }

        $pitch = self::TREE_LINE_PITCH_IN;
        $blockTop = $center - $lineCount * $pitch / 2;
        $lines = $branch['pages'];
        if ($branch['other_page_count'] > 0) {
            $lines[] = sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_other_pages_template'), $branch['other_page_count']);
        }

        $bracketX = self::TREE_BRACKET_X_IN;
        $centers = [];
        foreach ($lines as $k => $line) {
            $isOther = $branch['other_page_count'] > 0 && $k === count($lines) - 1;
            $lineCenter = $blockTop + $k * $pitch + $pitch / 2;
            $centers[] = $lineCenter;

            $box = $slide->createRichTextShape();
            $this->position($box, self::TREE_PAGE_LEFT_IN, $lineCenter - $pitch / 2, self::TREE_PAGE_WIDTH_IN, $pitch);
            $box->setInsetLeft(0)->setInsetRight(0)->setInsetTop(0)->setInsetBottom(0);
            $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $text = $isOther ? $line : $this->wrapOrEllipsizeForLines($line, self::TREE_PAGE_WIDTH_IN, 8.0, false, 1);
            $this->font($box->getActiveParagraph()->createTextRun($text), 8, false, $isOther ? self::DIM : self::BODY_TEXT);

            $this->drawLine($slide, [$bracketX, $lineCenter], [self::TREE_PAGE_LEFT_IN - 0.05, $lineCenter], self::DIM, 0.75);
        }

        $this->drawLine($slide, [self::TREE_BRANCH_LEFT_IN + self::TREE_BRANCH_WIDTH_IN, $center], [$bracketX, $center], self::DIM, 0.75);
        $this->drawLine($slide, [$bracketX, min($center, $centers[0])], [$bracketX, max($center, $centers[count($centers) - 1])], self::DIM, 0.75);
    }

    /**
     * 点線の枝(既存の「追加を検討したい導線」): TOPの箱の下から点線で出し、
     * 導線名を点線の枠で並べる。「ありません」と断定しない ―― 巡回した範囲で
     * 確認できなかった導線の例であり、注記(addTreeNotes)で明示する。
     * 0件のときは何も描かない。
     *
     * @param  list<string>  $names
     */
    private function addTreeRecommendations(Slide $slide, array $names, float $topBottom): void
    {
        if ($names === []) {
            return;
        }

        $limit = (int) config('admin_comparison_pptx.site_hierarchy_tree_recommended_limit');
        $shown = array_slice($names, 0, $limit);
        $otherCount = count($names) - count($shown);

        $headingTop = $topBottom + 0.12;
        $heading = $slide->createRichTextShape();
        $this->position($heading, self::TREE_TOP_LEFT_IN + 0.3, $headingTop, self::TREE_TOP_WIDTH_IN - 0.3, 0.24);
        $heading->setInsetLeft(0);
        $this->font($heading->getActiveParagraph()->createTextRun((string) config('admin_comparison_pptx.site_hierarchy_recommended_heading')), 10, true, self::GAP_TEXT);

        $itemsTop = $headingTop + 0.3;
        $itemLeft = self::TREE_TOP_LEFT_IN + 0.3;
        $itemWidth = self::TREE_TOP_WIDTH_IN - 0.3;
        $spineX = self::TREE_TOP_LEFT_IN + 0.12;

        $lastCenter = $itemsTop;
        foreach ($shown as $k => $name) {
            $top = $itemsTop + $k * self::TREE_RECOMMENDED_PITCH_IN;
            $center = $top + self::TREE_RECOMMENDED_ITEM_HEIGHT_IN / 2;
            $lastCenter = $center;

            $rect = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
            $this->position($rect, $itemLeft, $top, $itemWidth, self::TREE_RECOMMENDED_ITEM_HEIGHT_IN);
            $rect->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::WHITE));
            $rect->getBorder()->setLineStyle(Border::LINE_NONE);
            $this->drawRectOutline($slide, $itemLeft, $top, $itemWidth, self::TREE_RECOMMENDED_ITEM_HEIGHT_IN, self::GAP_TEXT, 1.0, Border::DASH_DASH);

            $box = $slide->createRichTextShape();
            $this->position($box, $itemLeft, $top, $itemWidth, self::TREE_RECOMMENDED_ITEM_HEIGHT_IN);
            $box->setInsetTop(0)->setInsetBottom(0);
            $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $text = $this->wrapOrEllipsizeForLines($name, $itemWidth, 9.0, false, 1);
            $this->font($box->getActiveParagraph()->createTextRun($text), 9, false, self::GAP_TEXT);

            $this->drawLine($slide, [$spineX, $center], [$itemLeft, $center], self::GAP_TEXT, 1.0, Border::DASH_DASH);
        }

        if ($otherCount > 0) {
            $top = $itemsTop + count($shown) * self::TREE_RECOMMENDED_PITCH_IN;
            $box = $slide->createRichTextShape();
            $this->position($box, $itemLeft, $top, $itemWidth, 0.2);
            $box->setInsetLeft(0)->setInsetTop(0)->setInsetBottom(0);
            $this->font($box->getActiveParagraph()->createTextRun(sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_recommended_other_template'), $otherCount)), 8, false, self::MUTED);
        }

        $this->drawLine($slide, [$spineX, $topBottom], [$spineX, $lastCenter], self::GAP_TEXT, 1.0, Border::DASH_DASH);
    }

    /**
     * 依頼CR-3: 列の上の見出し(階層名と説明)。各列の上端に階層の色の帯を引き、その下に見出しと説明を
     * そろえて置く。見出し・説明・色はconfig。
     *
     * @param  list<array{0: float, 1: float}>  $columns  列の[左(in), 幅(in)]
     * @param  list<array{heading: string, description: string}>  $labels
     * @param  list<int>  $levels  各列の階層(色の番号)
     */
    private function addTreeColumnHeadings(Slide $slide, array $columns, array $labels, array $levels): void
    {
        $fills = (array) config('admin_comparison_pptx.site_hierarchy_level_fill');

        foreach ($columns as $i => [$left, $width]) {
            $label = $labels[$i] ?? ['heading' => '', 'description' => ''];
            $this->addFilledRect($slide, $left, self::TREE_HEADINGS_TOP_IN, $width, 0.05, (string) ($fills[$levels[$i]] ?? self::NAVY));

            $box = $slide->createRichTextShape();
            $this->position($box, $left, self::TREE_HEADINGS_TOP_IN + 0.06, $width, 0.4);
            $box->setInsetLeft(0)->setInsetRight(0)->setInsetTop(2)->setInsetBottom(0);
            $box->setWrap(RichText::WRAP_SQUARE);
            $this->font($box->getActiveParagraph()->createTextRun($label['heading']), 10, true, self::NAVY);
            $this->font($box->createParagraph()->createTextRun($label['description']), 8, false, self::MUTED);
        }
    }

    /**
     * 依頼CR-3: コーポレートTOPが決まったときの4列の木。
     * 列1 コーポレートTOP(ホスト名)/ 列2 コーポレートのメニュー(採用の箱を強調、他は名前だけ薄く)/
     * 列3 採用サイトの区分(箱の下にURLのパス)/ 列4 各ページ。階層が深いほど色を薄くする。
     *
     * @param  array<string, mixed>  $tree
     */
    private function addCorporateTree(Slide $slide, array $tree): void
    {
        $corporate = $tree['corporate'];
        [$c1, $c2, $c3, $c4] = self::TREE4_COLUMNS;
        $fills = (array) config('admin_comparison_pptx.site_hierarchy_level_fill');
        $texts = (array) config('admin_comparison_pptx.site_hierarchy_level_text');
        $accent = (string) config('admin_comparison_pptx.site_hierarchy_accent_color');

        $this->addTreeColumnHeadings(
            $slide,
            [$c1, $c2, $c3, $c4],
            (array) config('admin_comparison_pptx.site_hierarchy_columns_corporate'),
            [0, 1, 2, 3],
        );

        // 列3・列4: 採用サイトの区分と各ページ(行の高さは各ページの行数で決まる)。
        $branches = $tree['branches'];
        $pitch = self::TREE_LINE_PITCH_IN;
        $y = self::TREE_TOP_IN;
        $centers = [];
        foreach ($branches as $branch) {
            $lineCount = count($branch['pages']) + ($branch['other_page_count'] > 0 ? 1 : 0);
            $rowHeight = max(self::TREE_BRANCH_BOX_HEIGHT_IN + 0.04, $lineCount * $pitch + 0.02);
            $center = $y + $rowHeight / 2;
            $centers[] = $center;

            $this->addLevelBox($slide, $c3[0], $center - self::TREE_BRANCH_BOX_HEIGHT_IN / 2, $c3[1], self::TREE_BRANCH_BOX_HEIGHT_IN, (string) $fills[2], null);
            $this->addBranchBoxText($slide, $branch, $center, $c3[0], $c3[1], (string) $texts[2]);
            $this->addLevelPages($slide, $branch, $center, $lineCount, $c3, $c4, (string) $fills[3], (string) $texts[3]);

            $y += $rowHeight + self::TREE_ROW_GAP_IN;
        }

        if ($branches === []) {
            $box = $slide->createRichTextShape();
            $this->position($box, $c3[0], self::TREE_TOP_IN, $c4[0] + $c4[1] - $c3[0], 0.5);
            $box->setWrap(RichText::WRAP_SQUARE);
            $this->font($box->getActiveParagraph()->createTextRun(sprintf((string) config('admin_comparison_pptx.site_hierarchy_empty_text'), $tree['origin_url'])), 11, false, self::MUTED);
        } else {
            $unplaced = (int) ($tree['unplaced_page_count'] ?? 0);
            if ($tree['other_branch_count'] > 0 || $unplaced > 0) {
                $parts = [];
                if ($tree['other_branch_count'] > 0) {
                    $parts[] = sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_other_branches_template'), $tree['other_branch_count']);
                }
                if ($unplaced > 0) {
                    $parts[] = sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_unplaced_template'), $unplaced);
                }
                $box = $slide->createRichTextShape();
                $this->position($box, $c3[0], $y, $c4[0] + $c4[1] - $c3[0], 0.22);
                $box->setInsetLeft(0);
                $this->font($box->getActiveParagraph()->createTextRun(implode('　／　', $parts)), 9, false, self::MUTED);
                $y += 0.22;
            }
        }

        // 列2: 採用の箱(強調)。列3の枝の真ん中に高さをそろえる。
        $mid = $centers === [] ? self::TREE_TOP_IN + 0.4 : ($centers[0] + $centers[count($centers) - 1]) / 2;
        $redirected = (bool) ($tree['top']['input_redirected'] ?? false);
        $recruitHeight = 0.62 + ($redirected ? 0.2 : 0.0);
        $recruitTop = max(self::TREE_TOP_IN, $mid - $recruitHeight / 2);
        $recruitCenter = $recruitTop + $recruitHeight / 2;

        $this->addLevelBox($slide, $c2[0], $recruitTop, $c2[1], $recruitHeight, (string) $fills[1], $accent);
        $box = $slide->createRichTextShape();
        $this->position($box, $c2[0], $recruitTop, $c2[1], $recruitHeight);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($box->getActiveParagraph()->createTextRun($this->wrapOrEllipsizeForLines((string) $corporate['recruit_label'], $c2[1] - 0.1, 11.0, true, 1)), 11, true, (string) $texts[1]);
        $this->font($box->createParagraph()->createTextRun($this->truncateToWidth((string) ($tree['recruit_path'] ?? ''), 36)), 8, false, (string) $texts[1]);
        if ($redirected) {
            // 依頼CR-3: 転送の一言は、採用の箱の中に置く。
            $this->font($box->createParagraph()->createTextRun((string) config('admin_comparison_pptx.site_hierarchy_tree_top_redirected_note')), 8, false, (string) $texts[1]);
        }

        // 列2: 採用以外のメニュー(名前だけ、薄く)。採用の箱の下に並べる。
        $otherColor = (string) config('admin_comparison_pptx.site_hierarchy_other_menu_text');
        $otherBorder = (string) config('admin_comparison_pptx.site_hierarchy_other_menu_border');
        $otherTop = $recruitTop + $recruitHeight + 0.12;
        $otherCenters = [];
        // 免責文の固定位置(罫線)より上に収まる件数まで。収まらない分は「ほかN」にまとめる。
        $fit = max(0, (int) floor((self::HIERARCHY_AXIS_CAVEAT_RULE_TOP_IN - 0.08 - $otherTop - 0.2) / 0.3));
        $hidden = max(0, count($corporate['other_menu']) - $fit);
        $corporate['other_menu'] = array_slice($corporate['other_menu'], 0, $fit);
        $corporate['other_menu_more'] += $hidden;
        foreach ($corporate['other_menu'] as $k => $name) {
            $top = $otherTop + $k * 0.3;
            $this->addLevelBox($slide, $c2[0], $top, $c2[1], 0.24, self::WHITE, null);
            $this->drawRectOutline($slide, $c2[0], $top, $c2[1], 0.24, $otherBorder, 0.75);
            $text = $slide->createRichTextShape();
            $this->position($text, $c2[0], $top, $c2[1], 0.24);
            $text->setInsetTop(0)->setInsetBottom(0);
            $text->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $this->font($text->getActiveParagraph()->createTextRun($this->wrapOrEllipsizeForLines($name, $c2[1] - 0.1, 8.0, false, 1)), 8, false, $otherColor);
            $otherCenters[] = $top + 0.12;
        }
        if ($corporate['other_menu_more'] > 0) {
            $top = $otherTop + count($corporate['other_menu']) * 0.3;
            $text = $slide->createRichTextShape();
            $this->position($text, $c2[0], $top, $c2[1], 0.2);
            $text->setInsetLeft(0)->setInsetTop(0)->setInsetBottom(0);
            $this->font($text->getActiveParagraph()->createTextRun(sprintf((string) config('admin_comparison_pptx.corporate_top_other_menu_other_template'), $corporate['other_menu_more'])), 8, false, $otherColor);
        }

        // 列1: コーポレートTOP(ホスト名)。採用の箱と同じ高さ。
        $topHeight = 0.62;
        $topBoxTop = $recruitCenter - $topHeight / 2;
        $this->addLevelBox($slide, $c1[0], $topBoxTop, $c1[1], $topHeight, (string) $fills[0], null);
        $hostBox = $slide->createRichTextShape();
        $this->position($hostBox, $c1[0], $topBoxTop, $c1[1], $topHeight);
        $hostBox->setWrap(RichText::WRAP_SQUARE);
        $hostBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($hostBox->getActiveParagraph()->createTextRun($this->wrapOrEllipsizeForLines((string) $corporate['host'], $c1[1] - 0.1, 9.0, true, 2)), 9, true, (string) $texts[0]);

        // 線: コーポレートTOP → 第1階層(採用の箱は濃く、他は薄く)、採用の箱 → 採用サイトの区分。
        $trunk1 = ($c1[0] + $c1[1] + $c2[0]) / 2;
        $this->drawLine($slide, [$c1[0] + $c1[1], $recruitCenter], [$c2[0], $recruitCenter], self::NAVY, 1.25);
        if ($otherCenters !== []) {
            $this->drawLine($slide, [$trunk1, $recruitCenter], [$trunk1, $otherCenters[count($otherCenters) - 1]], $otherBorder, 0.75);
            foreach ($otherCenters as $center) {
                $this->drawLine($slide, [$trunk1, $center], [$c2[0], $center], $otherBorder, 0.75);
            }
        }
        if ($centers !== []) {
            $trunk2 = ($c2[0] + $c2[1] + $c3[0]) / 2;
            $this->drawLine($slide, [$c2[0] + $c2[1], $recruitCenter], [$trunk2, $recruitCenter], self::NAVY, 1.25);
            $this->drawLine($slide, [$trunk2, min($recruitCenter, $centers[0])], [$trunk2, max($recruitCenter, $centers[count($centers) - 1])], self::NAVY, 1.25);
            foreach ($centers as $center) {
                $this->drawLine($slide, [$trunk2, $center], [$c3[0], $center], self::NAVY, 1.25);
            }
        }

        // 点線の「追加を検討したい導線」: 第2階層の列の下に置く(収まる件数まで。残りは「ほかN」)。
        $this->addCorporateRecommendations($slide, $tree['recommended_names'] ?? [], $y, $c3);
    }

    /**
     * 階層の色で塗った箱。$outlineを渡すと、その色の太い枠(強調)。
     */
    private function addLevelBox(Slide $slide, float $left, float $top, float $width, float $height, string $fill, ?string $outline): void
    {
        $this->addFilledRect($slide, $left, $top, $width, $height, $fill);
        if ($outline !== null) {
            $this->drawRectOutline($slide, $left, $top, $width, $height, $outline, 2.25);
        }
    }

    /**
     * 第2階層(列3)の箱の中身: 名前と、その下にURLのパスとページ数。
     *
     * @param  array{name: string, page_count: int, path?: ?string}  $branch
     */
    private function addBranchBoxText(Slide $slide, array $branch, float $center, float $left, float $width, string $textColor): void
    {
        $top = $center - self::TREE_BRANCH_BOX_HEIGHT_IN / 2;
        $box = $slide->createRichTextShape();
        $this->position($box, $left, $top, $width, self::TREE_BRANCH_BOX_HEIGHT_IN);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($box->getActiveParagraph()->createTextRun($this->wrapOrEllipsizeForLines($branch['name'], $width - 0.1, 10.0, true, 1)), 10, true, $textColor);
        $this->font($box->createParagraph()->createTextRun($this->branchSubLine($branch)), 8, false, $textColor);
    }

    /**
     * 箱の2行目: URLのパス(あれば)と「（Nページ）」。
     *
     * @param  array{page_count: int, path?: ?string}  $branch
     */
    private function branchSubLine(array $branch): string
    {
        $count = sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_page_count_template'), $branch['page_count']);
        $path = trim((string) ($branch['path'] ?? ''));

        return $path !== '' ? $path.'　'.$count : $count;
    }

    /**
     * 第3階層(列4): 枝の右にページ名を数件並べ、残りは「ほかNページ」。各行は階層の色の細い帯の上。
     *
     * @param  array{pages: list<string>, other_page_count: int}  $branch
     * @param  array{0: float, 1: float}  $branchColumn
     * @param  array{0: float, 1: float}  $pageColumn
     */
    private function addLevelPages(Slide $slide, array $branch, float $center, int $lineCount, array $branchColumn, array $pageColumn, string $fill, string $textColor): void
    {
        if ($lineCount === 0) {
            return;
        }

        $pitch = self::TREE_LINE_PITCH_IN;
        $blockTop = $center - $lineCount * $pitch / 2;
        $lines = $branch['pages'];
        if ($branch['other_page_count'] > 0) {
            $lines[] = sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_other_pages_template'), $branch['other_page_count']);
        }

        $bracketX = $pageColumn[0] - 0.15;
        $centers = [];
        foreach ($lines as $k => $line) {
            $isOther = $branch['other_page_count'] > 0 && $k === count($lines) - 1;
            $lineCenter = $blockTop + $k * $pitch + $pitch / 2;
            $centers[] = $lineCenter;

            if (! $isOther) {
                $this->addFilledRect($slide, $pageColumn[0], $lineCenter - $pitch / 2 + 0.008, $pageColumn[1], $pitch - 0.016, $fill);
            }
            $box = $slide->createRichTextShape();
            $this->position($box, $pageColumn[0], $lineCenter - $pitch / 2, $pageColumn[1], $pitch);
            $box->setInsetLeft(5)->setInsetRight(3)->setInsetTop(0)->setInsetBottom(0);
            $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $text = $isOther ? $line : $this->wrapOrEllipsizeForLines($line, $pageColumn[1], 8.0, false, 1);
            $this->font($box->getActiveParagraph()->createTextRun($text), 8, false, $isOther ? self::DIM : $textColor);

            $this->drawLine($slide, [$bracketX, $lineCenter], [$pageColumn[0], $lineCenter], self::DIM, 0.75);
        }

        $this->drawLine($slide, [$branchColumn[0] + $branchColumn[1], $center], [$bracketX, $center], self::DIM, 0.75);
        $this->drawLine($slide, [$bracketX, min($center, $centers[0])], [$bracketX, max($center, $centers[count($centers) - 1])], self::DIM, 0.75);
    }

    /**
     * 点線の枝(追加を検討したい導線): 第2階層の列の下。収まる件数まで並べ、残りは「ほかN」にまとめる。
     * 色・点線は従来のまま。
     *
     * @param  list<string>  $names
     * @param  array{0: float, 1: float}  $column
     */
    private function addCorporateRecommendations(Slide $slide, array $names, float $top, array $column): void
    {
        if ($names === []) {
            return;
        }

        $limit = (int) config('admin_comparison_pptx.site_hierarchy_tree_recommended_limit');
        $headingTop = $top + 0.1;
        $itemsTop = $headingTop + 0.3;
        $bottomLimit = self::HIERARCHY_AXIS_CAVEAT_RULE_TOP_IN - 0.05;
        $fit = (int) floor(($bottomLimit - $itemsTop - 0.2) / self::TREE_RECOMMENDED_PITCH_IN);
        $shownCount = max(0, min($limit, $fit, count($names)));
        if ($shownCount === 0) {
            return;
        }

        $shown = array_slice($names, 0, $shownCount);
        $otherCount = count($names) - $shownCount;

        $heading = $slide->createRichTextShape();
        $this->position($heading, $column[0] + 0.3, $headingTop, $column[1] - 0.3 + 1.0, 0.24);
        $heading->setInsetLeft(0);
        $this->font($heading->getActiveParagraph()->createTextRun((string) config('admin_comparison_pptx.site_hierarchy_recommended_heading')), 10, true, self::GAP_TEXT);

        $itemLeft = $column[0] + 0.3;
        $itemWidth = $column[1] - 0.3;
        $spineX = $column[0] + 0.12;
        $lastCenter = $itemsTop;
        foreach ($shown as $k => $name) {
            $itemTop = $itemsTop + $k * self::TREE_RECOMMENDED_PITCH_IN;
            $center = $itemTop + self::TREE_RECOMMENDED_ITEM_HEIGHT_IN / 2;
            $lastCenter = $center;

            $this->addFilledRect($slide, $itemLeft, $itemTop, $itemWidth, self::TREE_RECOMMENDED_ITEM_HEIGHT_IN, self::WHITE);
            $this->drawRectOutline($slide, $itemLeft, $itemTop, $itemWidth, self::TREE_RECOMMENDED_ITEM_HEIGHT_IN, self::GAP_TEXT, 1.0, Border::DASH_DASH);

            $box = $slide->createRichTextShape();
            $this->position($box, $itemLeft, $itemTop, $itemWidth, self::TREE_RECOMMENDED_ITEM_HEIGHT_IN);
            $box->setInsetTop(0)->setInsetBottom(0);
            $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $this->font($box->getActiveParagraph()->createTextRun($this->wrapOrEllipsizeForLines($name, $itemWidth, 9.0, false, 1)), 9, false, self::GAP_TEXT);

            $this->drawLine($slide, [$spineX, $center], [$itemLeft, $center], self::GAP_TEXT, 1.0, Border::DASH_DASH);
        }

        if ($otherCount > 0) {
            $otherTop = $itemsTop + $shownCount * self::TREE_RECOMMENDED_PITCH_IN;
            $box = $slide->createRichTextShape();
            $this->position($box, $itemLeft, $otherTop, $itemWidth, 0.2);
            $box->setInsetLeft(0)->setInsetTop(0)->setInsetBottom(0);
            $this->font($box->getActiveParagraph()->createTextRun(sprintf((string) config('admin_comparison_pptx.site_hierarchy_tree_recommended_other_template'), $otherCount)), 8, false, self::MUTED);
        }

        $this->drawLine($slide, [$spineX, $headingTop + 0.24], [$spineX, $lastCenter], self::GAP_TEXT, 1.0, Border::DASH_DASH);
    }

    /** 表示幅(全角=2)基準で、収まらない分を省略記号にする(URL用)。 */
    private function truncateToWidth(string $text, int $maxUnits): string
    {
        return mb_strwidth($text, 'UTF-8') <= $maxUnits ? $text : $this->truncateToDisplayWidth($text, max(0, $maxUnits - 2)).'…';
    }

    /**
     * 依頼CF-5②/CF追補(2026-09-30、必須): config('brand_wheel.
     * axis_unread_caveat')(4か所で共有している「絶対に消してはいけない
     * 文言」、文言自体は変更しない)を、可変レイアウト(枝・推奨導線・
     * 参考内訳)の量に関わらず必ず描く。固定位置
     * (HIERARCHY_AXIS_CAVEAT_RULE_TOP_IN)に置くことで、枝が多いサイト
     * (=充実している健全なケース)ほど免責文が消えるという、CF-5②実装
     * 直後に依頼者が指摘した逆向きの不具合を解消した。収まらない場合は
     * 呼び出し元より前(site_hierarchy_branch_limit=4への引き下げ)で
     * 場所を確保済みのため、ここでは条件分岐せず常に描く。
     */
    private function addAxisUnreadCaveat(Slide $slide): void
    {
        $rule = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($rule, self::LEFT_IN, self::HIERARCHY_AXIS_CAVEAT_RULE_TOP_IN, self::CONTENT_WIDTH_IN, 0.01);
        $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::RULE));
        $rule->getBorder()->setLineStyle(Border::LINE_NONE);

        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, self::HIERARCHY_AXIS_CAVEAT_BOX_TOP_IN, self::CONTENT_WIDTH_IN, self::HIERARCHY_AXIS_CAVEAT_BOX_HEIGHT_IN);
        $box->setWrap(RichText::WRAP_SQUARE);
        $run = $box->getActiveParagraph()->createTextRun((string) config('brand_wheel.axis_unread_caveat'));
        $this->font($run, 9, false, self::MUTED);
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
     * 依頼CQ-4(2026-10-09): 「{企業名}：トップメッセージと人事制度」。1社1ページ・文字だけ
     * (写真・ロゴ・画像は載せない ―― 差し込みの仕組みが外部参照を拒否する)。
     *
     * 左: 「TOP MESSAGE」の見出しとquote(メッセージの箱は写真の場所まで縦に広げる)。
     * 右: キーワードを最大4段。各段に制度を最大3つ(太字でname、続けてdetail)。
     * 下: 出典(使ったページのタイトル)と、固定の一文(対応づけは自動で整理したもの)。
     * 見出しは会社名とテーマだけ。評価の言葉は作らない。
     *
     * 文字が箱に収まらないときは、まず文字を小さくし(下限はconfigの比)、下限でも
     * 収まらなければその段の制度を1つ減らす。
     *
     * @param  array{company_name: string, quote: string, keywords: list<array{keyword: string, programs: list<array{name: string, detail: string}>}>, sources: list<string>}  $page
     */
    public function generateTopMessageSlide(array $page): string
    {
        return $this->renderSingleSlide(function (Slide $slide) use ($page): void {
            $this->addKicker($slide);
            $this->addTopMessageTitle($slide, $page['company_name']);

            $subtitle = $slide->createRichTextShape();
            $this->position($subtitle, self::LEFT_IN, self::TM_SUBTITLE_TOP_IN, self::CONTENT_WIDTH_IN, 0.3);
            $subtitle->setWrap(RichText::WRAP_SQUARE);
            $this->font($subtitle->getActiveParagraph()->createTextRun((string) config('admin_comparison_pptx.top_message_subtitle')), 11, false, self::MUTED);

            $this->addTopMessageQuote($slide, $page['quote']);
            $this->addTopMessageRows($slide, $page['keywords']);
            $this->addNoteFooter($slide, $this->topMessageSourceNote($page['sources']));
        });
    }

    private function addTopMessageTitle(Slide $slide, string $companyName): void
    {
        $template = (string) config('admin_comparison_pptx.top_message_title');
        $maxPt = (int) config('admin_comparison_pptx.top_message_title_max_pt');
        $minPt = min($maxPt, (int) config('admin_comparison_pptx.top_message_title_min_pt'));

        $title = sprintf($template, $companyName);
        $size = $minPt;
        for ($pt = $maxPt; $pt >= $minPt; $pt--) {
            if (mb_strwidth($title, 'UTF-8') <= $this->maxUnitsPerLine(self::CONTENT_WIDTH_IN, (float) $pt, true)) {
                $size = $pt;
                break;
            }
        }

        // 下限でも1行に収まらない極端に長い名前だけ、名前を省略記号で切る。
        if (mb_strwidth($title, 'UTF-8') > $this->maxUnitsPerLine(self::CONTENT_WIDTH_IN, (float) $size, true)) {
            $fixed = mb_strwidth(sprintf($template, ''), 'UTF-8');
            $room = $this->maxUnitsPerLine(self::CONTENT_WIDTH_IN, (float) $size, true) - $fixed - 2;
            $title = sprintf($template, $this->truncateToDisplayWidth($companyName, max(2, $room)).'…');
        }

        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, 0.86, self::CONTENT_WIDTH_IN, 0.55);
        $this->font($box->getActiveParagraph()->createTextRun($title), $size, true, self::NAVY);
    }

    private function addTopMessageQuote(Slide $slide, string $quote): void
    {
        $height = self::TM_BODY_BOTTOM_IN - self::TM_BODY_TOP_IN;

        $this->addFilledRect($slide, self::LEFT_IN, self::TM_BODY_TOP_IN, self::TM_LEFT_WIDTH_IN, $height, self::SELF_TINT);
        $this->addFilledRect($slide, self::LEFT_IN, self::TM_BODY_TOP_IN, 0.06, $height, self::COPPER);

        $heading = $slide->createRichTextShape();
        $this->position($heading, self::LEFT_IN + 0.25, self::TM_BODY_TOP_IN + 0.15, self::TM_LEFT_WIDTH_IN - 0.5, 0.3);
        $this->font($heading->getActiveParagraph()->createTextRun((string) config('admin_comparison_pptx.top_message_left_heading')), 11, true, self::COPPER);

        $boxLeft = self::LEFT_IN + 0.25;
        $boxTop = self::TM_BODY_TOP_IN + 0.55;
        $boxWidth = self::TM_LEFT_WIDTH_IN - 0.5;
        $boxHeight = $height - 0.8;

        $maxPt = (int) config('admin_comparison_pptx.top_message_quote_max_pt');
        $floor = max(7, (int) round($maxPt * (float) config('admin_comparison_pptx.top_message_min_font_ratio')));
        $size = $floor;
        for ($pt = $maxPt; $pt >= $floor; $pt--) {
            if ($this->topMessageTextHeight($quote, $boxWidth, $pt, true) <= $boxHeight) {
                $size = $pt;
                break;
            }
        }

        $box = $slide->createRichTextShape();
        $this->position($box, $boxLeft, $boxTop, $boxWidth, $boxHeight);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($box->getActiveParagraph()->createTextRun($quote), $size, true, self::NAVY);
    }

    /**
     * @param  list<array{keyword: string, programs: list<array{name: string, detail: string}>}>  $keywords
     */
    private function addTopMessageRows(Slide $slide, array $keywords): void
    {
        $keywords = array_slice($keywords, 0, 4);
        $count = count($keywords);
        if ($count === 0) {
            return;
        }

        $bodyHeight = self::TM_BODY_BOTTOM_IN - self::TM_BODY_TOP_IN;
        $rowHeight = min(self::TM_ROW_MAX_HEIGHT_IN, ($bodyHeight - ($count - 1) * self::TM_ROW_GAP_IN) / $count);
        $groupHeight = $count * $rowHeight + ($count - 1) * self::TM_ROW_GAP_IN;
        $top = self::TM_BODY_TOP_IN + ($bodyHeight - $groupHeight) / 2;

        $programsLeft = self::TM_RIGHT_LEFT_IN + self::TM_KEYWORD_WIDTH_IN + self::TM_KEYWORD_GAP_IN;
        $programsWidth = self::LEFT_IN + self::CONTENT_WIDTH_IN - $programsLeft;

        foreach ($keywords as $keyword) {
            $this->addTopMessageKeywordCell($slide, $keyword['keyword'], $top, $rowHeight);
            $this->addTopMessageProgramCells($slide, $keyword['programs'], $programsLeft, $programsWidth, $top, $rowHeight);
            $top += $rowHeight + self::TM_ROW_GAP_IN;
        }
    }

    private function addTopMessageKeywordCell(Slide $slide, string $keyword, float $top, float $height): void
    {
        $this->addFilledRect($slide, self::TM_RIGHT_LEFT_IN, $top, self::TM_KEYWORD_WIDTH_IN, $height, self::NAVY);

        $maxPt = (int) config('admin_comparison_pptx.top_message_keyword_max_pt');
        $floor = max(7, (int) round($maxPt * (float) config('admin_comparison_pptx.top_message_min_font_ratio')));
        $width = self::TM_KEYWORD_WIDTH_IN - 0.1;
        $size = $floor;
        $text = $keyword;
        for ($pt = $maxPt; $pt >= $floor; $pt--) {
            if ($this->topMessageTextHeight($keyword, $width, $pt, true) <= $height - 0.1) {
                $size = $pt;
                break;
            }
        }
        // 下限の大きさでも収まらないときだけ、収まる行数で省略する(キーワードは本文の語のため通常は起きない)。
        if ($this->topMessageTextHeight($keyword, $width, $size, true) > $height - 0.1) {
            $text = $this->wrapOrEllipsizeForLines($keyword, $width, (float) $size, true, max(1, (int) floor(($height - 0.1) / ($size * 1.25 / 72))));
        }

        $box = $slide->createRichTextShape();
        $this->position($box, self::TM_RIGHT_LEFT_IN, $top, self::TM_KEYWORD_WIDTH_IN, $height);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $paragraph = $box->getActiveParagraph();
        $paragraph->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // 2行になる長さは、1文字だけの行(「…づく／り」)が出ないよう、ほぼ半分に分ける(企業名と同じ処理)。
        $lines = (int) ceil(mb_strwidth($text, 'UTF-8') / $this->maxUnitsPerLine($width, (float) $size, true));
        if ($lines === 2) {
            $this->renderBalancedLines($paragraph, $text, $width, (float) $size, true, self::WHITE);
        } else {
            $this->font($paragraph->createTextRun($text), $size, true, self::WHITE);
        }
    }

    /**
     * 制度を横に並べる。全部が収まる大きさが下限を下回るなら、最後の制度を1つ減らして
     * 幅を広げ、もう一度試す(1つになるまで)。
     *
     * @param  list<array{name: string, detail: string}>  $programs
     */
    private function addTopMessageProgramCells(Slide $slide, array $programs, float $left, float $width, float $top, float $height): void
    {
        $programs = array_slice($programs, 0, 3);

        for ($shown = count($programs); $shown >= 1; $shown--) {
            $cellWidth = ($width - ($shown - 1) * self::TM_CELL_GAP_IN) / $shown;
            $sizes = $this->fitTopMessageCells(array_slice($programs, 0, $shown), $cellWidth, $height);
            if ($sizes === null && $shown > 1) {
                continue;
            }
            // 1つにしても収まらない場合は、下限の大きさで描く(この段の中身は検証済みの短い文章のため、通常は起きない)。
            $sizes ??= $this->topMessageFloorSizes();

            foreach (array_slice($programs, 0, $shown) as $i => $program) {
                $cellLeft = $left + $i * ($cellWidth + self::TM_CELL_GAP_IN);
                $this->addFilledRect($slide, $cellLeft, $top, $cellWidth, $height, self::BAND);
                $this->drawRectOutline($slide, $cellLeft, $top, $cellWidth, $height, self::RULE, 0.75);

                $box = $slide->createRichTextShape();
                $this->position($box, $cellLeft, $top, $cellWidth, $height);
                $box->setWrap(RichText::WRAP_SQUARE);
                $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
                $namePara = $box->getActiveParagraph();
                $this->font($namePara->createTextRun($program['name']), $sizes[0], true, self::NAVY);
                if ($program['detail'] !== '') {
                    $detailPara = $box->createParagraph();
                    $this->font($detailPara->createTextRun($program['detail']), $sizes[1], false, self::BODY_TEXT);
                }
            }

            return;
        }
    }

    /**
     * 全部のセルが高さに収まる最大の大きさ([nameのpt, detailのpt])。nameとdetailは
     * 同じ比で小さくする。下限(最大値 × config比)でも収まらなければnull。
     *
     * @param  list<array{name: string, detail: string}>  $programs
     * @return ?array{0: int, 1: int}
     */
    private function fitTopMessageCells(array $programs, float $cellWidth, float $cellHeight): ?array
    {
        $nameMax = (int) config('admin_comparison_pptx.top_message_program_name_max_pt');
        $detailMax = (int) config('admin_comparison_pptx.top_message_program_detail_max_pt');
        [$nameFloor] = $this->topMessageFloorSizes();

        for ($name = $nameMax; $name >= $nameFloor; $name--) {
            $detail = max(7, (int) round($detailMax * $name / $nameMax));
            $fits = true;
            foreach ($programs as $program) {
                $used = $this->topMessageTextHeight($program['name'], $cellWidth, $name, true)
                    + ($program['detail'] !== '' ? $this->topMessageTextHeight($program['detail'], $cellWidth, $detail, false) : 0)
                    + self::TM_CELL_PADDING_IN;
                if ($used > $cellHeight) {
                    $fits = false;
                    break;
                }
            }
            if ($fits) {
                return [$name, $detail];
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function topMessageFloorSizes(): array
    {
        $ratio = (float) config('admin_comparison_pptx.top_message_min_font_ratio');
        $nameMax = (int) config('admin_comparison_pptx.top_message_program_name_max_pt');
        $detailMax = (int) config('admin_comparison_pptx.top_message_program_detail_max_pt');

        return [max(7, (int) round($nameMax * $ratio)), max(7, (int) round($detailMax * $ratio))];
    }

    /**
     * 文字を折り返して置いたときの高さ(in)の見積もり。1行に入る表示幅(maxUnitsPerLine、
     * 社名の折り返しと同じ保守的な係数)で行数を数え、行の高さを文字の大きさの1.25倍とする。
     */
    private function topMessageTextHeight(string $text, float $widthIn, int $sizePt, bool $bold): float
    {
        $lines = max(1, (int) ceil(mb_strwidth($text, 'UTF-8') / $this->maxUnitsPerLine($widthIn, (float) $sizePt, $bold)));

        return $lines * $sizePt * 1.25 / 72;
    }

    /**
     * 下の出典: 「出典：」+ 使ったページのタイトル(長ければ切る)+ 固定の一文。
     *
     * @param  list<string>  $sources
     */
    private function topMessageSourceNote(array $sources): string
    {
        $max = (int) config('admin_comparison_pptx.top_message_source_label_max_chars');
        $labels = array_map(
            fn (string $label) => mb_strlen($label) > $max ? mb_substr($label, 0, $max).'…' : $label,
            $sources,
        );

        $head = $labels === []
            ? ''
            : config('admin_comparison_pptx.top_message_source_prefix').implode((string) config('admin_comparison_pptx.top_message_source_separator'), $labels).'。';

        return $head.config('admin_comparison_pptx.top_message_disclaimer');
    }

    /**
     * 依頼CQ-4: 作られなかった会社があるとき、比較のページの下に注記を1行足す。
     */
    private function addTopMessageMissingNote(Slide $slide, string $note): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, self::TM_MISSING_NOTE_TOP_IN, self::CONTENT_WIDTH_IN, 0.26);
        $box->setWrap(RichText::WRAP_SQUARE);
        $this->font($box->getActiveParagraph()->createTextRun($note), 8, false, self::GAP_TEXT);
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
     * 依頼CM-3(2026-10-06): 企業名の描画。従来はrenderBalancedLines()が表示幅の
     * ほぼ半分で機械的に2行へ割っていたため、「サイボウズ」「マネーフォワード」が
     * 語の途中で切れていた(本番の資料で確認)。企業名は語の切れ目で折る:
     *  1. 1行に収まるなら折らない。文字を小さくして収まるなら、下限
     *     (config: company_name_min_font_ratio / company_name_absolute_min_pt)まで
     *     小さくしてよい。
     *  2. 収まらなければ、語の切れ目(config: company_name_break_words
     *     「株式会社」等の前後 / company_name_break_after_chars「・」の後 /
     *     company_name_break_space_chars 空白)で$maxLines行以内に分ける。全ての行が
     *     収まる分け方のうち、最も長い行が最も短くなるものを選ぶ。やはり収まらなければ
     *     下限まで文字を小さくして試す。
     *  3. それでも収まらない(語そのものが行より長い等)ときだけ、既存の省略の処理
     *     (wrapOrEllipsizeForLines + renderBalancedLines)に落とす。
     * 企業名の表示だけに使う(文章の折り返しには使わない)。
     *
     * $prefixは企業名の前に付ける記号(例: 「A　」)。1行目の幅に含めて数える。
     * $widthInは文字を置ける幅(呼び出し側で左右のインセットを0にしておくこと)。
     */
    private function renderCompanyName(RichText\Paragraph $para, string $name, float $widthIn, float $sizePt, bool $bold, string $color, string $prefix = '', ?string $prefixColor = null, int $maxLines = 2): void
    {
        $baseSize = (int) round($sizePt);
        $floor = max((int) config('admin_comparison_pptx.company_name_absolute_min_pt'), (int) round($baseSize * (float) config('admin_comparison_pptx.company_name_min_font_ratio')));
        $floor = min($floor, $baseSize);
        $prefixUnits = mb_strwidth($prefix, 'UTF-8');

        if ($prefix !== '') {
            $this->font($para->createTextRun($prefix), $baseSize + 1, true, $prefixColor ?? $color);
        }

        // 1. 1行(文字を小さくして収まるなら下限まで)。
        for ($size = $baseSize; $size >= $floor; $size--) {
            if (mb_strwidth($name, 'UTF-8') + $prefixUnits <= $this->maxUnitsPerLine($widthIn, (float) $size, $bold)) {
                $this->font($para->createTextRun($name), $size, $bold, $color);

                return;
            }
        }

        // 2. 語の切れ目で複数行(大きい文字から順に、下限まで)。
        $tokens = $this->companyNameTokens($name);
        for ($size = $baseSize; $size >= $floor; $size--) {
            $lines = $this->partitionCompanyName($tokens, $maxLines, $this->maxUnitsPerLine($widthIn, (float) $size, $bold), $prefixUnits);
            if ($lines !== null) {
                foreach ($lines as $i => $line) {
                    if ($i > 0) {
                        $para->createBreak();
                    }
                    $this->font($para->createTextRun($line), $size, $bold, $color);
                }

                return;
            }
        }

        // 依頼CO-4: 語の切れ目で分けても収まらない(1語が行より長い)ときは、折る前に下限まで
        // 文字を小さくし、その大きさでも収まらない語だけを、行頭禁則を避けて等分し、
        // 語の切れ目(株式会社の前後など)はそのまま残して行に詰める。
        $floorUnits = $this->maxUnitsPerLine($widthIn, (float) $floor, $bold);
        $pieces = [];
        foreach ($tokens as $token) {
            array_push($pieces, ...$this->splitLongCompanyNameToken($token, max(2, $floorUnits)));
        }
        $lines = $this->partitionCompanyName($pieces, $maxLines, $floorUnits, $prefixUnits);
        if ($lines !== null) {
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $para->createBreak();
                }
                $this->font($para->createTextRun($line), $floor, $bold, $color);
            }

            return;
        }

        // 3. 既存の省略の処理に落とす(語の途中で折れることはあり得るが、極端に長い名前のみ)。
        $fallbackWidth = max(0.5, $widthIn - $prefixUnits * 0.07);
        // 依頼CO-4: 折る前に、下限まで文字を小さくしてから折る(折る位置は行頭禁則を避ける)。
        $text = $this->wrapOrEllipsizeForLines($name, $fallbackWidth, (float) $floor, $bold, 2);
        $this->renderBalancedLines($para, $text, $fallbackWidth, (float) $floor, $bold, $color);
    }

    /**
     * 1行($maxUnits)に収まらない語だけを、長音・小さいかなが行頭に来ない位置で、ほぼ等分に分ける。
     * 収まる語はそのまま返す。
     *
     * @return list<string>
     */
    private function splitLongCompanyNameToken(string $token, int $maxUnits): array
    {
        if (mb_strwidth($token, 'UTF-8') <= $maxUnits) {
            return [$token];
        }

        [$head, $tail] = $this->splitBalancedForTwoLines($token, $maxUnits);
        if ($head === '' || $tail === '') {
            return [$token];
        }

        return [...$this->splitLongCompanyNameToken($head, $maxUnits), ...$this->splitLongCompanyNameToken($tail, $maxUnits)];
    }

    /**
     * トークンを、語の切れ目だけで$maxLines行以内に分ける。どの行も$maxUnits
     * (1行目は記号ぶん$firstLinePrefixUnitsを差し引く)以内に収まる分け方のうち、
     * 最も長い行が最も短くなるものを返す。収まる分け方が無ければnull。
     *
     * @param  list<string>  $tokens
     * @return list<string>|null
     */
    private function partitionCompanyName(array $tokens, int $maxLines, int $maxUnits, int $firstLinePrefixUnits): ?array
    {
        $count = count($tokens);
        if ($count < 2 || $maxLines < 2) {
            return null;
        }

        $best = null;
        $search = function (int $start, array $lines) use (&$search, &$best, $tokens, $count, $maxLines, $maxUnits, $firstLinePrefixUnits): void {
            if ($start >= $count) {
                $widest = 0;
                foreach ($lines as $i => $line) {
                    $widest = max($widest, mb_strwidth($line, 'UTF-8') + ($i === 0 ? $firstLinePrefixUnits : 0));
                }
                if (count($lines) >= 2 && ($best === null || $widest < $best[0])) {
                    $best = [$widest, $lines];
                }

                return;
            }
            if (count($lines) >= $maxLines) {
                return;
            }
            for ($end = $start + 1; $end <= $count; $end++) {
                $line = $this->joinCompanyNameTokens(array_slice($tokens, $start, $end - $start));
                $units = mb_strwidth($line, 'UTF-8') + (count($lines) === 0 ? $firstLinePrefixUnits : 0);
                if ($units > $maxUnits) {
                    break;
                }
                $search($end, [...$lines, $line]);
            }
        };
        $search(0, []);

        return $best === null ? null : $best[1];
    }

    /**
     * 企業名を語の切れ目で分けたトークンにする。空白は捨て、config
     * 'company_name_break_words'(株式会社など)は独立したトークンに、
     * 'company_name_break_after_chars'(・など)は直前のトークンの末尾に付ける。
     *
     * @return list<string>
     */
    private function companyNameTokens(string $name): array
    {
        $words = array_values(array_filter((array) config('admin_comparison_pptx.company_name_break_words'), fn ($w) => is_string($w) && $w !== ''));
        $afterChars = (array) config('admin_comparison_pptx.company_name_break_after_chars');
        $spaceChars = (array) config('admin_comparison_pptx.company_name_break_space_chars');

        $parts = $words === []
            ? [$name]
            : (preg_split('/('.implode('|', array_map(fn (string $w) => preg_quote($w, '/'), $words)).')/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$name]);

        $tokens = [];
        foreach ($parts as $part) {
            if (in_array($part, $words, true)) {
                $tokens[] = $part;

                continue;
            }

            $current = '';
            foreach (mb_str_split($part) as $char) {
                if (in_array($char, $spaceChars, true)) {
                    if ($current !== '') {
                        $tokens[] = $current;
                    }
                    $current = '';
                } elseif (in_array($char, $afterChars, true)) {
                    $tokens[] = $current.$char;
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
            if ($current !== '') {
                $tokens[] = $current;
            }
        }

        return $tokens;
    }

    /**
     * トークンを1行に戻す。英数字どうしが隣り合う境目だけ空白を戻す
     * (「Fuji of Innovation」)。日本語との境目には入れない。
     *
     * @param  list<string>  $tokens
     */
    private function joinCompanyNameTokens(array $tokens): string
    {
        $line = '';
        foreach ($tokens as $token) {
            if ($line !== '' && preg_match('/[A-Za-z0-9]$/', $line) && preg_match('/^[A-Za-z0-9]/', $token)) {
                $line .= ' ';
            }
            $line .= $token;
        }

        return $line;
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

        // 依頼CO-4: 長音「ー」・小さい「ァィゥェォッャュョ」が行頭に来る位置では分けない
        // (「サイバーエ／ージェント」)。直前へ戻して、その文字を前の語と一緒に2行目へ送る。
        // 戻した結果1行目が空になるなら、逆に後ろへずらす。
        $noLineStart = array_values(array_filter((array) config('admin_comparison_pptx.company_name_no_line_start_chars'), fn ($c) => is_string($c) && $c !== ''));
        $count = count($chars);
        $back = $splitIndex;
        while ($back > 0 && $back < $count && in_array($chars[$back], $noLineStart, true)) {
            $back--;
        }
        if ($back > 0) {
            $splitIndex = $back;
        } else {
            while ($splitIndex < $count - 1 && in_array($chars[$splitIndex], $noLineStart, true)) {
                $splitIndex++;
            }
        }

        return [implode('', array_slice($chars, 0, $splitIndex)), implode('', array_slice($chars, $splitIndex))];
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
