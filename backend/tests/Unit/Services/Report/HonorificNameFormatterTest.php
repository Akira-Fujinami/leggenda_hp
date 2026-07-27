<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\HonorificNameFormatter;
use Tests\TestCase;

class HonorificNameFormatterTest extends TestCase
{
    private function formatter(): HonorificNameFormatter
    {
        return new HonorificNameFormatter;
    }

    public function test_a_plain_company_name_gets_a_single_honorific_appended(): void
    {
        $this->assertSame('株式会社サンプル様', $this->formatter()->format('株式会社サンプル'));
    }

    public function test_a_trailing_gochu_is_stripped_before_appending_the_honorific(): void
    {
        $this->assertSame('株式会社○○様', $this->formatter()->format('株式会社○○御中'));
    }

    public function test_a_trailing_sama_is_stripped_before_appending_the_honorific(): void
    {
        $this->assertSame('○○様', $this->formatter()->format('○○様'));
    }

    public function test_a_trailing_dono_is_stripped_before_appending_the_honorific(): void
    {
        $this->assertSame('○○様', $this->formatter()->format('○○殿'));
    }

    public function test_whitespace_left_after_stripping_the_honorific_is_trimmed(): void
    {
        $this->assertSame('株式会社○○様', $this->formatter()->format('株式会社○○ 御中'));
    }

    public function test_an_extremely_long_company_name_is_truncated_with_an_ellipsis(): void
    {
        $longName = str_repeat('あ', 60);

        $result = $this->formatter()->format($longName);

        $this->assertSame(str_repeat('あ', 40).'…様', $result);
    }

    public function test_an_empty_company_name_falls_back_to_a_generic_greeting(): void
    {
        $this->assertSame('お客様', $this->formatter()->format('   '));
    }
}
