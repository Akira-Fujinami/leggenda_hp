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
 * 依頼BM(2026-09-09): 旧構成(「競合が伝えていて自社が伝えていない項目」の
 * 一覧)は、その件数に依存するため、自社が強いとページの下2/3が白紙になる
 * (実データで確認済みの不具合)。6領域は分母4で固定のため、matched件数に
 * 関わらず必ず埋まる。他社サイトの本文引用は一切扱わない(依頼BM-4)。
 *
 * 依頼BO(2026-09-09): まとめの帯の下が「薄い」との指摘を受け、帯の高さを
 * summaryの実際の行数から可変にし、空いた縦へ「競合が伝えていて自社が
 * 伝えていない項目」一覧を追加した。他社サイトの引用は引き続き一切扱わない
 * (依頼BM-4を維持、ここで読むのはaxis_name/sub_nameの2フィールドのみ)。
 *
 * 依頼BQ(2026-09-11): 依頼BOの「余白を埋める」という動機自体が誤りだった
 * との指摘を受け、まとめの帯と項目一覧を1枚のカードに統合した
 * (addSummaryAndMissingItemsCard())。カードの高さは中身の実際の行数
 * ちょうどに合わせ、footer直前まで無理に埋めることはしない ―― 項目が
 * 2件しか無ければカードもそのぶん低くなる。抽出条件(過半数)自体は
 * BrandWheelMultiSiteComparisonComposerのまま変更していない。
 *
 * 【差し込みの前提、依頼BK/BL/BG由来・変更禁止】
 * - スライドサイズは12192000×6858000EMU固定(setCXにUNIT_INCHで13.333を
 *   渡すと丸め誤差で不正なXMLになるため、EMUを直接指定する)。
 * - schemeClr(テーマ色)を使わない。色は全てColor()経由のsrgbClr(明示RGB)。
 * - フォントはMeiryoを明示指定する(font()参照)。
 * - 画像・グラフ・埋め込みオブジェクトを使わない
 *   (AdminComparisonPptxInserter::extractComparisonSlideParts()が
 *   r:id/r:embed/r:linkの出現を検知して差し込みを中止する)。
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

    private const LIGHT_COPPER = 'E0A06B';

    private const BODY_TEXT = '0C1726';

    private const MUTED = '5A6B82';

    private const DIM = '9AA6B4';

    private const RULE = 'D9DFE7';

    private const BAND = 'FAFBFC';

    private const WHITE = 'FFFFFF';

    private const SELF_TINT = 'EEF1F5';

    private const GAP_BG = 'FBEEE3';

    private const GAP_TEXT = 'A85B1E';

    private const SUMMARY_BG = 'FBF6F1';

    // ------------------------------------------------------------------
    // スコア帯(依頼BM-5: 社名の折り返しを許すため、旧版より縦に広げた)。
    // ------------------------------------------------------------------

    private const TILE_TOP_IN = 1.55;

    private const TILE_HEIGHT_IN = 1.0;

    private const TILE_GAP_IN = 0.095;

    private const TILE_NAME_HEIGHT_IN = 0.46;

    private const TILE_NUMBER_HEIGHT_IN = 0.4;

    // ------------------------------------------------------------------
    // マトリクス(依頼BM-1: 6領域固定、常に埋まる)。
    // ------------------------------------------------------------------

    private const SECTION_TITLE_TOP_IN = 2.65;

    private const TABLE_TOP_IN = 2.95;

    /** ヘッダー行の高さは2行分で固定する(依頼BM-5、社名を切り詰めない)。 */
    private const TABLE_HEADER_HEIGHT_IN = 0.5;

    /** 領域名+補足を2行(別シェイプ)で収めるため、1行運用より高めに取る。 */
    private const TABLE_ROW_HEIGHT_IN = 0.36;

    private const AREA_COL_WIDTH_IN = 2.6;

    private const SUMMARY_TOP_IN = 5.69;

    /**
     * 依頼BO-2: まとめの帯が1行のときも旧来の3行ぶん(0.85in)の高さで
     * 描かれ、下に間延びした余白が残っていた(依頼者指摘)。帯の高さを
     * summary文字列の実際の折り返し行数(estimateLineCount、
     * wrapOrEllipsizeForLinesと同じmb_strwidthベースの見積もり)から
     * 動的に計算するようにし、空いた縦をBO-1の「不足している項目」
     * 一覧に回す。
     *
     * SUMMARY_LINE_HEIGHT_IN=0.23inは、旧来の固定高0.85in(依頼BM-2で
     * 「3行に収まる」根拠として実機確認済みだった値)を
     * 0.16(上下パディング)+3行で逆算した値(0.85-0.16=0.69、0.69÷3=0.23)
     * ―― 1行あたりの実測済みの行送りをそのまま再利用しているため、
     * 新しい行数(1〜2行)でも安全側の値になる。
     */
    /**
     * 依頼BQ-2(2026-09-11): SUMMARY_PADDING_INは、依頼BOでは「まとめの帯
     * 単体」の上下パディングだったが、まとめと項目一覧を1枚のカードに
     * 統合した(addSummaryAndMissingItemsCard())ことで、いまは「カード
     * 全体」の上下パディングを表す(まとめ側の上パディング0.08in+項目
     * 一覧側の下パディング0.08inで計0.16in、中身の実際の行数ぶんだけ
     * カードが伸び縮みする)。
     */
    private const SUMMARY_PADDING_IN = 0.16;

    private const SUMMARY_LINE_HEIGHT_IN = 0.23;

    private const SUMMARY_FONT_SIZE = 11;

    // ------------------------------------------------------------------
    // 不足している項目一覧(依頼BO-1、依頼BQ-2でまとめの帯と1枚のカードに
    // 統合)。
    // ------------------------------------------------------------------

    /** まとめの文章ブロックと項目一覧ブロックの、カード内での間隔。 */
    private const MISSING_ITEMS_GAP_IN = 0.08;

    private const MISSING_ITEMS_FONT_SIZE = 10.0;

    private const MISSING_ITEMS_LINE_HEIGHT_IN = 0.205;

    /**
     * 依頼BQ-2: カードの高さの「安全上限」。カードはfooter直前まで
     * 埋めようとはしない(依頼者指定 ―― 中身が少なければカードも低く
     * なる)が、項目が多いときに万一収まらずfooterの出典行(y=6.62in)と
     * 重なることが無いよう、頭打ちの基準としてのみ使う
     * (addSummaryAndMissingItemsCard参照)。
     */
    private const MISSING_ITEMS_BOTTOM_LIMIT_IN = 6.58;

    /**
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
     *     summary: string,
     *     missing_items: array{
     *         heading: string,
     *         empty_text: string,
     *         items: list<array{axis_name: string, sub_name: string}>,
     *         others_count: int,
     *     },
     *     source_note: string,
     *     page_number: ?string,
     * } $data
     */
    public function generate(array $data): string
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

        $this->addKicker($slide);
        $this->addTitle($slide);
        $this->addScoreTiles($slide, $data['companies']);
        $this->addMatrixSection($slide, $data['companies'], $data['axes']);
        $this->addSummaryAndMissingItemsCard($slide, $data['summary'], $data['missing_items']);
        $this->addFooter($slide, $data['source_note'], $data['page_number']);

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

    private function addTitle(Slide $slide): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, 0.86, self::CONTENT_WIDTH_IN, 0.55);
        $run = $box->getActiveParagraph()->createTextRun('採用ブランド24項目の他社比較');
        $this->font($run, 25, true, self::NAVY);
    }

    /**
     * @param  list<array{name: string, matched: int, total: int, is_self: bool}>  $companies
     */
    private function addScoreTiles(Slide $slide, array $companies): void
    {
        $n = count($companies);
        $gap = self::TILE_GAP_IN;
        $width = ($n > 0) ? (self::CONTENT_WIDTH_IN - ($n - 1) * $gap) / $n : 0;

        foreach ($companies as $i => $company) {
            $left = self::LEFT_IN + $i * ($width + $gap);
            $isSelf = $company['is_self'];

            $tile = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
            $this->position($tile, $left, self::TILE_TOP_IN, $width, self::TILE_HEIGHT_IN);
            $tile->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.($isSelf ? self::NAVY : self::WHITE)));
            if ($isSelf) {
                $tile->getBorder()->setLineStyle(Border::LINE_NONE);
            } else {
                $tile->getBorder()->setLineWidth(0.75)->setColor(new Color('FF'.self::RULE));
            }

            // 依頼BM-5: 社名を切り詰めず、2行までの折り返しを許す
            // (旧版は1行に収まるよう強制的に省略記号で切っていた ――
            // 本番の実データで「株式会社Fuji of In…」のように自社名が
            // 切れる不具合として確認済み)。
            $nameTop = self::TILE_TOP_IN + 0.08;
            $labelBox = $slide->createRichTextShape();
            $this->position($labelBox, $left, $nameTop, $width, self::TILE_NAME_HEIGHT_IN);
            $labelBox->setWrap(RichText::WRAP_SQUARE);
            $labelBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $labelText = $this->wrapOrEllipsizeForLines($company['name'], $width, 9.5, true, 2);
            $this->renderBalancedLines($labelBox->getActiveParagraph(), $labelText, $width, 9.5, true, $isSelf ? self::WHITE : self::MUTED);

            $numberTop = $nameTop + self::TILE_NAME_HEIGHT_IN;
            $numberBox = $slide->createRichTextShape();
            $this->position($numberBox, $left, $numberTop, $width, self::TILE_NUMBER_HEIGHT_IN);
            $numberBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $numberRun = $numberBox->getActiveParagraph()->createTextRun("{$company['matched']} / {$company['total']}");
            $this->font($numberRun, 17, true, $isSelf ? self::LIGHT_COPPER : self::NAVY);
        }
    }

    /**
     * 依頼BM-1: 「競合が伝えていて自社が伝えていない項目」一覧に代わる、
     * 6領域(config('brand_wheel.axes')順)×各社のマトリクス。分母4固定
     * (=各領域のsub_elements件数)のため、matched件数に関わらず必ず埋まる
     * ―― 旧版の「該当0件だと下2/3が白紙になる」不具合はこの構成では
     * 起こり得ない。
     *
     * @param  list<array{name: string, matched: int, total: int, is_self: bool}>  $companies
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     */
    private function addMatrixSection(Slide $slide, array $companies, array $axes): void
    {
        $heading = $slide->createRichTextShape();
        $this->position($heading, self::LEFT_IN, self::SECTION_TITLE_TOP_IN, self::CONTENT_WIDTH_IN, 0.28);
        $run = $heading->getActiveParagraph()->createTextRun('領域別の発信量');
        $this->font($run, 12.5, true, self::NAVY);
        $noteRun = $heading->getActiveParagraph()->createTextRun('　各領域4項目・○と判定できた数');
        $this->font($noteRun, 9.5, false, self::MUTED);

        $this->addLegend($slide);

        $companyCount = count($companies);
        $colWidth = ($companyCount > 0) ? (self::CONTENT_WIDTH_IN - self::AREA_COL_WIDTH_IN) / $companyCount : 0;

        $this->addMatrixHeader($slide, $companies, $colWidth);

        foreach ($axes as $i => $axis) {
            $top = self::TABLE_TOP_IN + self::TABLE_HEADER_HEIGHT_IN + $i * self::TABLE_ROW_HEIGHT_IN;
            $this->addMatrixRow($slide, $axis, $companies, $colWidth, $top, $i % 2 === 1);
        }
    }

    /**
     * 依頼BN-3(2026-09-09): オレンジの網かけ・競合内の最高値の太字が
     * 何を意味するか、スライドのどこにも説明が無かった(初見の商談相手には
     * 伝わらない、依頼者指摘)。「領域別の発信量」見出しと同じ行の右側に
     * 凡例を置く ―― 縦の余白を新たに使わない(依頼者指定の制約)。
     *
     * 濃淡(競合内の最高値を太字にする表現)は残す判断とした(依頼者の
     * 推し・依頼BN-3参照)。全社が同値の行では該当する競合全員が太字に
     * なる(例: 情緒的便益で競合3社が同値)が、これは「その領域の競合内
     * 最高値」という凡例の説明どおりの正しい表示であり、誤りではないため。
     */
    private function addLegend(Slide $slide): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN + 6.6, self::SECTION_TITLE_TOP_IN, self::CONTENT_WIDTH_IN - 6.6, 0.28);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $para = $box->getActiveParagraph();
        $para->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->font($para->createTextRun('■'), 8, false, self::GAP_TEXT);
        $this->font($para->createTextRun(' 自社が競合の最高値未達　'), 8, false, self::MUTED);
        $this->font($para->createTextRun('■'), 8, true, self::NAVY);
        $this->font($para->createTextRun(' 競合内の最高値'), 8, false, self::MUTED);
    }

    /**
     * @param  list<array{name: string, matched: int, total: int, is_self: bool}>  $companies
     */
    private function addMatrixHeader(Slide $slide, array $companies, float $colWidth): void
    {
        $band = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($band, self::LEFT_IN, self::TABLE_TOP_IN, self::CONTENT_WIDTH_IN, self::TABLE_HEADER_HEIGHT_IN);
        $band->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::NAVY));
        $band->getBorder()->setLineStyle(Border::LINE_NONE);

        $areaBox = $slide->createRichTextShape();
        $this->position($areaBox, self::LEFT_IN + 0.12, self::TABLE_TOP_IN, self::AREA_COL_WIDTH_IN - 0.12, self::TABLE_HEADER_HEIGHT_IN);
        $areaBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($areaBox->getActiveParagraph()->createTextRun('領域'), 10.5, true, self::WHITE);

        foreach ($companies as $i => $company) {
            $left = self::LEFT_IN + self::AREA_COL_WIDTH_IN + $i * $colWidth;
            $box = $slide->createRichTextShape();
            $this->position($box, $left, self::TABLE_TOP_IN, $colWidth, self::TABLE_HEADER_HEIGHT_IN);
            $box->setWrap(RichText::WRAP_SQUARE);
            $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $box->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            // 依頼BM-5: ヘッダーの高さは2行分で固定。2行に収まる社名は
            // 折り返しをそのまま許し(切り詰めない)、2行に収まらない
            // 極端に長い社名だけ、2行分の文字数で省略記号にする
            // (対処の提案。ヘッダーの高さを固定する以上、際限なく伸ばせない)。
            $text = $this->wrapOrEllipsizeForLines($company['name'], $colWidth, 10, true, 2);
            $this->renderBalancedLines($box->getActiveParagraph(), $text, $colWidth, 10, true, self::WHITE);
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

        // 依頼BM-1: 領域名+補足は、同じ行に並べると(フォントサイズが
        // 混在するため)幅の見積もりが合わず、意図しない位置で折り返して
        // 次の行と重なる不具合が実機画像化で見つかった。名前と補足を別々の
        // 行(別シェイプ)にして、それぞれ1行に収まる前提で高さを固定する。
        $areaNameBox = $slide->createRichTextShape();
        $this->position($areaNameBox, self::LEFT_IN + 0.12, $top + 0.02, self::AREA_COL_WIDTH_IN - 0.12, 0.19);
        $this->font($areaNameBox->getActiveParagraph()->createTextRun($axis['name']), 10.5, true, self::BODY_TEXT);

        if ($axis['caption'] !== null) {
            $captionBox = $slide->createRichTextShape();
            $this->position($captionBox, self::LEFT_IN + 0.12, $top + 0.2, self::AREA_COL_WIDTH_IN - 0.12, 0.15);
            $this->font($captionBox->getActiveParagraph()->createTextRun($axis['caption']), 7.5, false, self::MUTED);
        }

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
            $this->font($cell->getActiveParagraph()->createTextRun("{$count} / {$axis['denominator']}"), 11, true, $fg);
        }
    }

    /**
     * 依頼BQ-2(2026-09-11): まとめの帯と「不足している項目」一覧を、
     * 1枚のカード(アクセントバー+背景を共有)に統合した。
     *
     * 依頼BOでは、まとめの帯(色付き)と項目一覧(色の無いプレーンテキスト)を
     * 別々の要素として積み上げ、項目一覧の高さをfooter直前までの残り全部
     * (旧MISSING_ITEMS_BOTTOM_LIMIT_IN)で確保していた。これは「件数が
     * 多いときに壊れない」ためには有効だったが、逆に件数が少ない
     * (実データで2件のケースを確認)と、色付きの帯のすぐ下に色の無い
     * 短い1〜2行だけが浮き、その下がfooterまで大きく空く、という
     * 不自然な見た目になっていた(依頼BQ指摘 ―― 依頼BOの「余白を埋める」
     * という当初の動機が、そもそも目的として誤りだったとの指摘を受けた)。
     *
     * 直しかた:
     *   - まとめの文章と項目一覧を同じ1枚のカードに収め、カードの高さは
     *     「まとめの実際の行数+項目一覧の実際の行数」ちょうどに合わせる
     *     (依頼者の言う「詰める」「帯と一体にする」)。footer直前までの
     *     残りを埋めようとはしない ―― 件数が少なければカードも低くなる。
     *   - footer衝突防止(依頼BM-2由来)は、カード高さの「安全上限」として
     *     残す(MISSING_ITEMS_BOTTOM_LIMIT_IN) ―― 万一項目が多くて
     *     収まらない場合だけこの上限で頭打ちにする(missing_items_max_count・
     *     fitMissingItems()の「多いときは削ってほかN件に畳む」仕組みは
     *     そのまま維持)。通常時(件数が少ない)はこの上限に達しないため、
     *     カードはfooterよりずっと上で終わる。
     *
     * @param  array{heading: string, empty_text: string, items: list<array{axis_name: string, sub_name: string}>, others_count: int}  $missingItems
     */
    private function addSummaryAndMissingItemsCard(Slide $slide, string $summary, array $missingItems): void
    {
        $textWidth = self::CONTENT_WIDTH_IN - 0.5;

        $summaryLines = $this->estimateLineCount($summary, $textWidth, self::SUMMARY_FONT_SIZE);
        $summaryBlockHeight = $summaryLines * self::SUMMARY_LINE_HEIGHT_IN;

        // footer直前までの残りを「安全上限」として使う(依頼BM-2由来の
        // footer衝突防止をそのまま維持)。カードの高さをこの上限まで
        // 埋める目的では使わない ―― あくまで項目が多いときの頭打ち。
        $ceilingHeight = max(
            self::MISSING_ITEMS_LINE_HEIGHT_IN,
            self::MISSING_ITEMS_BOTTOM_LIMIT_IN - self::SUMMARY_TOP_IN - self::SUMMARY_PADDING_IN - $summaryBlockHeight - self::MISSING_ITEMS_GAP_IN,
        );
        $maxLines = max(1, (int) floor($ceilingHeight / self::MISSING_ITEMS_LINE_HEIGHT_IN));

        $heading = $missingItems['heading'].'：';

        if ($missingItems['items'] === []) {
            $shown = [];
            $others = 0;
            $itemsLines = $this->estimateLineCount($heading.$missingItems['empty_text'], $textWidth, self::MISSING_ITEMS_FONT_SIZE);
        } else {
            [$shown, $others] = $this->fitMissingItems($missingItems['items'], $missingItems['others_count'], $missingItems['heading'], $textWidth, $maxLines);
            $itemsLines = $this->estimateLineCount($heading.$this->joinMissingItems($shown, $others), $textWidth, self::MISSING_ITEMS_FONT_SIZE);
        }
        $itemsBlockHeight = $itemsLines * self::MISSING_ITEMS_LINE_HEIGHT_IN;

        $cardHeight = self::SUMMARY_PADDING_IN + $summaryBlockHeight + self::MISSING_ITEMS_GAP_IN + $itemsBlockHeight;

        $accent = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($accent, self::LEFT_IN, self::SUMMARY_TOP_IN, 0.05, $cardHeight);
        $accent->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::COPPER));
        $accent->getBorder()->setLineStyle(Border::LINE_NONE);

        $band = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($band, self::LEFT_IN + 0.05, self::SUMMARY_TOP_IN, self::CONTENT_WIDTH_IN - 0.05, $cardHeight);
        $band->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::SUMMARY_BG));
        $band->getBorder()->setLineStyle(Border::LINE_NONE);

        $summaryBox = $slide->createRichTextShape();
        $this->position($summaryBox, self::LEFT_IN + 0.25, self::SUMMARY_TOP_IN + 0.08, $textWidth, $summaryBlockHeight);
        $summaryBox->setWrap(RichText::WRAP_SQUARE);
        $this->font($summaryBox->getActiveParagraph()->createTextRun($summary), self::SUMMARY_FONT_SIZE, false, self::GAP_TEXT);

        $itemsTop = self::SUMMARY_TOP_IN + 0.08 + $summaryBlockHeight + self::MISSING_ITEMS_GAP_IN;
        $itemsBox = $slide->createRichTextShape();
        $this->position($itemsBox, self::LEFT_IN + 0.25, $itemsTop, $textWidth, $itemsBlockHeight);
        $itemsBox->setWrap(RichText::WRAP_SQUARE);
        $para = $itemsBox->getActiveParagraph();

        $this->font($para->createTextRun($heading), self::MISSING_ITEMS_FONT_SIZE, true, self::NAVY);

        if ($missingItems['items'] === []) {
            $this->font($para->createTextRun($missingItems['empty_text']), self::MISSING_ITEMS_FONT_SIZE, false, self::MUTED);

            return;
        }

        foreach ($shown as $item) {
            $this->font($para->createTextRun("　「{$item['axis_name']}」{$item['sub_name']}"), self::MISSING_ITEMS_FONT_SIZE, false, self::BODY_TEXT);
        }

        if ($others > 0) {
            $this->font($para->createTextRun("　ほか{$others}件"), self::MISSING_ITEMS_FONT_SIZE, false, self::MUTED);
        }
    }

    /**
     * 見出し込みで折り返し行数がmaxLines以内に収まる最大件数を、先頭から
     * 貪欲に探す(件数降順の並びを崩さないため、末尾から削る)。削った分は
     * othersCountへ繰り込む。見出し部分は実際は太字だが項目列は非太字
     * ―― estimateLineCountのキャリブレーション(BO-2)では太字・非太字の
     * 実測差がほぼ無かったため、太さを区別せず同じ係数で見積もっている。
     *
     * @param  list<array{axis_name: string, sub_name: string}>  $items
     * @return array{0: list<array{axis_name: string, sub_name: string}>, 1: int}
     */
    private function fitMissingItems(array $items, int $baseOthersCount, string $heading, float $widthIn, int $maxLines): array
    {
        for ($n = count($items); $n >= 0; $n--) {
            $others = $baseOthersCount + (count($items) - $n);
            $text = $heading.'：'.$this->joinMissingItems(array_slice($items, 0, $n), $others);

            if ($this->estimateLineCount($text, $widthIn, self::MISSING_ITEMS_FONT_SIZE) <= $maxLines) {
                return [array_slice($items, 0, $n), $others];
            }
        }

        return [[], $baseOthersCount + count($items)];
    }

    /**
     * @param  list<array{axis_name: string, sub_name: string}>  $items
     */
    private function joinMissingItems(array $items, int $others): string
    {
        $text = implode('', array_map(fn (array $item) => "　「{$item['axis_name']}」{$item['sub_name']}", $items));

        return $others > 0 ? $text."　ほか{$others}件" : $text;
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
