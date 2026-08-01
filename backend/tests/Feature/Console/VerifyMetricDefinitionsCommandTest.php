<?php

namespace Tests\Feature\Console;

use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * analysis:verify-metric-definitions は読み取り専用。#A-2:
 * 「シーダーを再実行する」という手順だけでは実行し忘れを検知できない
 * (2026-07-20の採点マスタ0件、2026-08-01の採用ページ指標未シードで2度発生)ため、
 * デプロイ後にDBの実際の状態がSeeder内容と一致しているかを能動的に確認する。
 */
class VerifyMetricDefinitionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_when_both_seeders_have_been_run(): void
    {
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);

        $this->artisan('analysis:verify-metric-definitions')->assertExitCode(0);
    }

    public function test_fails_when_a_metric_definition_is_entirely_missing(): void
    {
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);

        MetricDefinition::query()->where('key', 'recruit_title_present')->delete();

        $this->artisan('analysis:verify-metric-definitions')->assertExitCode(1);
    }

    public function test_fails_when_a_metric_definition_is_inactive(): void
    {
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);

        MetricDefinition::query()->where('key', 'recruit_title_present')->update(['is_active' => false]);

        $this->artisan('analysis:verify-metric-definitions')->assertExitCode(1);
    }

    public function test_fails_when_a_category_definition_is_missing(): void
    {
        // category_definitions.keyはmetric_definitions.category_keyから
        // restrictOnDelete()で参照されているため、MetricDefinitionSeederを
        // 実行済みの状態では該当カテゴリを削除できない。ここではカテゴリのみ
        // 投入した状態(=まだ指標は1件も紐付いていない)で削除して検証する。
        $this->seed(CategoryDefinitionSeeder::class);

        CategoryDefinition::query()->where('key', 'content')->delete();

        $this->artisan('analysis:verify-metric-definitions')->assertExitCode(1);
    }

    public function test_fails_when_the_active_category_weight_sum_is_not_100(): void
    {
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);

        CategoryDefinition::query()->where('key', 'content')->update(['is_active' => false]);

        $this->artisan('analysis:verify-metric-definitions')->assertExitCode(1);
    }

    public function test_fails_before_any_seeder_has_ever_run(): void
    {
        $this->artisan('analysis:verify-metric-definitions')->assertExitCode(1);
    }
}
