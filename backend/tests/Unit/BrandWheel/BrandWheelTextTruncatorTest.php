<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelTextTruncator;
use Tests\TestCase;

class BrandWheelTextTruncatorTest extends TestCase
{
    public function test_returns_the_text_unchanged_when_within_the_limit(): void
    {
        $this->assertSame('短い文章です。', BrandWheelTextTruncator::truncateAtSentenceBoundary('短い文章です。', 20));
    }

    public function test_truncates_at_the_last_sentence_boundary_within_the_limit(): void
    {
        $first = str_repeat('あ', 10).'。';
        $second = str_repeat('い', 20).'。';

        $result = BrandWheelTextTruncator::truncateAtSentenceBoundary($first.$second, 20);

        $this->assertSame($first, $result);
        $this->assertStringEndsWith('。', $result);
        $this->assertStringEndsNotWith('…', $result);
    }

    public function test_appends_an_ellipsis_when_no_sentence_boundary_exists_within_the_limit(): void
    {
        $text = str_repeat('あ', 50);

        $result = BrandWheelTextTruncator::truncateAtSentenceBoundary($text, 20);

        $this->assertSame(str_repeat('あ', 20).'…', $result);
    }
}
