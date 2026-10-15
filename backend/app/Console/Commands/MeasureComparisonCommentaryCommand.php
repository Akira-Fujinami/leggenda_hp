<?php

namespace App\Console\Commands;

use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelAnalysisException;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use App\Services\BrandWheel\BrandWheelAnalysisProviderFactory;
use App\Services\ComparisonCommentary\ComparisonCommentaryMaterialBuilder;
use App\Services\ComparisonCommentary\ComparisonCommentaryService;
use App\Services\ComparisonCommentary\StrengthWeaknessDetailComposer;
use App\Services\Report\ComparisonStrengthWeaknessComposer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 依頼CU: 強み・弱みの一文(AI)を、保存済みの巡回結果とブランド・ホイールの判定結果で測る。
 * 自社と競合は「ラベル:website_analysis_id」で指定する(ラベルが企業名として材料・文に出る)。
 *
 * AIを呼ぶのは次の2種類だけで、合計が --max-ai-calls(既定0=呼ばない)を超える見込みなら、1回も呼ばずに止まる:
 *  - --judge   : 成功した判定の無い会社のブランド・ホイールの判定(1社1回)。結果は --cache-file に置き、DBは書き換えない
 *                (本番のジョブにある通知・改善提案の起動などの副作用を持ち込まない)。
 *  - --live    : 強み・弱みの一文(1回。--repeat なら、同じ材料でもう1回)。結果のファイルは作らない。
 * 出力は、材料の項目名と件数・依頼CT-1の一文・AIの一文・確認で落ちた理由の符号。根拠の文は出さない。
 *
 * 開発・検証専用。本番で実行されうる経路には登録しない。
 */
#[Signature('comparison-commentary:measure
    {--self= : 自社。ラベル:website_analysis_id (例 cybozu:776)}
    {--competitors= : 競合(表示順)。ラベル:id をカンマ区切り (例 マネーフォワード:774,SmartHR:777)}
    {--judge : 成功した判定の無い会社のブランド・ホイールの判定をAIで行う(1社1回)。結果は --cache-file に置く}
    {--cache-file= : --judge の結果(JSON)の置き場所。あれば再利用する}
    {--live : 強み・弱みの一文のAIを呼ぶ(既定は呼ばず、材料と見込み回数だけ出す)}
    {--repeat : --live のとき、同じ材料でもう1回呼んで、1回目との一致を出す}
    {--max-ai-calls=0 : AIを呼んでよい回数の上限(既定0=呼ばない。超える見込みなら1回も呼ばず、実行中も達したら止まる)}
')]
#[Description('非本番・開発専用: 強み・弱みの一文(AI)を保存済みの判定結果で測る(依頼CU)')]
class MeasureComparisonCommentaryCommand extends Command
{
    public function handle(
        ComparisonCommentaryMaterialBuilder $builder,
        ComparisonCommentaryService $service,
        BrandWheelAnalysisInputFactory $inputFactory,
        ComparisonStrengthWeaknessComposer $composer,
        StrengthWeaknessDetailComposer $detailComposer,
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

        // 判定結果の用意(保存済みの成功 → キャッシュ → 要判定)。
        $entries = [];
        $toJudge = [];
        foreach (array_merge([$self], $competitors) as $i => [$label, $id]) {
            $wa = WebsiteAnalysis::query()->with('website')->findOrFail($id);
            $record = $wa->brandWheelAnalysisResults()->latest('id')->first();
            $source = 'DBの保存済み';
            if ($record === null || $record->status !== 'success' || (array) ($record->axes ?? []) === []) {
                if (isset($cache[(string) $id])) {
                    $record = (new BrandWheelAnalysisResult)->forceFill($cache[(string) $id]);
                    $source = 'キャッシュ';
                } else {
                    $record = null;
                    $toJudge[$id] = $wa;
                    $source = '判定なし(要判定)';
                }
            }
            $entries[$i] = ['name' => $label, 'wa' => $wa, 'record' => $record];
            $this->line(sprintf('%s  website_analysis_id=%d  %s  %s', $i === 0 ? '自社' : '競合', $id, $label, $source));
        }

        $judgeCalls = $this->option('judge') ? count($toJudge) : 0;
        $liveCalls = $this->option('live') ? ($this->option('repeat') ? 2 : 1) : 0;
        $expected = $judgeCalls + $liveCalls;
        $max = max(0, (int) $this->option('max-ai-calls'));
        $this->info("AI呼び出しの見込み: 判定 {$judgeCalls}回 + 一文 {$liveCalls}回 = {$expected}回(上限 {$max}回)");

        if ($expected > $max) {
            $this->error("AI呼び出しが上限(--max-ai-calls={$max}回)を超える見込み({$expected}回)のため、1回も呼ばずに止めます。");

            return self::FAILURE;
        }

        // 開発環境の既定はmock。測定は実際のAIで行う(AIを呼ばないときは何も変えない)。
        if ($expected > 0) {
            config(['services.brand_wheel_ai.provider' => 'openai']);
        }

        $calls = 0;
        if ($judgeCalls > 0) {
            foreach ($entries as $i => $entry) {
                if ($entry['record'] !== null) {
                    continue;
                }
                if ($calls + 1 > $max) {
                    $this->error('AI呼び出しが上限に達するため、残りの判定は行わずに止めます。');

                    return self::FAILURE;
                }
                $wa = $entry['wa'];
                $this->info("--- {$entry['name']} のブランド・ホイールを判定 ---");
                try {
                    $calls++;
                    $input = $inputFactory->build($wa);
                    $outcome = app(BrandWheelAnalysisProviderFactory::class)->make()->analyze($input);
                } catch (BrandWheelAnalysisException $e) {
                    $this->error("判定に失敗しました: {$e->errorCode}");

                    return self::FAILURE;
                }
                $result = $outcome->result;
                $attributes = [
                    'status' => 'success',
                    'provider' => $result->provider,
                    'model' => $result->model,
                    'prompt_version' => $result->promptVersion,
                    'axes' => array_map(fn ($axis) => $axis->toArray(), $result->axes),
                    'input_origin_chars' => $input->originChars,
                ];
                $cache[(string) $wa->id] = $attributes;
                if ($cacheFile !== null) {
                    file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                $entries[$i]['record'] = (new BrandWheelAnalysisResult)->forceFill($attributes);
                $matched = array_sum(array_map(fn (array $axis) => count((array) ($axis['matched_sub_elements'] ?? [])), $attributes['axes']));
                $this->line("  判定 完了(○ {$matched}件 / 24項目)");
            }
        }

        foreach ($entries as $entry) {
            if ($entry['record'] === null) {
                $this->error("{$entry['name']} の判定結果が無いため、材料を作れません(--judge で判定できます)。");

                return self::FAILURE;
            }
        }

        $toEntry = fn (array $e) => ['name' => $e['name'], 'record' => $e['record'], 'website' => $e['wa']->website];
        $material = $builder->buildFromEntries($self[0], $toEntry($entries[0]), array_map($toEntry, array_slice($entries, 1)));

        $this->info('=== 材料(AIを使わない) ===');
        if ($material['status'] !== 'ok') {
            $this->warn('材料を作りませんでした: '.$material['reason'].'(この場合、AIは呼ばない)');

            return self::SUCCESS;
        }
        $this->printMaterial($material);
        $this->printBaseline($composer, $material, $self[0]);
        $this->printDetail($detailComposer, $material);

        if (! $this->option('live')) {
            return self::SUCCESS;
        }

        $first = $this->composeOnce($service, $material, '1回目');
        $calls++;
        if ($this->option('repeat')) {
            $second = $this->composeOnce($service, $material, '2回目(同じ材料)');
            $calls++;
            $this->info('--- 1回目と2回目の違い ---');
            foreach (['strength' => '強み', 'weakness' => '弱み'] as $key => $name) {
                $a = $first[$key]['sentence'] ?? null;
                $b = $second[$key]['sentence'] ?? null;
                $this->line(sprintf(
                    '  %s: 文が%s / 項目キー %s',
                    $name,
                    $a === $b ? '同じ' : '違う',
                    ($first[$key]['items'] ?? []) === ($second[$key]['items'] ?? []) ? '同じ' : '違う',
                ));
            }
            $this->line('  確認で落ちた理由: 1回目 '.json_encode($first['rejected'], JSON_UNESCAPED_UNICODE).' / 2回目 '.json_encode($second['rejected'], JSON_UNESCAPED_UNICODE));
        }

        $this->info("AI呼び出し 合計: {$calls}回");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    private function composeOnce(ComparisonCommentaryService $service, array $material, string $title): array
    {
        $result = $service->compose($material);
        $this->info("=== 強み・弱みの一文(AI) {$title} ===");
        if ($result['skipped'] !== null) {
            $this->warn('書けませんでした: '.$result['skipped']);
        }
        foreach (['weakness' => '弱み'] as $key => $name) {
            if ($result[$key] !== null) {
                $this->line("  {$name}(確認を通った): ".$result[$key]['sentence']);
                $this->line('    項目: '.implode('、', $result[$key]['items']).' / 引用: '.count($result[$key]['quotes']).'件');
            } else {
                $this->line("  {$name}: 確認を通らなかった(または書けなかった)→ CT-1の一文に戻る。理由: ".implode('、', $result['rejected'][$key]));
                // 測定のために、確認前のAIの文を見せる(ファイルにもログにも置かない)。
                $this->line('    (AIが書いた文) '.($result['candidates'][$key]['sentence'] ?? '')
                    .' / 項目: '.implode('、', (array) ($result['candidates'][$key]['items'] ?? []))
                    .' / 引用: '.implode(' | ', (array) ($result['candidates'][$key]['quotes'] ?? []))
                    .' / 会社: '.implode('、', (array) ($result['candidates'][$key]['companies'] ?? [])));
            }
        }
        $this->line(sprintf('  トークン 入力%d / 出力%d  model=%s prompt=%s', (int) $result['usage_input_tokens'], (int) $result['usage_output_tokens'], (string) $result['model'], (string) $result['prompt_version']));

        return $result;
    }

    /**
     * @param  array<string, mixed>  $material
     */
    private function printMaterial(array $material): void
    {
        $this->line('競合: '.implode('、', array_column($material['competitors'], 'label')));
        $this->line('自社だけの強み('.count($material['only_strengths']).'件): '.implode('、', array_map(fn (array $r) => "{$r['axis']}／{$r['name']}(競合の○ {$r['competitor_matched_count']}社)", $material['only_strengths'])));
        $this->line('自社の穴('.count($material['holes']).'件): '.implode('、', array_map(fn (array $r) => "{$r['axis']}／{$r['name']}(○の競合: ".implode('・', $r['matched_companies']).')', $material['holes'])));
        $this->line('差がつかない領域('.count($material['tie_axes']).'件): '.implode('、', array_map(fn (array $r) => "{$r['axis']} {$r['score']}/{$r['denominator']}", $material['tie_axes'])));
    }

    /**
     * 依頼CUAの一文(項目と根拠の一節で書く。AIを使わない)と、選ばれた項目・一節を選べず飛ばした項目の数。
     *
     * @param  array<string, mixed>  $material
     */
    private function printDetail(StrengthWeaknessDetailComposer $detailComposer, array $material): void
    {
        $detail = $detailComposer->compose($material);
        $info = $detail['info'];
        $this->info('=== 依頼CUAの一文(項目と根拠の一節。AIなし) ===');
        $this->line('  強み：'.($detail['strength'] ?? '(作れなかった → CT-1の文)'));
        $this->line(sprintf('    種類: %s / 選ばれた項目: %s / 一節を選べず飛ばした項目: %d件', $info['strength_kind'] ?? '-', $info['strength_item'] ?? '-', $info['strength_skipped']));
        $this->line('  弱み：'.($detail['weakness'] ?? '(作れなかった → CT-1の文)'));
        $this->line(sprintf('    選ばれた項目: %s / 一節を選べず飛ばした項目: %d件', $info['weakness_item'] ?? '-', $info['weakness_skipped']));
    }

    /**
     * 依頼CT-1の一文(6領域の点数から作る)。
     *
     * @param  array<string, mixed>  $material
     */
    private function printBaseline(ComparisonStrengthWeaknessComposer $composer, array $material, string $selfName): void
    {
        $axes = [];
        foreach ($material['axis_scores'] as $row) {
            $axes[] = ['name' => $row['axis'], 'caption' => null, 'denominator' => $row['denominator'], 'self_count' => $row['self'], 'competitor_counts' => $row['competitors'], 'self_gap' => $row['self'] < max($row['competitors'])];
        }
        $companies = [['name' => $selfName, 'matched' => $material['totals']['self'], 'total' => 24, 'is_self' => true, 'material_sufficient' => true]];
        foreach ($material['competitors'] as $i => $competitor) {
            $companies[] = ['name' => $competitor['name'], 'matched' => $material['totals']['competitors'][$i], 'total' => 24, 'is_self' => false, 'material_sufficient' => true];
        }
        $this->line('点数: '.implode(' / ', array_map(fn (array $c) => "{$c['name']} {$c['matched']}/24", $companies)));
        $baseline = $composer->compose($companies, $axes, true);
        $this->info('=== 依頼CT-1の一文(点数から) ===');
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
