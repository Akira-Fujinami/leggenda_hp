<?php

namespace Tests\Unit\Support\Lead;

use App\Support\Lead\LeadMetricCatalog;
use Tests\TestCase;

class LeadMetricCatalogTest extends TestCase
{
    private function catalog(): LeadMetricCatalog
    {
        return new LeadMetricCatalog;
    }

    public function test_a_metric_not_in_any_perspective_is_hidden_by_default(): void
    {
        // 4観点に含めない指標の例(外部SEO・技術検出・構造化データ等)。
        foreach (['https', 'canonical_present', 'structured_data_present', 'ogp_present', 'robots_fetched', 'sitemap_fetched', 'cms_detected', 'ga_detected', 'authority_score', 'lighthouse_seo_score', 'lighthouse_best_practices', 'fcp', 'lcp', 'cls'] as $key) {
            $this->assertFalse($this->catalog()->isDisplayed($key), "key={$key} should be hidden");
            $this->assertNull($this->catalog()->perspectiveFor($key));
        }
    }

    public function test_recruit_link_present_belongs_to_the_completeness_perspective(): void
    {
        $this->assertSame(LeadMetricCatalog::PERSPECTIVE_COMPLETENESS, $this->catalog()->perspectiveFor('recruit_link_present'));
    }

    public function test_technical_metrics_get_a_plain_language_label(): void
    {
        $this->assertSame('検索結果に表示される紹介文', $this->catalog()->label('meta_description_present'));
        $this->assertSame('応募・問い合わせの受け口', $this->catalog()->label('form_present'));
    }

    public function test_the_lighthouse_accessibility_label_mentions_color_contrast_not_only_screen_readers(): void
    {
        $label = $this->catalog()->label('lighthouse_accessibility');

        $this->assertStringContainsString('コントラスト', $label);
        $this->assertStringContainsString('読み上げ', $label);
    }

    public function test_every_perspective_has_at_least_one_mapped_metric(): void
    {
        foreach (array_keys(LeadMetricCatalog::PERSPECTIVE_LABELS) as $perspective) {
            $this->assertNotEmpty($this->catalog()->keysForPerspective($perspective), "perspective={$perspective} should have at least one metric");
        }
    }

    public function test_an_unknown_key_falls_back_to_itself_as_the_label(): void
    {
        $this->assertSame('totally_unknown_key', $this->catalog()->label('totally_unknown_key'));
    }
}
