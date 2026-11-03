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
 * 依頼DK: 「私たちが理解している、{社名}という会社」(社内用・下書き)のスライド1枚。
 *
 * 上から: 赤い帯(下書き) → タイトル → 紺の帯(見出しの言葉と要約) → 柱のカード(3つまで。番号・柱の句・
 * 大切にしていること・実績・外からの評価) → (自社だけ)問いかけの1行 → 出典。
 * 中身は CompanyProfileVerifier を通ったものだけ(空の項目は「確認できませんでした」)。文言・色は config('company_profile')。
 */
class CompanyProfileSlideGenerator
{
    private const PX_PER_INCH = 96;

    private const SLIDE_WIDTH_IN = 13.333;

    private const LEFT_IN = 0.9;

    private const CONTENT_WIDTH_IN = 11.5;

    private const NAVY = '12243F';

    private const COPPER = 'C8763C';

    private const BODY_TEXT = '0C1726';

    private const MUTED = '5A6B82';

    private const DIM = '9AA6B4';

    private const WHITE = 'FFFFFF';

    private const BAND_TOP_IN = 1.45;

    private const CARDS_TOP_IN = 2.8;

    private const CARDS_BOTTOM_IN = 6.0;

    private const CARD_GAP_IN = 0.25;

    /**
     * @param  array{company_name: string, is_self: bool, profile: array<string, mixed>}  $page
     */
    public function generate(array $page): string
    {
        return $this->render(function (Slide $slide) use ($page): void {
            $profile = $page['profile'];
            $this->addDraftBand($slide);
            $this->addKicker($slide);
            $this->addTitle($slide, sprintf((string) config('company_profile.title'), $page['company_name']));
            $this->addHeadlineBand($slide, $profile, (bool) $page['is_self']);
            $this->addPillars($slide, array_values((array) ($profile['pillars'] ?? [])));
            if ((bool) $page['is_self']) {
                $this->addQuestion($slide);
            }
            $this->addFooter($slide, $this->sourceNote((array) ($profile['sources'] ?? [])));
        });
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function addHeadlineBand(Slide $slide, array $profile, bool $isSelf): void
    {
        $height = 1.18;
        $this->addRect($slide, self::LEFT_IN, self::BAND_TOP_IN, self::CONTENT_WIDTH_IN, $height, (string) config('company_profile.band_fill'));

        $innerLeft = self::LEFT_IN + 0.35;
        $innerWidth = self::CONTENT_WIDTH_IN - 0.7;
        $box = $slide->createRichTextShape();
        $this->position($box, $innerLeft, self::BAND_TOP_IN + 0.08, $innerWidth, $height - 0.16);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);

        $label = (string) config($isSelf ? 'company_profile.band_label_self' : 'company_profile.band_label_competitor');
        $this->font($box->getActiveParagraph()->createTextRun($label), 10, false, 'C9CED6');

        $headline = trim((string) ($profile['headline']['text'] ?? ''));
        $paragraph = $box->createParagraph();
        if ($headline === '') {
            $this->font($paragraph->createTextRun((string) config('company_profile.band_no_message')), 14, true, self::WHITE);
        } else {
            $size = 24;
            while ($size > 16 && mb_strwidth($headline, 'UTF-8') * $size * 0.62 > $innerWidth * 72) {
                $size--;
            }
            // 「」『』の中だけ色を変える(括弧そのものは白)。
            foreach (preg_split('/([「『][^」』]*[」』])/u', $headline, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (preg_match('/^([「『])(.*)([」』])$/u', $part, $m) === 1) {
                    $this->font($paragraph->createTextRun($m[1]), $size, true, self::WHITE);
                    $this->font($paragraph->createTextRun($m[2]), $size, true, (string) config('company_profile.band_highlight'));
                    $this->font($paragraph->createTextRun($m[3]), $size, true, self::WHITE);
                } else {
                    $this->font($paragraph->createTextRun($part), $size, true, self::WHITE);
                }
            }
        }

        $lead = trim((string) ($profile['lead'] ?? ''));
        if ($lead !== '') {
            $this->font($box->createParagraph()->createTextRun($lead), 11, false, 'DDE1E7');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $pillars
     */
    private function addPillars(Slide $slide, array $pillars): void
    {
        $count = count($pillars);
        if ($count === 0) {
            return;
        }
        $width = (self::CONTENT_WIDTH_IN - self::CARD_GAP_IN * ($count - 1)) / $count;
        $height = self::CARDS_BOTTOM_IN - self::CARDS_TOP_IN;
        $labels = (array) config('company_profile.labels');

        foreach ($pillars as $i => $pillar) {
            $left = self::LEFT_IN + $i * ($width + self::CARD_GAP_IN);
            $this->addRect($slide, $left, self::CARDS_TOP_IN, $width, $height, (string) config('company_profile.card_fill'));

            // 番号の丸と、柱の句。
            $circle = $slide->createAutoShape()->setType(AutoShape::TYPE_OVAL);
            $this->position($circle, $left + 0.2, self::CARDS_TOP_IN + 0.2, 0.42, 0.42);
            $circle->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.config('company_profile.accent')));
            $circle->getBorder()->setLineStyle(Border::LINE_NONE);
            $number = $slide->createRichTextShape();
            $this->position($number, $left + 0.2, self::CARDS_TOP_IN + 0.2, 0.42, 0.42);
            $number->setInsetLeft(0)->setInsetRight(0)->setInsetTop(0)->setInsetBottom(0);
            $number->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $number->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $this->font($number->getActiveParagraph()->createTextRun((string) ($i + 1)), 12, true, self::WHITE);

            $titleLeft = $left + 0.72;
            $titleWidth = $width - 0.85;
            $title = $slide->createRichTextShape();
            $this->position($title, $titleLeft, self::CARDS_TOP_IN + 0.12, $titleWidth, 0.72);
            $title->setWrap(RichText::WRAP_SQUARE);
            $title->setVerticalAlignCenter(RichText::VALIGN_CENTER);
            $titleSize = $this->fitSize((string) $pillar['title'], $titleWidth, 0.72, 14, 11, true);
            $this->font($title->getActiveParagraph()->createTextRun((string) $pillar['title']), $titleSize, true, self::BODY_TEXT);

            $innerLeft = $left + 0.15;
            $innerWidth = $width - 0.3;
            $this->addFact($slide, (string) ($labels['value'] ?? ''), $pillar['value'] ?? null, $innerLeft, self::CARDS_TOP_IN + 0.9, $innerWidth, 0.72, self::MUTED);
            $this->addFact($slide, (string) ($labels['achievement'] ?? ''), $pillar['achievement'] ?? null, $innerLeft, self::CARDS_TOP_IN + 1.64, $innerWidth, 0.72, self::MUTED);

            // 外からの評価は、白い箱の中に赤いラベル。
            $boxTop = self::CARDS_TOP_IN + 2.4;
            $this->addRect($slide, $left + 0.1, $boxTop, $width - 0.2, self::CARDS_BOTTOM_IN - 0.1 - $boxTop, self::WHITE);
            $this->addFact($slide, (string) ($labels['recognition'] ?? ''), $pillar['recognition'] ?? null, $innerLeft, $boxTop + 0.04, $innerWidth, self::CARDS_BOTTOM_IN - 0.18 - $boxTop, (string) config('company_profile.accent'));
        }
    }

    private function addFact(Slide $slide, string $label, mixed $fact, float $left, float $top, float $width, float $height, string $labelColor): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, $left, $top, $width, $height);
        $box->setWrap(RichText::WRAP_SQUARE);
        $this->font($box->getActiveParagraph()->createTextRun($label), 9, true, $labelColor);
        $text = is_array($fact) ? trim((string) ($fact['text'] ?? '')) : '';
        $paragraph = $box->createParagraph();
        if ($text === '') {
            $this->font($paragraph->createTextRun((string) config('company_profile.fact_missing')), 9, false, self::DIM);

            return;
        }
        $size = $this->fitSize($text, $width, $height - 0.22, 11, 9, false);
        $this->font($paragraph->createTextRun($text), $size, false, self::BODY_TEXT);
    }

    private function addQuestion(Slide $slide): void
    {
        $lead = trim((string) config('company_profile.question_lead'));
        $text = trim((string) config('company_profile.question_text'));
        if ($lead === '' && $text === '') {
            return;
        }
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, self::CARDS_BOTTOM_IN + 0.06, self::CONTENT_WIDTH_IN, 0.34);
        $box->setWrap(RichText::WRAP_SQUARE);
        $paragraph = $box->getActiveParagraph();
        if ($lead !== '') {
            $this->font($paragraph->createTextRun($lead.'　'), 12, true, (string) config('company_profile.accent'));
        }
        if ($text !== '') {
            $this->font($paragraph->createTextRun($text), 11, false, self::BODY_TEXT);
        }
    }

    /**
     * @param  list<array{url: string, title: string}>  $sources
     */
    private function sourceNote(array $sources): string
    {
        $names = [];
        foreach ($sources as $source) {
            $name = trim((string) ($source['title'] ?? ''));
            if ($name === '') {
                $name = (string) parse_url((string) $source['url'], PHP_URL_HOST);
            }
            if (mb_strlen($name) > 40) {
                $name = mb_substr($name, 0, 39).'…';
            }
            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        $max = (int) config('company_profile.source_max');
        $shown = array_slice($names, 0, $max);
        $note = (string) config('company_profile.source_prefix').implode((string) config('company_profile.source_separator'), $shown);
        if (count($names) > $max) {
            $note .= sprintf('　ほか%d件', count($names) - $max);
        }

        return $note;
    }

    private function addFooter(Slide $slide, string $note): void
    {
        $source = $slide->createRichTextShape();
        $this->position($source, self::LEFT_IN, 6.45, self::CONTENT_WIDTH_IN, 0.45);
        $source->setWrap(RichText::WRAP_SQUARE);
        $this->font($source->getActiveParagraph()->createTextRun($note), 8, false, self::MUTED);

        $logo = $slide->createRichTextShape();
        $this->position($logo, self::LEFT_IN, 6.98, 3.0, 0.3);
        $this->font($logo->getActiveParagraph()->createTextRun('LEGGENDA'), 9, true, self::MUTED);
    }

    private function addDraftBand(Slide $slide): void
    {
        $text = (string) config('admin_comparison_pptx.top_message_draft_band_text');
        if (trim($text) === '') {
            return;
        }
        $this->addRect($slide, 0, 0, self::SLIDE_WIDTH_IN, 0.42, (string) config('admin_comparison_pptx.top_message_draft_band_fill'));
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, 0, self::CONTENT_WIDTH_IN, 0.42);
        $box->setWrap(RichText::WRAP_SQUARE);
        $box->setVerticalAlignCenter(RichText::VALIGN_CENTER);
        $this->font($box->getActiveParagraph()->createTextRun($text), (int) config('admin_comparison_pptx.top_message_draft_band_pt'), true, (string) config('admin_comparison_pptx.top_message_draft_band_text_color'));
    }

    private function addKicker(Slide $slide): void
    {
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, 0.5, self::CONTENT_WIDTH_IN, 0.34);
        $this->font($box->getActiveParagraph()->createTextRun('EMPLOYER BRAND BENCHMARK'), 12, true, self::COPPER);
    }

    private function addTitle(Slide $slide, string $text): void
    {
        $size = 25;
        while ($size > 18 && mb_strwidth($text, 'UTF-8') * $size * 0.64 > self::CONTENT_WIDTH_IN * 72) {
            $size--;
        }
        $box = $slide->createRichTextShape();
        $this->position($box, self::LEFT_IN, 0.84, self::CONTENT_WIDTH_IN, 0.55);
        $this->font($box->getActiveParagraph()->createTextRun($text), $size, true, self::NAVY);
    }

    /** 箱に収まる文字の大きさ(大きい方から。行数は表示幅で見積もる)。 */
    private function fitSize(string $text, float $widthIn, float $heightIn, int $max, int $min, bool $bold): int
    {
        for ($size = $max; $size > $min; $size--) {
            $perLine = max(1, (int) floor(($widthIn * 72 - 9) / ($size * ($bold ? 0.64 : 0.6))));
            $lines = (int) ceil(mb_strwidth($text, 'UTF-8') / $perLine);
            if ($lines * $size * 1.3 / 72 <= $heightIn) {
                return $size;
            }
        }

        return $min;
    }

    private function addRect(Slide $slide, float $left, float $top, float $width, float $height, string $rgb): void
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

    /**
     * AdminComparisonPptxGenerator::renderSingleSlide() と同じ作り方(16:9、EMUで直接指定)。縦中央は anchor="ctr" に直す。
     */
    private function render(callable $build): string
    {
        $presentation = new PhpPresentation;
        $presentation->removeSlideByIndex(0);
        $layout = $presentation->getLayout();
        $layout->setDocumentLayout($layout::LAYOUT_CUSTOM);
        $layout->setCX(12192000, $layout::UNIT_EMU);
        $layout->setCY(6858000, $layout::UNIT_EMU);
        $slide = $presentation->createSlide();
        $build($slide);

        foreach ($slide->getShapeCollection() as $shape) {
            if ($shape instanceof RichText && $shape->getVerticalAlignCenter() === RichText::VALIGN_CENTER) {
                $shape->setVerticalAlignCenter(RichText::VALIGN_NOTCENTER);
                foreach ($shape->getParagraphs() as $paragraph) {
                    $paragraph->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                }
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pptx');
        (new PowerPoint2007($presentation))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }
}
