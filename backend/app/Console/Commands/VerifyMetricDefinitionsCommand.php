<?php

namespace App\Console\Commands;

use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 読み取り専用。metric_definitions/category_definitionsが、Seederが本来
 * 登録するはずの内容と一致しているかを検証する。
 *
 * #A-2: 「シーダーを再実行する」という手順は実行し忘れを検知できない
 * (2026-07-20の採点マスタ0件、2026-08-01の採用ページ指標未シードで2度発生)。
 * このコマンドはDBへ一切書き込まず、デプロイ直後・トラブルシュート時に
 * 何度でも安全に実行できる形で「シーダーとDBの実際の状態が一致しているか」
 * を能動的に確認する。
 */
#[Signature('analysis:verify-metric-definitions')]
#[Description('metric_definitions/category_definitionsがSeederの内容と一致しているかを読み取り専用で検証する')]
class VerifyMetricDefinitionsCommand extends Command
{
    public function handle(): int
    {
        $hasProblem = false;

        $hasProblem = $this->verifyCategories() || $hasProblem;
        $hasProblem = $this->verifyMetricDefinitions() || $hasProblem;

        if ($hasProblem) {
            $this->newLine();
            $this->error('metric_definitions/category_definitionsに不整合があります。上記の内容を確認し、該当Seederをクラス指定で再実行してください。');

            return self::FAILURE;
        }

        $this->info('metric_definitions/category_definitionsはSeederの内容と一致しています。');

        return self::SUCCESS;
    }

    /**
     * category_definitionsの検証: (1) Seederが定義する各カテゴリが有効な状態で
     * 存在するか、(2) 有効なカテゴリのweight合計が100かどうか。
     * (2)はCategoryDefinitionSeeder::run()自身も投入時に検証しているが、
     * それはSeederを実際に実行した瞬間にしか働かない。DBが後から直接
     * 書き換えられた場合や、Seeder実行そのものが漏れた場合はここでしか
     * 検知できない。
     */
    private function verifyCategories(): bool
    {
        $expected = (new CategoryDefinitionSeeder)->expectedCategories();
        $expectedKeys = array_column($expected, 'key');

        $existing = CategoryDefinition::query()->get(['key', 'is_active', 'weight'])->keyBy('key');

        $hasProblem = false;

        $missing = [];
        $inactive = [];
        foreach ($expectedKeys as $key) {
            $row = $existing->get($key);

            if ($row === null) {
                $missing[] = $key;
            } elseif (! $row->is_active) {
                $inactive[] = $key;
            }
        }

        if ($missing !== []) {
            $hasProblem = true;
            $this->error('category_definitionsに存在しないカテゴリキー: '.implode(', ', $missing));
        }

        if ($inactive !== []) {
            $hasProblem = true;
            $this->error('category_definitionsで無効(is_active=false)になっているカテゴリキー: '.implode(', ', $inactive));
        }

        $activeWeightSum = CategoryDefinition::query()->where('is_active', true)->sum('weight');

        if (abs($activeWeightSum - 100.0) > 0.01) {
            $hasProblem = true;
            $this->error("有効なCategoryDefinitionのweight合計が100ではありません(現在: {$activeWeightSum})。");
        }

        return $hasProblem;
    }

    /**
     * metric_definitionsの検証: Seederが登録するはずの各keyが、有効な状態で
     * 存在するか。存在しない/無効なキーへの書き込みはrecordMetric()が
     * 無言でスキップする(#A-2でLog::errorを追加済みだが、それはジョブ実行時
     * にしか発火しない受動的な検知であり、このコマンドは能動的にいつでも
     * 確認できる手段として併用する)。
     */
    private function verifyMetricDefinitions(): bool
    {
        $expected = (new MetricDefinitionSeeder)->expectedDefinitions();

        $existing = MetricDefinition::query()->get(['key', 'is_active'])->keyBy('key');

        $missing = [];
        $inactive = [];

        foreach ($expected as $definition) {
            $row = $existing->get($definition['key']);

            if ($row === null) {
                $missing[] = $definition['key'];
            } elseif (! $row->is_active) {
                $inactive[] = $definition['key'];
            }
        }

        $hasProblem = false;

        if ($missing !== []) {
            $hasProblem = true;
            $this->error('metric_definitionsに存在しない指標キー('.count($missing).'件):');
            $this->listWithBullets($missing);
        }

        if ($inactive !== []) {
            $hasProblem = true;
            $this->error('metric_definitionsで無効(is_active=false)になっている指標キー('.count($inactive).'件):');
            $this->listWithBullets($inactive);
        }

        return $hasProblem;
    }

    /**
     * @param  list<string>  $keys
     */
    private function listWithBullets(array $keys): void
    {
        foreach ($keys as $key) {
            $this->line("  - {$key}");
        }
    }
}
