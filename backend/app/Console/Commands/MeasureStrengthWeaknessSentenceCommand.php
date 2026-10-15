<?php

namespace App\Console\Commands;

use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelAnalysisException;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use App\Services\BrandWheel\BrandWheelAnalysisProviderFactory;
use App\Services\ComparisonCommentary\ComparisonCommentaryMaterialBuilder;
use App\Services\Report\ComparisonStrengthWeaknessComposer;
use App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceMaterialBuilder;
use App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 依頼CV: 強み・弱みの一文(機械が選んだ1項目・1社から、AIが書く)を、保存済みの巡回結果とブランド・ホイールの判定結果で測る。
 * 自社と競合は「ラベル:website_analysis_id」で指定する(ラベルが企業名として材料・文に出る)。
 *
 * AIを呼ぶのは次の2種類だけで、合計が --max-ai-calls(既定0=呼ばない)を超える見込みなら、1回も呼ばずに止まる:
 *  - --judge / --rejudge : ブランド・ホイールの判定(1社1回)。--judge は成功した判定の無い会社だけ、--rejudge は全社。
 *                          結果は --cache-file に置き、DBは書き換えない(本番のジョブにある通知・改善提案の起動などの副作用を持ち込まない)。
 *                          判定の前に、巡回結果から入力を作れるか(AIを使わず)確かめ、作れない会社があれば1回も呼ばずに止まる。
 *  - --live              : 強み・弱みの一文(1回。--repeat なら、同じ材料でもう1回)。結果のファイルは作らない。
 * 出力は、取り上げた項目・会社、依頼CT-1の文、AIの文とquote、確認で落ちた理由の符号。根拠の文は出さない。
 *
 * 開発・検証専用。本番で実行されうる経路には登録しない。
 */
#[Signature('strength-weakness-sentence:measure
    {--self= : 自社。ラベル:website_analysis_id (例 サイボウズ:763)}
    {--competitors= : 競合(表示順)。ラベル:id をカンマ区切り (例 マネーフォワード:766,SmartHR:465)}
    {--judge : 成功した判定の無い会社のブランド・ホイールの判定をAIで行う(1社1回)}
    {--rejudge : 全社のブランド・ホイールを判定し直す(1社1回。DBやキャッシュの既存の判定は使わない)}
    {--cache-file= : 判定の結果(JSON)の置き場所。--judge のときは、あれば再利用する}
    {--live : 強み・弱みの一文のAIを呼ぶ(既定は呼ばず、材料と見込み回数だけ出す)}
    {--repeat : --live のとき、同じ材料でもう1回呼んで、1回目との一致を出す}
    {--max-ai-calls=0 : AIを呼んでよい回数の上限(既定0=呼ばない。超える見込みなら1回も呼ばず、実行中も達したら止まる)}
')]
#[Description('非本番・開発専用: 強み・弱みの一文(機械が選んだ1項目・1社からAIが書く)を保存済みの判定結果で測る(依頼CV)')]
class MeasureStrengthWeaknessSentenceCommand extends Command
{
    public function handle(
        ComparisonCommentaryMaterialBuilder $baseBuilder,
        StrengthWeaknessSentenceMaterialBuilder $builder,
        StrengthWeaknessSentenceService $service,
        BrandWheelAnalysisInputFactory $inputFactory,
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

        $rejudge = (bool) $this->option('rejudge');
        $cacheFile = $this->option('cache-file') ? (string) $this->option('cache-file') : null;
        $cache = $cacheFile !== null && is_file($cacheFile) ? (array) json_decode((string) file_get_contents($cacheFile), true) : [];

        // 判定結果の用意(--rejudge は全社を判定 / 保存済みの成功 → キャッシュ → 要判定)。
        $entries = [];
        foreach (array_merge([$self], $competitors) as $i => [$label, $id]) {
            $wa = WebsiteAnalysis::query()->with('website')->findOrFail($id);
            $record = null;
            $source = '判定なし(要判定)';
            if (! $rejudge) {
                $record = $wa->brandWheelAnalysisResults()->latest('id')->first();
                $source = 'DBの保存済み';
                if ($record === null || $record->status !== 'success' || (array) ($record->axes ?? []) === []) {
                    if (isset($cache[(string) $id])) {
                        $record = (new BrandWheelAnalysisResult)->forceFill($cache[(string) $id]);
                        $source = 'キャッシュ';
                    } else {
                        $record = null;
                        $source = '判定なし(要判定)';
                    }
                }
            } else {
                $source = '判定し直し';
            }
            $entries[$i] = ['name' => $label, 'wa' => $wa, 'record' => $record];
            $this->line(sprintf('%s  website_analysis_id=%d  %s  %s', $i === 0 ? '自社' : '競合', $id, $label, $source));
        }

        $needs = array_keys(array_filter($entries, fn (array $e) => $e['record'] === null));
        $judgeCalls = ($this->option('judge') || $rejudge) ? count($needs) : 0;
        $liveCalls = $this->option('live') ? ($this->option('repeat') ? 2 : 1) : 0;
        $expected = $judgeCalls + $liveCalls;
        $max = max(0, (int) $this->option('max-ai-calls'));
        $this->info("AI呼び出しの見込み: 判定 {$judgeCalls}回 + 一文 {$liveCalls}回 = {$expected}回(上限 {$max}回)");

        // 判定の前に、巡回結果から入力を作れるか(AIを使わず。承認の回数の確認より先に)確かめる。作れない会社があれば、1回も呼ばずに止める。
        if ($judgeCalls > 0) {
            foreach ($needs as $i) {
                try {
                    $input = $inputFactory->build($entries[$i]['wa']);
                } catch (\Throwable $e) {
                    $this->error("{$entries[$i]['name']} の入力を作れません(".$e::class.')。AIは1回も呼ばずに止めます。');

                    return self::FAILURE;
                }
                $chars = mb_strlen($input->recruitPageBodyText) + mb_strlen($input->homepageBodyText);
                $this->line(sprintf('  入力の確認 %s: 本文 %d文字(起点由来 %d文字)', $entries[$i]['name'], $chars, (int) $input->originChars));
                if ($chars < (int) config('brand_wheel.insufficient_input_min_total_chars', 200)) {
                    $this->error("{$entries[$i]['name']} は本文が少なく、判定できません。AIは1回も呼ばずに止めます。");

                    return self::FAILURE;
                }
            }
        }

        if ($expected > $max) {
            $this->error("AI呼び出しが上限(--max-ai-calls={$max}回)を超える見込み({$expected}回)のため、1回も呼ばずに止めます。");

            return self::FAILURE;
        }

        // 開発環境の既定はmock。測定は実際のAIで行う(AIを呼ばないときは何も変えない)。
        if ($expected > 0) {
            config(['services.brand_wheel_ai.provider' => 'openai']);
        }

        $calls = 0;
        foreach ($needs as $i) {
            if ($judgeCalls === 0) {
                break;
            }
            if ($calls + 1 > $max) {
                $this->error('AI呼び出しが上限に達するため、残りの判定は行わずに止めます。');

                return self::FAILURE;
            }
            $wa = $entries[$i]['wa'];
            $this->info("--- {$entries[$i]['name']} のブランド・ホイールを判定 ---");
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

        foreach ($entries as $entry) {
            if ($entry['record'] === null) {
                $this->error("{$entry['name']} の判定結果が無いため、材料を作れません(--judge で判定できます)。");

                return self::FAILURE;
            }
        }

        $toEntry = fn (array $e) => ['name' => $e['name'], 'record' => $e['record'], 'website' => $e['wa']->website];
        $base = $baseBuilder->buildFromEntries($self[0], $toEntry($entries[0]), array_map($toEntry, array_slice($entries, 1)));
        $material = $builder->fromBase($base, [$self[0]]);

        $this->info('=== 材料(AIを使わない) ===');
        if ($material['status'] !== 'ok') {
            $this->warn('材料を作りませんでした: '.$material['reason'].'(この場合、AIは呼ばない)');
            if (($base['status'] ?? null) === 'ok') {
                $this->printBaseline($composer, $base, $self[0]);
            }

            return self::SUCCESS;
        }
        $this->printMaterial($material);
        $this->printBaseline($composer, $base, $self[0]);

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
                if ($material[$key] === null) {
                    continue;
                }
                $a = $first['candidates'][$key] ?? [];
                $b = $second['candidates'][$key] ?? [];
                $this->line(sprintf(
                    '  %s: AIの文が%s / quoteが%s / 確認の結果が%s',
                    $name,
                    ($a['sentence'] ?? null) === ($b['sentence'] ?? null) ? '同じ' : '違う',
                    ($a['quote'] ?? null) === ($b['quote'] ?? null) ? '同じ' : '違う',
                    $first['rejected'][$key] === $second['rejected'][$key] ? '同じ' : '違う',
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
    private function composeOnce(StrengthWeaknessSentenceService $service, array $material, string $title): array
    {
        $result = $service->compose($material);
        $this->info("=== 強み・弱みの一文(AI) {$title} ===");
        if ($result['skipped'] !== null) {
            $this->warn('書けませんでした: '.$result['skipped']);
        }
        foreach (['strength' => '強み', 'weakness' => '弱み'] as $key => $name) {
            if ($material[$key] === null) {
                $this->line("  {$name}: 取り上げる対象が無いため、AIに書かせていない → CT-1の文");

                continue;
            }
            if ($result[$key] !== null) {
                $this->line("  {$name}(確認を通った): ".$result[$key]['sentence']);
                $this->line('    quote: '.$result[$key]['quote']);
            } else {
                $this->line("  {$name}: 確認を通らなかった → CT-1の文に戻る。理由: ".implode('、', $result['rejected'][$key]));
                // 測定のために、確認前のAIの文を見せる(ファイルにもログにも置かない)。
                $this->line('    (AIが書いた文) '.($result['candidates'][$key]['sentence'] ?? '').' / quote: '.($result['candidates'][$key]['quote'] ?? ''));
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
        if ($material['strength'] !== null) {
            $s = $material['strength'];
            $survey = is_array($s['survey']) ? sprintf(' / 調査項目: %s %s%%', $s['survey']['name'], $s['survey']['percentage']) : '';
            $this->line("取り上げる強み: {$s['axis']}／{$s['name']}(競合{$s['competitor_count']}社のうち{$s['matched']}社)  選び方(strength_basis): {$s['basis']}{$survey}  自社の根拠の文 ".mb_strlen($s['evidence']).'字');
        } else {
            $this->line('取り上げる強み: なし(→ AIに書かせない。CT-1の文)');
        }
        if ($material['weakness'] !== null) {
            $w = $material['weakness'];
            $this->line("取り上げる弱み: {$w['axis']}／{$w['name']}(競合{$w['competitor_count']}社のうち{$w['matched']}社が○)  取り上げる競合: {$w['company_name']}  根拠の文 ".mb_strlen($w['evidence']).'字');
        } else {
            $this->line('取り上げる弱み: なし(→ AIに書かせない。CT-1の文)');
        }
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
