<?php

namespace App\Console\Commands;

use App\Models\WebsiteAnalysis;
use App\Services\BranchSurveyItems\BranchSurveyItemsInputBuilder;
use App\Services\BranchSurveyItems\BranchSurveyItemsPresenter;
use App\Services\BranchSurveyItems\BranchSurveyItemsService;
use App\Services\BranchSurveyItems\BranchSurveyItemsStore;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use App\Services\Report\CandidateSurveyCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 依頼CT: 階層図の「枝ごとの情報」(調査14項目の判定)を、前回の巡回結果(保存済みのWebsiteAnalysis)で測る。
 * 巡回・コーポレートTOPの取得はしない(保存済みのデータだけを読む)。AIを呼ぶのは--live のときだけで、
 * 承認された呼び出し回数(--max-ai-calls、既定0=呼ばない)を超える見込みなら1回も呼ばずに止まり、実行中も回数に達したらそれ以上は呼ばない。
 * 出力は、対象ごとの確認できた項目名・サーバー側の確認で捨てた件数・入力の文字数だけで、
 * 本文・根拠の文は出さない。階層図のスライド(pptx)は--out-dirへ書き出す。
 *
 * 開発・検証専用。本番で実行されうる経路には登録しない。
 */
#[Signature('branch-survey:measure
    {--ids= : 対象。ラベル:website_analysis_id のカンマ区切り(例 kayac:780,cybozu:776)}
    {--live : 実際にAIを呼ぶ(既定は呼ばず、対象の数と入力の文字数だけを出す)}
    {--repeat : --live のとき、同じ入力でもう1回呼んで、1回目との一致を出す}
    {--again : --live のとき、保存済みの前回の結果と、今回(同じ入力で呼び直した結果)との一致を出す}
    {--max-ai-calls=0 : AIを呼んでよい回数の上限(既定0=呼ばない。超える見込みなら1回も呼ばず、実行中も達したら止まる)}
    {--out-dir= : 階層図のスライド(pptx)の書き出し先}
')]
#[Description('非本番・開発専用: 階層図の枝ごとの調査14項目の判定を、保存済みの巡回結果で測る(依頼CT)')]
class MeasureBranchSurveyItemsCommand extends Command
{
    public function handle(
        AdminComparisonSiteHierarchyBuilder $hierarchy,
        BranchSurveyItemsInputBuilder $inputBuilder,
        BranchSurveyItemsService $service,
        BranchSurveyItemsStore $store,
        BranchSurveyItemsPresenter $presenter,
        AdminComparisonPptxGenerator $generator,
        CandidateSurveyCatalog $catalog,
    ): int {
        if (app()->environment('production')) {
            $this->error('production環境ではこのコマンドを実行できません。');

            return self::FAILURE;
        }

        $targets = [];
        foreach (array_filter(explode(',', (string) $this->option('ids'))) as $pair) {
            [$label, $id] = array_pad(explode(':', trim($pair), 2), 2, null);
            if ($label === null || $id === null || ! ctype_digit($id)) {
                $this->error("--ids は ラベル:id の形で指定してください: {$pair}");

                return self::FAILURE;
            }
            $targets[$label] = (int) $id;
        }
        if ($targets === []) {
            $this->error('--ids を指定してください。');

            return self::FAILURE;
        }

        $live = (bool) $this->option('live');
        $perRun = $live && $this->option('repeat') ? 2 : 1;

        // 呼び出しの回数を先に数える(上限を超えるなら、1回も呼ばない)。
        $plan = [];
        $totalCalls = 0;
        foreach ($targets as $label => $id) {
            $wa = WebsiteAnalysis::findOrFail($id);
            $tree = $hierarchy->buildTree($wa);
            $inputs = $hierarchy->surveyInputs($wa, $tree);
            $rows = [];
            if ($inputs['top'] !== null) {
                $rows[] = ['name' => 'HP Top', 'chars' => mb_strlen($inputBuilder->build([$inputs['top']['html']]))];
            }
            foreach ($inputs['branches'] as $branch) {
                $rows[] = ['name' => $branch['name'], 'chars' => mb_strlen($inputBuilder->build($branch['htmls']))];
            }
            $calls = count(array_filter($rows, fn (array $r) => $r['chars'] > 0));
            $plan[$label] = ['wa' => $wa, 'rows' => $rows, 'calls' => $calls];
            $totalCalls += $calls;
            $this->info("=== {$label} (website_analysis_id={$id}) 対象 ".count($rows).'件、AI呼び出し '.$calls.'回 ===');
            foreach ($rows as $row) {
                $this->line(sprintf('  %s  入力%d文字', $row['name'], $row['chars']));
            }
        }
        $perCompanyCalls = array_sum(array_column($plan, 'calls'));
        $this->line("AI呼び出しの見込み: {$perCompanyCalls}回".($perRun === 2 ? '(--repeat で、最初の1社はさらにもう1回)' : ''));

        if (! $live) {
            return self::SUCCESS;
        }

        $first = array_key_first($plan);
        $expected = $perCompanyCalls + ($perRun === 2 ? $plan[$first]['calls'] : 0);
        $maxAiCalls = max(0, (int) $this->option('max-ai-calls'));
        if ($expected > $maxAiCalls) {
            $this->error("AI呼び出しが上限(--max-ai-calls={$maxAiCalls}回)を超える見込み({$expected}回)のため、1回も呼ばずに止めます。");

            return self::FAILURE;
        }

        // 開発環境の既定はmock。測定は実際のAIで行う。
        config(['services.brand_wheel_ai.provider' => 'openai']);

        $outDir = $this->option('out-dir') ? rtrim((string) $this->option('out-dir'), '/\\') : null;
        if ($outDir !== null && ! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $calls = 0;
        foreach ($plan as $label => $entry) {
            $wa = $entry['wa'];
            if ($calls + $entry['calls'] > $maxAiCalls) {
                $this->error("AI呼び出しが上限({$maxAiCalls}回)に達するため、{$label} 以降は呼ばずに止めます。");
                break;
            }
            $this->info("=== {$label} 判定 ===");
            $previous = $this->option('again') ? $store->read((int) $wa->analysis_id, (int) $wa->id) : null;
            $result = $service->generate($wa, true);
            $calls += (int) $result['ai_calls'];
            $this->printResult($result, $catalog);
            if ($previous !== null) {
                $this->info("--- {$label} 前回の保存結果との一致 ---");
                $this->printAgreement($previous, $result);
            }

            if ($perRun === 2 && $label === $first) {
                $second = $service->generate($wa, true);
                $calls += (int) $second['ai_calls'];
                $this->info("--- {$label} 2回目(同じ入力) ---");
                $this->printResult($second, $catalog);
                $this->printAgreement($result, $second);
                // 2回目の結果を保存したまま(最後に書いたもの)。1回目のスライドを作るため書き戻す。
                $store->write((int) $wa->analysis_id, (int) $wa->id, $result);
            }

            $tree = $presenter->apply($hierarchy->buildTree($wa), $store->read((int) $wa->analysis_id, (int) $wa->id));
            if (($tree['survey']['headline'] ?? null) !== null) {
                $this->line('  見出しの一文: '.$tree['survey']['headline']);
            } else {
                $this->line('  見出しの一文: (出ない)');
            }
            if ($outDir !== null) {
                file_put_contents("{$outDir}/{$label}.pptx", $generator->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));
            }
        }

        $this->info("AI呼び出し 合計: {$calls}回");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printResult(array $result, CandidateSurveyCatalog $catalog): void
    {
        $names = [];
        foreach ($catalog->options() as $option) {
            $names[$option['key']] = $option['name'];
        }

        $rows = [];
        if (is_array($result['top'] ?? null)) {
            $rows[] = ['HP Top'] + [1 => $result['top']];
        }
        foreach ((array) $result['branches'] as $branch) {
            $rows[] = [$branch['name'], $branch];
        }
        foreach ($rows as [$name, $row]) {
            $this->line(sprintf(
                '  %s  [%s]  確認できた情報(%d): %s  サーバー側でfalseにした件数: %d%s',
                $name,
                $row['status'],
                count($row['items']),
                implode('／', array_map(fn (array $i) => $names[$i['key']] ?? $i['key'], $row['items'])),
                (int) $row['discarded'],
                $row['reason'] !== null ? '  理由='.$row['reason'] : '',
            ));
        }
        $discarded = array_sum(array_map(fn ($r) => (int) $r[1]['discarded'], $rows));
        $this->line("  サーバー側でfalseにした件数 合計: {$discarded}");
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function printAgreement(array $a, array $b): void
    {
        $pairs = [];
        if (is_array($a['top'] ?? null) && is_array($b['top'] ?? null)) {
            $pairs[] = ['HP Top', $a['top'], $b['top']];
        }
        foreach ((array) $a['branches'] as $i => $branch) {
            if (isset($b['branches'][$i])) {
                $pairs[] = [$branch['name'], $branch, $b['branches'][$i]];
            }
        }

        $same = 0;
        $inter = 0;
        $union = 0;
        foreach ($pairs as [$name, $x, $y]) {
            $kx = array_column($x['items'], 'key');
            $ky = array_column($y['items'], 'key');
            $i = count(array_intersect($kx, $ky));
            $u = count(array_unique([...$kx, ...$ky]));
            $inter += $i;
            $union += $u;
            $same += $kx === $ky || (count($kx) === count($ky) && $i === count($kx)) ? 1 : 0;
            $this->line(sprintf('  一致 %s: 1回目%d件・2回目%d件・共通%d件・和集合%d件', $name, count($kx), count($ky), $i, $u));
        }
        $this->line(sprintf('  完全に同じ結果だった対象: %d/%d  項目の一致率(共通/和集合): %d/%d', $same, count($pairs), $inter, $union));
    }
}
