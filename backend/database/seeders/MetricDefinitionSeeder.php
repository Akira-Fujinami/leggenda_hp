<?php

namespace Database\Seeders;

use App\Models\MetricDefinition;
use Illuminate\Database\Seeder;

class MetricDefinitionSeeder extends Seeder
{
    /**
     * 基本スコアの配点マスタ。カテゴリ合計は technical_seo=35 / content=20 /
     * performance=35 / technology=10 の仮配点 (spec section 21) に一致する。
     * lighthouse_seo / lighthouse_accessibility は参考値のため max_score=0
     * (スコア計算には加算されず、結果画面には表示される)。
     */
    public function run(): void
    {
        $definitions = [
            ['key' => 'title_present', 'category' => 'technical_seo', 'name' => 'titleタグ', 'value_type' => 'boolean', 'source_type' => 'static_html', 'max_score' => 8, 'display_order' => 10],
            ['key' => 'meta_description_present', 'category' => 'technical_seo', 'name' => 'meta description', 'value_type' => 'boolean', 'source_type' => 'static_html', 'max_score' => 6, 'display_order' => 20],
            ['key' => 'h1_single', 'category' => 'technical_seo', 'name' => 'H1タグ (1件)', 'value_type' => 'boolean', 'source_type' => 'static_html', 'max_score' => 6, 'display_order' => 30],
            ['key' => 'canonical_present', 'category' => 'technical_seo', 'name' => 'canonicalタグ', 'value_type' => 'boolean', 'source_type' => 'static_html', 'max_score' => 5, 'display_order' => 40],
            ['key' => 'https', 'category' => 'technical_seo', 'name' => 'HTTPS化', 'value_type' => 'boolean', 'source_type' => 'http', 'max_score' => 5, 'display_order' => 50],
            ['key' => 'robots_fetched', 'category' => 'technical_seo', 'name' => 'robots.txt取得', 'value_type' => 'boolean', 'source_type' => 'http', 'max_score' => 3, 'display_order' => 60],
            ['key' => 'sitemap_fetched', 'category' => 'technical_seo', 'name' => 'sitemap.xml取得', 'value_type' => 'boolean', 'source_type' => 'http', 'max_score' => 2, 'display_order' => 70],
            ['key' => 'og_and_structured_data_present', 'category' => 'technical_seo', 'name' => 'OGP/構造化データ (参考値)', 'value_type' => 'boolean', 'source_type' => 'static_html', 'max_score' => 0, 'display_order' => 75],

            ['key' => 'viewport_present', 'category' => 'content', 'name' => 'viewport meta', 'value_type' => 'boolean', 'source_type' => 'static_html', 'max_score' => 5, 'display_order' => 80],
            ['key' => 'img_alt_coverage', 'category' => 'content', 'name' => '画像alt充足率', 'value_type' => 'percentage', 'unit' => '%', 'source_type' => 'static_html', 'max_score' => 10, 'display_order' => 90],
            ['key' => 'word_count_sufficient', 'category' => 'content', 'name' => '本文の文字数', 'value_type' => 'boolean', 'source_type' => 'static_html', 'max_score' => 5, 'display_order' => 100],

            ['key' => 'lighthouse_performance', 'category' => 'performance', 'name' => 'Lighthouse Performance', 'value_type' => 'score', 'unit' => 'pt', 'source_type' => 'lighthouse', 'max_score' => 35, 'display_order' => 110],
            ['key' => 'lighthouse_seo', 'category' => 'performance', 'name' => 'Lighthouse SEO (参考値)', 'value_type' => 'score', 'unit' => 'pt', 'source_type' => 'lighthouse', 'max_score' => 0, 'display_order' => 120],
            ['key' => 'lighthouse_accessibility', 'category' => 'performance', 'name' => 'Lighthouse Accessibility (参考値)', 'value_type' => 'score', 'unit' => 'pt', 'source_type' => 'lighthouse', 'max_score' => 0, 'display_order' => 130],

            ['key' => 'technology_detected', 'category' => 'technology', 'name' => '使用技術の検出', 'value_type' => 'boolean', 'source_type' => 'technology', 'max_score' => 10, 'display_order' => 140],
        ];

        foreach ($definitions as $definition) {
            MetricDefinition::query()->updateOrCreate(
                ['key' => $definition['key']],
                $definition + ['description' => null, 'unit' => null, 'is_active' => true],
            );
        }
    }
}
