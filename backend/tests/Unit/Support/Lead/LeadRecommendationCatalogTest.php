<?php

namespace Tests\Unit\Support\Lead;

use App\Support\Lead\LeadMetricCatalog;
use App\Support\Lead\LeadRecommendationCatalog;
use Tests\TestCase;

/**
 * リード向け改善提案の許可リスト。2026-08-03の設計方針:
 * 1. 許可リストに無いキーは表示しない(除外リストではない)
 * 2. 許可リストに載っているキーは、必ずLeadMetricCatalog(4観点)にも
 *    表示されている指標であること(改善提案だけ独自の関心事を持ち込まない)
 * 3. title/descriptionのいずれにも、config('brand_wheel.forbidden_phrases')の
 *    語を一切含まないこと(社外に出る文章のため)
 */
class LeadRecommendationCatalogTest extends TestCase
{
    private function catalog(): LeadRecommendationCatalog
    {
        return new LeadRecommendationCatalog(new LeadMetricCatalog);
    }

    public function test_an_uncataloged_key_like_analytics_configured_is_not_allowed(): void
    {
        $catalog = $this->catalog();

        $this->assertFalse($catalog->isAllowed('analytics_configured'));
        $this->assertNull($catalog->groupKeyFor('analytics_configured'));
    }

    public function test_form_present_is_allowed_and_reuses_the_lead_metric_catalog_label(): void
    {
        $catalog = $this->catalog();
        $metricCatalog = new LeadMetricCatalog;

        $this->assertTrue($catalog->isAllowed('form_present'));
        $this->assertSame('form_present', $catalog->groupKeyFor('form_present'));
        $this->assertSame($metricCatalog->label('form_present'), $catalog->title('form_present'));
    }

    public function test_the_four_performance_sub_metrics_collapse_into_a_single_group_key(): void
    {
        $catalog = $this->catalog();

        foreach (['fcp', 'lcp', 'cls', 'tbt'] as $key) {
            $this->assertSame(LeadRecommendationCatalog::PERFORMANCE_GROUP_KEY, $catalog->groupKeyFor($key));
        }
    }

    public function test_the_performance_group_title_matches_the_lighthouse_performance_perspective_label(): void
    {
        $catalog = $this->catalog();
        $metricCatalog = new LeadMetricCatalog;

        $this->assertSame(
            $metricCatalog->label('lighthouse_performance'),
            $catalog->title(LeadRecommendationCatalog::PERFORMANCE_GROUP_KEY),
        );
    }

    /**
     * 許可リストの各キー(表示速度グループを除く)は、必ずLeadMetricCatalog
     * (4観点)にも登録されていること。改善提案だけが4観点に無い独自の
     * 関心事を持ち込むと、画面の一貫性が崩れる。
     */
    public function test_every_allowed_key_except_the_performance_group_is_in_the_lead_metric_catalog(): void
    {
        $catalog = $this->catalog();
        $metricCatalog = new LeadMetricCatalog;

        foreach ($catalog->allowedGroupKeys() as $key) {
            if ($key === LeadRecommendationCatalog::PERFORMANCE_GROUP_KEY) {
                continue;
            }

            $this->assertTrue($metricCatalog->isDisplayed($key), "key={$key} should be in LeadMetricCatalog");
        }
    }

    /**
     * 社外に出る文章のため、ブランド・ホイールと同じ否定的評価語を
     * 一切含まないこと(2026-08-03のユーザー指摘)。
     */
    public function test_no_title_or_description_contains_a_forbidden_phrase(): void
    {
        $catalog = $this->catalog();
        $forbiddenPhrases = (array) config('brand_wheel.forbidden_phrases');

        $this->assertNotEmpty($forbiddenPhrases, 'config(brand_wheel.forbidden_phrases) should not be empty in this test environment');

        foreach ($catalog->allowedGroupKeys() as $key) {
            $title = $catalog->title($key);
            $description = $catalog->description($key);

            foreach ($forbiddenPhrases as $phrase) {
                $this->assertStringNotContainsString($phrase, $title, "title for key={$key} contains forbidden phrase '{$phrase}'");
                $this->assertStringNotContainsString($phrase, $description, "description for key={$key} contains forbidden phrase '{$phrase}'");
            }
        }
    }

    public function test_every_allowed_key_has_a_non_empty_title_and_description(): void
    {
        $catalog = $this->catalog();

        foreach ($catalog->allowedGroupKeys() as $key) {
            $this->assertNotSame('', trim($catalog->title($key)), "title for key={$key} should not be empty");
            $this->assertNotSame('', trim($catalog->description($key)), "description for key={$key} should not be empty");
        }
    }
}
