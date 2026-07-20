<?php

namespace Database\Seeders;

use App\Models\CategoryDefinition;
use Illuminate\Database\Seeder;

/**
 * カテゴリ配点のマスタ。合計100点。
 * 配点変更はこのSeeder(またはCategoryDefinitionテーブル)を更新するだけでよく、
 * コード内の採点ロジックには一切ハードコードしない。
 */
class CategoryDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['key' => 'technical_seo', 'name' => '技術SEO', 'weight' => 20, 'display_order' => 10],
            ['key' => 'content', 'name' => 'コンテンツ', 'weight' => 15, 'display_order' => 20],
            ['key' => 'performance', 'name' => '表示速度', 'weight' => 15, 'display_order' => 30],
            ['key' => 'accessibility', 'name' => 'アクセシビリティ', 'weight' => 10, 'display_order' => 40],
            ['key' => 'technology', 'name' => '技術・計測環境', 'weight' => 10, 'display_order' => 50],
            ['key' => 'conversion', 'name' => '集客・コンバージョン', 'weight' => 15, 'display_order' => 60],
            ['key' => 'authority', 'name' => '外部SEO・ドメイン評価', 'weight' => 15, 'display_order' => 70],
        ];

        foreach ($categories as $category) {
            CategoryDefinition::query()->updateOrCreate(
                ['key' => $category['key']],
                $category + ['description' => null, 'is_active' => true],
            );
        }

        $activeWeightSum = CategoryDefinition::query()->where('is_active', true)->sum('weight');

        if (abs($activeWeightSum - 100.0) > 0.01) {
            throw new \RuntimeException(
                "有効なCategoryDefinitionのweight合計が100ではありません(現在: {$activeWeightSum})。".
                'category_definitionsテーブルの設定を確認してください。',
            );
        }
    }
}
