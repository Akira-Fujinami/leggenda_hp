<?php

namespace App\Console\Commands;

use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use App\Services\ComparisonCommentary\ComparisonCommentaryMaterialBuilder;
use App\Services\Report\ComparisonStrengthWeaknessComposer;
use App\Services\SiteFirstImpression\SiteFirstImpressionMaterialBuilder;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightMaterialBuilder;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 依頼DB: 強み・弱みの下書き(Claudeが根拠つきで書く)を、保存済みの巡回結果とブランド・ホイールの判定結果で測る。
 * 自社と競合は「ラベル:website_analysis_id」で指定する(ラベルが企業名として材料・文に出る)。
 *
 * AIを呼ぶのは --live のときだけ(1組1回。--repeat なら、同じ材料でもう1回)。呼ぶ回数が --max-ai-calls(既定0=呼ばない)を
 * 超える見込みなら、1回も呼ばずに止まる。ブランド・ホイールの判定(OpenAI)はここでは行わない(DBの保存済み、または --cache-file の判定を使う)。
 * 結果のファイルは作らない(DBも書き換えない)。出力は、材料の件数、依頼CT-1の文、Claudeの文と quotes、確認で落ちた理由の符号、
 * 使ったモデル名とトークン量。APIキーは出さない。根拠の文・構造は出さない。
 *
 * 開発・検証専用。本番で実行されうる経路には登録しない。
 */
#[Signature('strength-weakness-insight:measure
    {--self= : 自社。ラベル:website_analysis_id (例 サイボウズ:776)}
    {--competitors= : 競合(表示順)。ラベル:id をカンマ区切り (例 マネーフォワード:774,SmartHR:775)}
    {--cache-file= : 保存していない判定結果(JSON。website_analysis_id => {axes, ...})}
    {--live : Claudeを呼ぶ(既定は呼ばず、材料と見込み回数だけ出す)}
    {--repeat : --live のとき、同じ材料でもう1回呼んで、1回目との一致を出す}
    {--max-ai-calls=0 : AIを呼んでよい回数の上限(既定0=呼ばない。超える見込みなら1回も呼ばず、実行中も達したら止まる)}
')]
#[Description('非本番・開発専用: 強み・弱みの下書き(Claudeが根拠つきで書く)を保存済みの判定結果で測る(依頼DB)')]
class MeasureStrengthWeaknessInsightCommand extends Command
{
    public function handle(
        ComparisonCommentaryMaterialBuilder $baseBuilder,
        StrengthWeaknessInsightMaterialBuilder $builder,
        SiteFirstImpressionMaterialBuilder $firstImpression,
        StrengthWeaknessInsightService $service,
        ComparisonStrengthWeaknessComposer $composer,
    ): int {
        if (app()->environment('production')) {
            $this->error('production環境ではこのコマンドを実行できません。');

            return self::FAILURE;
        }

        $self = $this->parsePair((string) $this->option('self'));
        $competitors = array_map(fn (string $pair) => $this->parsePair($pair), array_values(array_filter(explode(',', (string) $this->option('competitors')))));
        if ($self === null || $competitors === [] || in_array(null, $competitors, true)) {
            $this->error('--self と --competitors は ラベル:id の形で指定してください。');

            return self::FAILURE;
        }

        $cacheFile = $this->option('cache-file') ? (string) $this->option('cache-file') : null;
        $cache = $cacheFile !== null && is_file($cacheFile) ? (array) json_decode((string) file_get_contents($cacheFile), true) : [];

        $entries = [];
        foreach (array_merge([$self], $competitors) as $i => [$label, $id]) {
            $wa = WebsiteAnalysis::query()->with('website')->findOrFail($id);
            $record = $wa->brandWheelAnalysisResults()->latest('id')->first();
            $source = 'DBの保存済み';
            if ($record === null || $record->status !== 'success' || (array) ($record->axes ?? []) === []) {
                if (isset($cache[(string) $id])) {
                    $record = (new BrandWheelAnalysisResult)->forceFill($cache[(string) $id]);
                    $source = 'キャッシュ';
                } else {
                    $this->error("{$label}(website_analysis_id={$id}) の判定結果が無いため、材料を作れません。AIは呼びません。");

                    return self::FAILURE;
                }
            }
            $entries[$i] = ['name' => $label, 'wa' => $wa, 'record' => $record];
            $this->line(sprintf('%s  website_analysis_id=%d  %s  %s', $i === 0 ? '自社' : '競合', $id, $label, $source));
        }

        $toEntry = fn (array $e) => ['name' => $e['name'], 'record' => $e['record'], 'website' => $e['wa']->website];
        $base = $baseBuilder->buildFromEntries($self[0], $toEntry($entries[0]), array_map($toEntry, array_slice($entries, 1)));
        $structurePage = null;
        if (($base['status'] ?? null) === 'ok') {
            try {
                $structurePage = $firstImpression->build($entries[0]['wa'])['pages']['recruit'] ?? null;
            } catch (\Throwable) {
                $structurePage = null;
            }
        }
        $material = $builder->fromBase($base, $structurePage, [$self[0]]);

        $this->info('=== 材料(AIを使わない) ===');
        if ($material['status'] !== 'ok') {
            $this->warn('材料を作りませんでした: '.$material['reason'].'(この場合、AIは呼ばない)');

            return self::SUCCESS;
        }
        $this->line(sprintf(
            '競合: %s / 24項目の判定 %d / 自社の根拠の文 %d件 / 競合の根拠の文 %d件 / 採用TOPの構造: %s / 削った根拠の文 %d件',
            implode('、', array_column($material['competitors'], 'name')),
            count($material['items']),
            count($material['self_evidence']),
            count($material['competitor_evidence']),
            $material['structure'] === null ? '取れず' : sprintf('ナビ%d・セクション%d', count($material['structure']['nav'] ?? []), count($material['structure']['sections'] ?? [])),
            $material['evidence_trimmed'],
        ));
        $this->printBaseline($composer, $base, $self[0]);

        $expected = $this->option('live') ? ($this->option('repeat') ? 2 : 1) : 0;
        $max = max(0, (int) $this->option('max-ai-calls'));
        $this->info("AI呼び出しの見込み: {$expected}回(上限 {$max}回)");
        if ($expected > $max) {
            $this->error("AI呼び出しが上限(--max-ai-calls={$max}回)を超える見込み({$expected}回)のため、1回も呼ばずに止めます。");

            return self::FAILURE;
        }
        if (! $this->option('live')) {
            return self::SUCCESS;
        }

        $calls = 0;
        $guard = function () use (&$calls, $max): bool {
            if ($calls >= $max) {
                return false;
            }
            $calls++;

            return true;
        };

        $first = $this->composeOnce($service, $material, '1回目', $guard);
        $second = null;
        if ($this->option('repeat')) {
            $second = $this->composeOnce($service, $material, '2回目(同じ材料)', $guard);
            $this->info('--- 1回目と2回目の違い ---');
            foreach (['strength' => '強み', 'weakness' => '弱み'] as $key => $name) {
                $this->line(sprintf(
                    '  %s: 文が%s / 確認の結果が%s / quotes が%s',
                    $name,
                    ($first[$key]['sentence'] ?? null) === ($second[$key]['sentence'] ?? null) ? '同じ' : '違う',
                    $first['rejected'][$key] === $second['rejected'][$key] ? '同じ' : '違う',
                    array_column($first[$key]['quotes'] ?? [], 'text') === array_column($second[$key]['quotes'] ?? [], 'text') ? '同じ' : '違う',
                ));
            }
        }

        $this->info("AI呼び出し 合計: {$calls}回");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    private function composeOnce(StrengthWeaknessInsightService $service, array $material, string $title, callable $guard): array
    {
        $result = $service->compose($material, $guard);
        $this->info("=== 強み・弱みの下書き(Claude) {$title} ===");
        if ($result['skipped'] !== null) {
            $this->warn('書けませんでした: '.$result['skipped']);
        }
        foreach (['strength' => '強み', 'weakness' => '弱み'] as $key => $name) {
            if ($result[$key] !== null) {
                $this->line("  {$name}(確認を通った): ".$result[$key]['sentence']);
                foreach ($result[$key]['quotes'] as $quote) {
                    $this->line("    quotes: [{$quote['company']}] {$quote['text']}");
                }
            } elseif ($result['skipped'] === null) {
                $this->line("  {$name}: 確認を通らなかった → CT-1の文に戻る。理由: ".implode('、', $result['rejected'][$key]));
                // 測定のために、確認前のAIの文を見せる(ファイルにもログにも置かない)。
                $candidate = $result['candidates'][$key] ?? null;
                $this->line('    (AIの文) '.(is_array($candidate) ? (string) ($candidate['text'] ?? '') : ''));
                foreach ((array) (is_array($candidate) ? ($candidate['quotes'] ?? []) : []) as $quote) {
                    $this->line('    (AIの quotes) ['.($quote['company'] ?? '').'] '.($quote['text'] ?? ''));
                }
            }
        }
        $this->line(sprintf('  トークン 入力%d / 出力%d  model=%s prompt=%s', (int) $result['usage_input_tokens'], (int) $result['usage_output_tokens'], (string) $result['model'], (string) $result['prompt_version']));

        return $result;
    }

    /**
     * 依頼CT-1の文(6領域の点数から作る)。
     *
     * @param  array<string, mixed>  $base
     */
    private function printBaseline(ComparisonStrengthWeaknessComposer $composer, array $base, string $selfName): void
    {
        $axes = [];
        foreach ($base['axis_scores'] as $row) {
            $axes[] = ['name' => $row['axis'], 'caption' => null, 'denominator' => $row['denominator'], 'self_count' => $row['self'], 'competitor_counts' => $row['competitors'], 'self_gap' => $row['self'] < max($row['competitors'])];
        }
        $companies = [['name' => $selfName, 'matched' => $base['totals']['self'], 'total' => 24, 'is_self' => true, 'material_sufficient' => true]];
        foreach ($base['competitors'] as $i => $competitor) {
            $companies[] = ['name' => $competitor['name'], 'matched' => $base['totals']['competitors'][$i], 'total' => 24, 'is_self' => false, 'material_sufficient' => true];
        }
        $this->line('点数: '.implode(' / ', array_map(fn (array $c) => "{$c['name']} {$c['matched']}/24", $companies)));
        $baseline = $composer->compose($companies, $axes, true);
        $this->info('=== 依頼CT-1の文(点数から) ===');
        $this->line('  強み：'.($baseline['strength'] ?? '-'));
        $this->line('  弱み：'.($baseline['weakness'] ?? '-'));
    }

    /**
     * @return ?array{0: string, 1: int}
     */
    private function parsePair(string $pair): ?array
    {
        [$label, $id] = array_pad(explode(':', trim($pair), 2), 2, null);

        return $label !== null && $label !== '' && $id !== null && ctype_digit($id) ? [$label, (int) $id] : null;
    }
}
