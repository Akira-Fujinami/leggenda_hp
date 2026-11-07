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
 * 依頼DN: 差し替え用の枠(目印のスライド)。システムは中身を作らず、あとで担当者が資料のスライドを貼り込む場所を示すだけ。
 * 上に赤い帯、タイトル、点線の枠と、枠の中の案内の文。文言は config('admin_comparison_pptx.missing_items_placeholder')。
 */
class PlaceholderSlideGenerator
{
    private const PX_PER_INCH = 96;

    private const LEFT_IN = 0.9;

    private const CONTENT_WIDTH_IN = 11.5;

    /**
     * @param  array{band: string, title: string, text: string, note?: string}  $texts
     */
    public function generate(array $texts): string
    {
        $presentation = new PhpPresentation;
        $presentation->removeSlideByIndex(0);
        $layout = $presentation->getLayout();
        $layout->setDocumentLayout($layout::LAYOUT_CUSTOM);
        $layout->setCX(12192000, $layout::UNIT_EMU);
        $layout->setCY(6858000, $layout::UNIT_EMU);
        $slide = $presentation->createSlide();

        if (trim($texts['band']) !== '') {
            $this->rect($slide, 0, 0, 13.333, 0.42, (string) config('admin_comparison_pptx.top_message_draft_band_fill'));
            $band = $this->text($slide, self::LEFT_IN, 0, self::CONTENT_WIDTH_IN, 0.42);
            $band->getActiveParagraph()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $this->font($band->getActiveParagraph()->createTextRun($texts['band']), (int) config('admin_comparison_pptx.top_message_draft_band_pt'), true, (string) config('admin_comparison_pptx.top_message_draft_band_text_color'));
        }

        $kicker = $this->text($slide, self::LEFT_IN, 0.5, self::CONTENT_WIDTH_IN, 0.34);
        $this->font($kicker->getActiveParagraph()->createTextRun('EMPLOYER BRAND BENCHMARK'), 12, true, 'C8763C');
        $title = $this->text($slide, self::LEFT_IN, 0.86, self::CONTENT_WIDTH_IN, 0.55);
        $this->font($title->getActiveParagraph()->createTextRun($texts['title']), 25, true, '12243F');

        // 点線の枠と、中央の案内の文。
        $this->rect($slide, self::LEFT_IN, 1.7, self::CONTENT_WIDTH_IN, 4.6, 'F7F8FA');
        // 枠線は線を4本引く(図形の枠線の点線は、書き出しで落ちるため。AdminComparisonPptxGenerator::drawRectOutline() と同じ)。
        $l = self::LEFT_IN;
        $t = 1.7;
        $r = self::LEFT_IN + self::CONTENT_WIDTH_IN;
        $b = 1.7 + 4.6;
        foreach ([[$l, $t, $r, $t], [$r, $t, $r, $b], [$r, $b, $l, $b], [$l, $b, $l, $t]] as [$x1, $y1, $x2, $y2]) {
            $line = $slide->createLineShape((int) round($x1 * self::PX_PER_INCH), (int) round($y1 * self::PX_PER_INCH), (int) round($x2 * self::PX_PER_INCH), (int) round($y2 * self::PX_PER_INCH));
            $line->getBorder()->setLineWidth(2)->setColor(new Color('FF9AA6B4'))->setDashStyle(Border::DASH_DASH);
        }

        $message = $this->text($slide, self::LEFT_IN + 0.5, 1.7, self::CONTENT_WIDTH_IN - 1.0, 4.6);
        $paragraph = $message->getActiveParagraph();
        $paragraph->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $this->font($paragraph->createTextRun($texts['text']), 16, true, '5A6B82');
        if (trim((string) ($texts['note'] ?? '')) !== '') {
            $note = $message->createParagraph();
            $note->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $this->font($note->createTextRun((string) $texts['note']), 11, false, '9AA6B4');
        }

        $logo = $this->text($slide, self::LEFT_IN, 6.98, 3.0, 0.3);
        $this->font($logo->getActiveParagraph()->createTextRun('LEGGENDA'), 9, true, '5A6B82');

        $tmp = tempnam(sys_get_temp_dir(), 'pptx');
        (new PowerPoint2007($presentation))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    private function text(Slide $slide, float $left, float $top, float $width, float $height): RichText
    {
        $box = $slide->createRichTextShape();
        $this->position($box, $left, $top, $width, $height);
        $box->setWrap(RichText::WRAP_SQUARE);

        return $box;
    }

    private function rect(Slide $slide, float $left, float $top, float $width, float $height, string $rgb): void
    {
        $rect = $slide->createAutoShape()->setType(AutoShape::TYPE_RECTANGLE);
        $this->position($rect, $left, $top, $width, $height);
        $rect->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$rgb));
        $rect->getBorder()->setLineStyle(Border::LINE_NONE);
    }

    private function position($shape, float $leftIn, float $topIn, float $widthIn, float $heightIn): void
    {
        $shape->setOffsetX((int) round($leftIn * self::PX_PER_INCH));
        $shape->setOffsetY((int) round($topIn * self::PX_PER_INCH));
        $shape->setWidth((int) round($widthIn * self::PX_PER_INCH));
        $shape->setHeight((int) round($heightIn * self::PX_PER_INCH));
    }

    private function font(RichText\Run $run, float $sizePt, bool $bold, string $rgb): void
    {
        $run->getFont()->setName('Meiryo')->setSize((int) round($sizePt))->setBold($bold)->setColor(new Color('FF'.$rgb));
    }
}
