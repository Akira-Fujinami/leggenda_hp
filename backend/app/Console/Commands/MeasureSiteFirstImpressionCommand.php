<?php

namespace App\Console\Commands;

use App\Models\WebsiteAnalysis;
use App\Services\SiteFirstImpression\SiteFirstImpressionMaterialBuilder;
use App\Services\SiteFirstImpression\SiteFirstImpressionService;
use App\Services\SiteFirstImpression\SiteFirstImpressionVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 依頼CX: コーポレートTOP・採用TOPの第一印象(AIの所見)を、保存済みの巡回結果(依頼CRのコーポレートTOPを含む)で測る。
 * 自社は「ラベル:website_analysis_id」で指定する(複数可。ラベルは表示用)。
 *
 * AIを呼ぶのは --live のときだけで、合計(会社の数 + --repeat なら1回)が --max-ai-calls(既定0=呼ばない)を超える見込みなら、
 * 1回も呼ばずに止まる。--images は画面の画像を撮る(既存の描画の仕組み analyzer を使う。AIは使わない。撮れなければ画像なし)。
 * 結果のファイルは作らない(測定専用)。出力は、構造の要素数・採用への導線・各点(title・body・evidence)・捨てた点と理由の符号・
 * 1回あたりの入力/出力トークン。
 *
 * 開発・検証専用。本番で実行されうる経路には登録しない。
 */
#[Signature('site-first-impression:measure
    {--wa= : 自社。ラベル:website_analysis_id をカンマ区切り (例 サイボウズ:776,カヤック:780)}
    {--images : 画面の画像を撮る(analyzerを使う。既定は撮らず、構造だけ)}
    {--live : AIを呼ぶ(既定は呼ばず、構造と見込み回数だけ出す)}
    {--repeat : --live のとき、最初の会社だけ、同じ材料でもう1回呼んで、1回目との一致を出す}
    {--max-ai-calls=0 : AIを呼んでよい回数の上限(既定0=呼ばない。超える見込みなら1回も呼ばず、実行中も達したら止まる)}
')]
#[Description('非本番・開発専用: コーポレートTOP・採用TOPの第一印象(AIの所見)を保存済みの巡回結果で測る(依頼CX)')]
class MeasureSiteFirstImpressionCommand extends Command
{
    public function handle(
        SiteFirstImpressionMaterialBuilder $builder,
        SiteFirstImpressionService $service,
        SiteFirstImpressionVerifier $verifier,
    ): int {
        if (app()->environment('production')) {
            $this->error('production環境ではこのコマンドを実行できません。');

            return self::FAILURE;
        }

        $targets = [];
        foreach (array_filter(explode(',', (string) $this->option('wa'))) as $pair) {
            [$label, $id] = array_pad(explode(':', trim($pair), 2), 2, null);
            if ($label === null || $label === '' || $id === null || ! ctype_digit($id)) {
                $this->error("--wa は ラベル:id の形で指定してください: {$pair}");

                return self::FAILURE;
            }
            $targets[] = [$label, WebsiteAnalysis::query()->with('website')->findOrFail((int) $id)];
        }
        if ($targets === []) {
            $this->error('--wa を指定してください。');

            return self::FAILURE;
        }

        // 材料(構造)。通信しない・AIを使わない。
        $materials = [];
        foreach ($targets as [$label, $wa]) {
            $material = $builder->build($wa);
            $materials[$label] = $material;
            $this->info("=== {$label} (website_analysis_id={$wa->id}) ===");
            if ($material['status'] !== 'ok') {
                $this->warn('材料を作れませんでした: '.$material['reason'].'(この場合、AIは呼ばない)');

                continue;
            }
            foreach ($material['pages'] as $page) {
                $c = $page['structure']['counts'];
                $link = $page['structure']['recruit_link'];
                $this->line(sprintf(
                    '  %s: グローバルナビ %d件 / メインビジュアル %d件 / セクション %d件(項目 %d件) / フッター %s%s',
                    $page['label'],
                    $c['nav'],
                    $c['hero'],
                    $c['sections'],
                    $c['items'],
                    $page['structure']['footer'] === null ? 'なし' : 'あり',
                    is_array($link) ? ' / 採用への導線: '.$link['where'].($link['id'] !== null ? "({$link['id']})" : '') : '',
                ));
            }
            if (! isset($material['pages']['corporate'])) {
                $this->line('  (コーポレートTOPは決まらなかったため、採用TOPだけ)');
            }
        }

        $live = (bool) $this->option('live');
        $okCount = count(array_filter($materials, fn (array $m) => $m['status'] === 'ok'));
        $expected = $live ? $okCount + ($this->option('repeat') ? 1 : 0) : 0;
        $max = max(0, (int) $this->option('max-ai-calls'));
        $this->info("AI呼び出しの見込み: {$expected}回(上限 {$max}回)");
        if ($expected > $max) {
            $this->error("AI呼び出しが上限(--max-ai-calls={$max}回)を超える見込み({$expected}回)のため、1回も呼ばずに止めます。");

            return self::FAILURE;
        }
        if (! $live) {
            // AIは呼ばない。--images のときだけ、画面の画像を撮れるかを確かめる(analyzerを使う)。
            if ($this->option('images')) {
                foreach ($targets as [$label, $wa]) {
                    if ($materials[$label]['status'] !== 'ok') {
                        continue;
                    }
                    $prepared = $service->prepare($wa);
                    foreach ($prepared['image_info'] as $key => $info) {
                        $this->line(sprintf('  画像 %s %s: 最初に見える範囲 %s / ページ全体 %s%s', $label, $key, $info['first_view'] ? '撮れた' : '撮れず', $info['full_page'] ? '撮れた' : '撮れず', $info['error'] ? "({$info['error']})" : ''));
                    }
                }
            }

            return self::SUCCESS;
        }

        // 開発環境の既定はmock。測定は実際のAIで行う。
        config(['services.brand_wheel_ai.provider' => 'openai']);
        if (! (bool) $this->option('images')) {
            config(['site_first_impression.images_enabled' => false]);
        }

        $calls = 0;
        $first = true;
        foreach ($targets as [$label, $wa]) {
            if ($materials[$label]['status'] !== 'ok') {
                continue;
            }
            $this->info("=== {$label}: 第一印象(AI) ===");
            $prepared = $service->prepare($wa);
            foreach ($prepared['image_info'] as $key => $info) {
                $this->line(sprintf('  画像 %s: 最初に見える範囲 %s / ページ全体 %s%s', $key, $info['first_view'] ? '撮れた' : '撮れず', $info['full_page'] ? '撮れた' : '撮れず', $info['error'] ? "({$info['error']})" : ''));
            }
            $this->line('  AIに渡す画像: '.count($prepared['images']).'枚');

            $rounds = $first && $this->option('repeat') ? 2 : 1;
            $outputs = [];
            for ($i = 1; $i <= $rounds; $i++) {
                if ($calls + 1 > $max) {
                    $this->error('AI呼び出しが上限に達するため、止めます。');

                    return self::FAILURE;
                }
                $calls++;
                $result = $service->compose($prepared['material'], $prepared['images'], $prepared['image_info']);
                $outputs[$i] = $result;
                $this->printResult($result, $verifier, $prepared['material'], $rounds > 1 ? "{$i}回目" : '');
            }
            if ($rounds === 2) {
                $this->info('--- 1回目と2回目の違い ---');
                foreach (['good' => '良い点', 'bad' => '悪い点'] as $side => $name) {
                    $a = array_map(fn (array $p) => $p['title'], (array) ($outputs[1]['candidates'][$side] ?? []));
                    $b = array_map(fn (array $p) => $p['title'], (array) ($outputs[2]['candidates'][$side] ?? []));
                    $this->line(sprintf('  %s: 件数 %d / %d、見出しが同じ %d件', $name, count($a), count($b), count(array_intersect($a, $b))));
                }
            }
            $first = false;
        }

        $this->info("AI呼び出し 合計: {$calls}回");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $material
     */
    private function printResult(array $result, SiteFirstImpressionVerifier $verifier, array $material, string $title): void
    {
        if ($result['skipped'] !== null && $result['skipped'] !== 'not_enough_points') {
            $this->warn('書けませんでした: '.$result['skipped']);
        }
        foreach (['good' => '良いイメージ', 'bad' => '悪いイメージ'] as $side => $name) {
            $this->line("  {$name} {$title}:");
            foreach ((array) ($result['candidates'][$side] ?? []) as $point) {
                $checked = $verifier->verify(is_array($point) ? $point : [], $material);
                $this->line(sprintf('    [%s] %s', $checked['ok'] ? '採用' : '捨てた: '.implode('、', $checked['reasons']), (string) ($point['title'] ?? '')));
                $this->line('      '.(string) ($point['body'] ?? ''));
                foreach ((array) ($point['evidence'] ?? []) as $row) {
                    $this->line('      根拠 '.($row['id'] ?? '').': '.($row['text'] ?? ''));
                }
            }
        }
        $this->line(sprintf('  採用 良い%d件・悪い%d件 / 捨てた理由 %s / ページ: %s', $result['kept']['good'], $result['kept']['bad'], json_encode($result['discarded'], JSON_UNESCAPED_UNICODE), $result['page'] !== null ? '作る' : '作らない('.$result['skipped'].')'));
        $this->line(sprintf('  トークン 入力%d / 出力%d(画像 %d枚を含む) model=%s prompt=%s', (int) $result['usage_input_tokens'], (int) $result['usage_output_tokens'], (int) $result['image_count'], (string) $result['model'], (string) $result['prompt_version']));
    }
}
