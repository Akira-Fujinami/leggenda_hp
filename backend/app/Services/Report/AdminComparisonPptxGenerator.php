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
 * 依頼AT(2026-09-03)の検証用スパイク実装。承認前のプロトタイプにつき、
 * まだJob/Controller/ルートには一切接続していない(依頼AT-4「報告してから
 * 実装に入ること」/AT-3「提案・承認前に実装すること」の禁止事項を守るため)。
 *
 * 座標・色は共有された比較スライド_モックアップ.pptxのXMLを実測した値を
 * そのまま使う(依頼書AT-2の表と一致することを確認済み)。
 */
class AdminComparisonPptxGenerator
{
    private const EMU_PER_INCH = 914400;

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

    private const RULE = 'D9DFE7';

    private const BAND = 'F4F6F9';

    private const WHITE = 'FFFFFF';

    private const TILE_TOP_IN = 1.6;

    private const TILE_HEIGHT_IN = 0.86;

    private const TILE_GAP_IN = 0.095;

    private const TABLE_HEADER_TOP_IN = 2.98;

    private const TABLE_HEADER_HEIGHT_IN = 0.32;

    private const TABLE_ROWS_TOP_IN = 3.3;

    // 出所行(T6.35)の手前に空白を残すための、データ行が使える下端(依頼AT-4
    // 検証で判明: モックアップ実測のまま1行0.415inで8行敷くと6.62inとなり、
    // 出所行(T6.35)に重なる。行数に応じて行高を縮め、常にこの下端に収める。
    private const TABLE_ROWS_BOTTOM_IN = 6.2;

    private const TABLE_ROW_HEIGHT_MAX_IN = 0.415;

    /** @var array{sub_name: 2.35, axis_name: 1.75, count: 1.5, quote: 5.9} */
    private const COL_WIDTHS_IN = [
        'sub_name' => 2.35,
        'axis_name' => 1.75,
        'count' => 1.5,
        'quote' => 5.9,
    ];

    /**
     * @param  array{
     *     self_company_name: string,
     *     companies: list<array{name: string, matched: int, total: int, is_self: bool}>,
     *     competitor_count: int,
     *     rows: list<array{sub_name: string, axis_name: string, matched_count: int, quote: ?string}>,
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
        $this->addTitle($slide, $data['self_company_name']);
        $this->addScoreTiles($slide, $data['companies']);
        $this->addMissingSection($slide, $data['self_company_name'], $data['rows'], $data['competitor_count']);
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

    private function addTitle(Slide $slide, string $selfCompanyName): void
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

            $labelBox = $slide->createRichTextShape();
            $this->position($labelBox, $left, 1.68, $width, 0.26);
            $labelBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $labelText = $this->truncateForWidth($company['name'], $width, 9.5, true);
            $labelRun = $labelBox->getActiveParagraph()->createTextRun($labelText);
            $this->font($labelRun, 9.5, true, $isSelf ? self::WHITE : self::MUTED);

            $numberBox = $slide->createRichTextShape();
            $this->position($numberBox, $left, 1.94, $width, 0.44);
            $numberBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $numberRun = $numberBox->getActiveParagraph()->createTextRun("{$company['matched']} / {$company['total']}");
            $this->font($numberRun, 17, true, $isSelf ? self::LIGHT_COPPER : self::NAVY);
        }
    }

    /**
     * @param  list<array{sub_name: string, axis_name: string, matched_count: int, quote: ?string}>  $rows
     */
    private function addMissingSection(Slide $slide, string $selfCompanyName, array $rows, int $competitorCount): void
    {
        $heading = $slide->createRichTextShape();
        $this->position($heading, self::LEFT_IN, 2.62, self::CONTENT_WIDTH_IN, 0.3);
        $run = $heading->getActiveParagraph()->createTextRun("競合が伝えていて、{$selfCompanyName}が伝えていない項目(言及社数の多い順)");
        $this->font($run, 11, true, self::NAVY);

        if ($rows === []) {
            // 依頼AT報告事項(4): 0件時の表示(仮)。承認前のプロトタイプ表示。
            $empty = $slide->createRichTextShape();
            $this->position($empty, self::LEFT_IN, self::TABLE_HEADER_TOP_IN, self::CONTENT_WIDTH_IN, 0.5);
            $emptyRun = $empty->getActiveParagraph()->createTextRun("競合各社と比べて、今回の比較の範囲では、{$selfCompanyName}に不足している項目は見つかりませんでした。");
            $this->font($emptyRun, 9.5, false, self::MUTED);

            return;
        }

        $this->addTableHeader($slide);

        $rowCount = count($rows);
        $availableHeight = self::TABLE_ROWS_BOTTOM_IN - self::TABLE_ROWS_TOP_IN;
        $rowHeight = min(self::TABLE_ROW_HEIGHT_MAX_IN, $availableHeight / $rowCount);

        $x1 = self::LEFT_IN;
        $x2 = $x1 + self::COL_WIDTHS_IN['sub_name'];
        $x3 = $x2 + self::COL_WIDTHS_IN['axis_name'];
        $x4 = $x3 + self::COL_WIDTHS_IN['count'];

        foreach ($rows as $i => $row) {
            $top = self::TABLE_ROWS_TOP_IN + $i * $rowHeight;

            if ($i % 2 === 0) {
                $band = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
                $this->position($band, $x1, $top, self::CONTENT_WIDTH_IN, $rowHeight);
                $band->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::BAND));
                $band->getBorder()->setLineStyle(Border::LINE_NONE);
            }

            $subBox = $slide->createRichTextShape();
            $this->position($subBox, $x1, $top, self::COL_WIDTHS_IN['sub_name'], $rowHeight);
            $subBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $subText = $this->truncateForWidth($row['sub_name'], self::COL_WIDTHS_IN['sub_name'], 9.5, true);
            $this->font($subBox->getActiveParagraph()->createTextRun($subText), 9.5, true, self::NAVY);

            $axisBox = $slide->createRichTextShape();
            $this->position($axisBox, $x2, $top, self::COL_WIDTHS_IN['axis_name'], $rowHeight);
            $axisBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $axisText = $this->truncateForWidth($row['axis_name'], self::COL_WIDTHS_IN['axis_name'], 8.5, false);
            $this->font($axisBox->getActiveParagraph()->createTextRun($axisText), 8.5, false, self::MUTED);

            $countBox = $slide->createRichTextShape();
            $this->position($countBox, $x3, $top, self::COL_WIDTHS_IN['count'], $rowHeight);
            $countBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $this->font($countBox->getActiveParagraph()->createTextRun("{$row['matched_count']} / {$competitorCount}社"), 9.5, true, self::COPPER);

            $quoteBox = $slide->createRichTextShape();
            $this->position($quoteBox, $x4, $top, self::COL_WIDTHS_IN['quote'], $rowHeight);
            $quoteBox->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $quoteText = $this->truncateForWidth((string) $row['quote'], self::COL_WIDTHS_IN['quote'], 8.5, false);
            $this->font($quoteBox->getActiveParagraph()->createTextRun($quoteText), 8.5, false, self::BODY_TEXT);
        }
    }

    private function addTableHeader(Slide $slide): void
    {
        $band = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($band, self::LEFT_IN, self::TABLE_HEADER_TOP_IN, self::CONTENT_WIDTH_IN, self::TABLE_HEADER_HEIGHT_IN);
        $band->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.self::NAVY));
        $band->getBorder()->setLineStyle(Border::LINE_NONE);

        $labels = [
            ['項目', self::LEFT_IN, self::COL_WIDTHS_IN['sub_name']],
            ['領域', self::LEFT_IN + self::COL_WIDTHS_IN['sub_name'], self::COL_WIDTHS_IN['axis_name']],
            ['言及社数', self::LEFT_IN + self::COL_WIDTHS_IN['sub_name'] + self::COL_WIDTHS_IN['axis_name'], self::COL_WIDTHS_IN['count']],
            ['代表的な記述(引用)', self::LEFT_IN + self::COL_WIDTHS_IN['sub_name'] + self::COL_WIDTHS_IN['axis_name'] + self::COL_WIDTHS_IN['count'], self::COL_WIDTHS_IN['quote']],
        ];

        foreach ($labels as [$text, $left, $width]) {
            $box = $slide->createRichTextShape();
            $this->position($box, $left, self::TABLE_HEADER_TOP_IN, $width, self::TABLE_HEADER_HEIGHT_IN);
            $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $this->font($box->getActiveParagraph()->createTextRun($text), 9, true, self::WHITE);
        }
    }

    private function addFooter(Slide $slide, string $sourceNote, ?string $pageNumber): void
    {
        $source = $slide->createRichTextShape();
        $this->position($source, self::LEFT_IN, 6.35, self::CONTENT_WIDTH_IN, 0.3);
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
     * 依頼AT-1: 1行に収まる長さへの切り詰め(文の途中で切れる場合は末尾に…)。
     * dompdf版(admin-comparison-pdf.blade.php)のtruncateNameと同じ考え方だが、
     * pptxはHTMLのoverflow:hiddenのような自動クリップが無く自前で文字数を
     * 見積もる必要がある。Meiryoの日本語フルwidth文字は概ね1em幅のため、
     * 文字数 ≈ (列幅 - 左右余白) / フォントサイズ で近似する(実PowerPoint
     * 画像化で目視確認済み、依頼AT報告事項5)。
     */
    private function truncateForWidth(string $text, float $widthIn, float $sizePt, bool $bold): string
    {
        $insetPt = 9.0; // RichTextの既定lIns/rIns(左右合計)の近似値。
        // 実PowerPoint画像化で1.0倍(1文字=1em)では2行に折り返す事例が
        // 見つかったため、Meiryoの実測に合わせて安全側に大きくした係数。
        $charWidthPt = $sizePt * ($bold ? 1.28 : 1.18);
        $usableWidthPt = $widthIn * 72 - $insetPt;
        $maxChars = max(1, (int) floor($usableWidthPt / $charWidthPt));

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, max(0, $maxChars - 1)).'…';
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
