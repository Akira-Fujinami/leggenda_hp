<?php

namespace Tests\Feature\Report;

use App\Services\Report\PlaceholderSlideGenerator;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼DN: 「足りないもの」を差し替え用の枠(目印のスライド)にする。
 */
class MissingItemsPlaceholderTest extends TestCase
{
    public function test_the_placeholder_is_on_by_default_and_can_be_turned_off(): void
    {
        $this->assertStringContainsString("env('MISSING_ITEMS_PLACEHOLDER_ENABLED', true)", (string) file_get_contents(base_path('config/admin_comparison_pptx.php')));
        $this->assertTrue((bool) config('admin_comparison_pptx.missing_items_placeholder.enabled'));
    }

    public function test_the_placeholder_slide_has_the_band_the_title_and_the_message_and_no_content(): void
    {
        $config = (array) config('admin_comparison_pptx.missing_items_placeholder');
        $bytes = app(PlaceholderSlideGenerator::class)->generate($config);

        $tmp = tempnam(sys_get_temp_dir(), 'ph');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($tmp);

        foreach (['band', 'title', 'text', 'note'] as $key) {
            $this->assertStringContainsString((string) $config[$key], $xml, $key);
        }
        $this->assertStringContainsString('prstDash val="dash"', $xml, '点線の枠');
        $this->assertStringNotContainsString('OTOGI', $xml, '中身(求職者調査の出典など)は作らない');
    }
}
