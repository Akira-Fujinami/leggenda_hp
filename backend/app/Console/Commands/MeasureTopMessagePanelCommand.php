<?php

namespace App\Console\Commands;

use App\Models\WebsiteAnalysis;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\TopMessageDraft\TopMessageDraftBuilder;
use App\Services\TopMessagePanel\TopMessagePanelMaterialBuilder;
use App\Services\TopMessagePanel\TopMessagePanelService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 依頼CZ: トップメッセージと制度のパネル(AIが核の一文・キーワード・制度を選び、サーバーが確かめる)を、保存済みの巡回結果で測る。
 * 会社は「ラベル:website_analysis_id」で指定する(同じ会社を2回指定すれば、同じ材料でAIをもう1回呼ぶ)。
 *
 * AIを呼ぶのは --live のときだけ(1社1回)。呼ぶ回数が --max-ai-calls(既定0=呼ばない)を超える見込みなら、1回も呼ばずに止まる。
 * 結果のファイルは作らない(DBも書き換えない)。出力は、材料の件数と除いた行の数(理由別)、AIが選んだもの、確認で落ちた理由の符号、
 * 確認を通ったパネル。--show-material で、AIに渡す材料(メッセージの文・制度の候補)も出す。
 * --out-dir を指定すると、各社のスライド(pptx)を書き出す(パネルが無い会社は、素材ページ)。
 *
 * 開発・検証専用。本番で実行されうる経路には登録しない。
 */
#[Signature('top-message-panel:measure
    {--wa= : 会社。ラベル:website_analysis_id をカンマ区切り (例 freee:778,freee2:778,orbray:779)}
    {--live : AIを呼ぶ(既定は呼ばず、材料と見込み回数だけ出す)}
    {--max-ai-calls=0 : AIを呼んでよい回数の上限(既定0=呼ばない。超える見込みなら1回も呼ばず、実行中も達したら止まる)}
    {--show-material : AIに渡す材料(メッセージの文・制度の候補)を出す}
    {--out-dir= : スライド(pptx)の書き出し先}
')]
#[Description('非本番・開発専用: トップメッセージと制度のパネル(AIが選ぶ)を保存済みの巡回結果で測る(依頼CZ)')]
class MeasureTopMessagePanelCommand extends Command
{
    public function handle(
        TopMessagePanelMaterialBuilder $materials,
        TopMessagePanelService $service,
        TopMessageDraftBuilder $draftBuilder,
        AdminComparisonPptxGenerator $generator,
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

        $max = max(0, (int) $this->option('max-ai-calls'));
        $live = (bool) $this->option('live');

        // AIを呼ぶ見込みの回数(材料がある会社の数)。AIを使わずに材料だけ作って数える。
        $expected = 0;
        $built = [];
        foreach ($targets as $i => [$label, $wa]) {
            $built[$i] = $materials->build($wa);
            if ($built[$i]['status'] === 'ok') {
                $expected++;
            }
        }
        $this->line("AI呼び出しの見込み: {$expected}回 / 上限(--max-ai-calls): {$max}回 / --live: ".($live ? 'あり' : 'なし(呼ばない)'));
        if ($live && $expected > $max) {
            $this->error("AI呼び出しが上限(--max-ai-calls={$max}回)を超える見込み({$expected}回)のため、1回も呼ばずに止めます。");

            return self::FAILURE;
        }

        $outDir = $this->option('out-dir') ? rtrim((string) $this->option('out-dir'), '/\\') : null;
        if ($outDir !== null && ! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $calls = 0;
        $totalIn = 0;
        $totalOut = 0;
        foreach ($targets as $i => [$label, $wa]) {
            $material = $built[$i];
            $this->info("=== {$label} (website_analysis_id={$wa->id}) ===");
            $this->line(sprintf('材料: メッセージの文 %d / 制度の候補 %d / 話者: %s / メッセージのページ: %s', count($material['sentences']), count($material['programs']), $material['speaker'] ?? '(なし)', $material['message_title'] ?? '(なし)'));
            $this->line('除いた行(メッセージ): '.json_encode($material['dropped']['message'], JSON_UNESCAPED_UNICODE));
            $this->line('除いた行(制度の候補): '.json_encode($material['dropped']['programs'], JSON_UNESCAPED_UNICODE));
            if ($material['status'] !== 'ok') {
                $this->line("材料が足りない: {$material['reason']}(AIを呼ばない)");
            }
            if ((bool) $this->option('show-material')) {
                foreach ($material['sentences'] as $sentence) {
                    $this->line("  {$sentence['id']}: {$sentence['text']}");
                }
                foreach ($material['programs'] as $program) {
                    $this->line("  {$program['id']} | {$program['name']} | {$program['excerpt']}  [{$program['source']}]");
                }
            }

            $result = null;
            if ($live && $material['status'] === 'ok') {
                $result = $service->compose($material, (string) ($wa->website?->name ?? $label), function () use (&$calls, $max): bool {
                    if ($calls >= $max) {
                        return false;
                    }
                    $calls++;

                    return true;
                });
                $totalIn += (int) $result['usage_input_tokens'];
                $totalOut += (int) $result['usage_output_tokens'];
                $this->line("AI: {$result['ai_calls']}回 / 入力トークン {$result['usage_input_tokens']} / 出力トークン {$result['usage_output_tokens']}");
                $this->line('AIの出力(確認前): '.json_encode($result['candidates'] ?? null, JSON_UNESCAPED_UNICODE));
                $this->line('確認で落ちた理由(件数): '.json_encode($result['rejected'], JSON_UNESCAPED_UNICODE));
                $this->line("結果: {$result['status']}".($result['reason'] !== null ? "(理由: {$result['reason']})" : ''));
                if ($result['panel'] !== null) {
                    $panel = $result['panel'];
                    $this->line("  核の一文: {$panel['core']['text']}");
                    foreach ($panel['body'] as $row) {
                        $this->line("  本文: {$row['text']}");
                    }
                    foreach ($panel['keywords'] as $keyword) {
                        $this->line("  キーワード: {$keyword['phrase']}  (出典 {$keyword['source_sentence_id']})");
                        foreach ($keyword['programs'] as $program) {
                            $this->line("    制度: {$program['name']}｜".($program['summary'] ?? '(説明なし=名前だけ)'));
                        }
                    }
                }
            }

            if ($outDir !== null) {
                $page = $draftBuilder->build($wa, (string) ($wa->website?->name ?? $label));
                if ($page !== null) {
                    if (($result['panel'] ?? null) !== null) {
                        $page['panel'] = [
                            'speaker' => $result['speaker'],
                            'source_title' => $result['message_title'],
                            'core' => (string) $result['panel']['core']['text'],
                            'body' => array_map(fn (array $row) => (string) $row['text'], $result['panel']['body']),
                            'keywords' => $result['panel']['keywords'],
                        ];
                    }
                    file_put_contents("{$outDir}/{$label}.pptx", $generator->generateTopMessageDraftSlide($page));
                    $this->line("スライド: {$label}.pptx(".(isset($page['panel']) ? 'パネル' : '素材ページ').')');
                }
            }
        }

        $this->line("AI呼び出し合計: {$calls}回 / 入力トークン合計 {$totalIn} / 出力トークン合計 {$totalOut}");

        return self::SUCCESS;
    }
}
