<?php

namespace App\Console\Commands;

use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 非本番環境限定。診断フロー全体(相談ボタン等)を経由せず、既存の
 * WebsiteAnalysisに対してブランド・ホイール(6軸)分析だけを単体実行する。
 * #99の実測作業(安定性確認・モデル比較・10サイト分布)向け。
 *
 * Jobをキューに積まず、この場で同期的にhandle()を直接呼ぶ ―― CLIで
 * 即座に結果を確認できるようにするため。
 *
 * --forceを指定すると、input_hashが一致する既存のsuccess結果があっても
 * 再利用せずAIを再度呼ぶ(GenerateBrandWheelAnalysisJob::$forceRefresh)。
 * これはproduction環境では強制的に無効化される(Job側で二重にガードする)。
 *
 * #99の安定性確認では、このコマンドを--force付きで3回実行し、6軸のstate・
 * matched_sub_elementsのキー集合・evidenceの抜粋箇所が一致するかを比較する
 * ―― --forceを付けないと2回目以降はinput_hashの一致により結果が再利用され、
 * 「常に完全一致する」という見かけ上の結果になり、AIが実際には1回しか
 * 呼ばれていないため測定として成立しない(docs/phase4-brand-wheel.php 9.1参照)。
 *
 * --output=<path>で、パース済みの判定結果をJSONファイルへ書き出せる
 * (2026-07-30の指摘 ―― 同一website_analysis_idに3回実行すると同じ行が
 * 上書きされ続けるため、手で控える運用では#99の安定性比較が成立しない)。
 * 出力内容はBrandWheelAnalysisResultモデル自身のカラムのみから構成し、
 * LeadSession(企業名・担当者名・連絡先)へは一切アクセスしない ―― この
 * モデルにはそもそもリードの個人情報を保持するカラムが存在しないため、
 * 「除外する」のではなく構造的に含まれ得ない。対象サイト本文のevidence
 * 抜粋は含まれる。
 */
#[Signature('brand-wheel:run {website_analysis_id : 対象WebsiteAnalysisのID} {--force : input_hashが一致していてもAIを再度呼ぶ(#99の安定性確認に必須、production環境では無効)} {--output= : パース済みの判定結果をJSONで書き出すファイルパス(#99の複数回比較用、リードの個人情報は含まれない)}')]
#[Description('非本番環境限定: 既存のWebsiteAnalysisに対しブランド・ホイール分析だけを単体実行する(#99の実測作業向け)')]
class RunBrandWheelAnalysisCommand extends Command
{
    public function handle(BrandWheelAnalysisInputFactory $inputFactory): int
    {
        if (app()->environment('production')) {
            $this->error('production環境ではこのコマンドを実行できません。');

            return self::FAILURE;
        }

        $websiteAnalysisId = (int) $this->argument('website_analysis_id');
        $websiteAnalysis = WebsiteAnalysis::find($websiteAnalysisId);

        if ($websiteAnalysis === null) {
            $this->error("WebsiteAnalysis(id={$websiteAnalysisId})が見つかりません。");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $outputPath = $this->option('output');

        $record = BrandWheelAnalysisResult::query()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
            'is_mock' => false,
            'input_hash' => '',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id, forceRefresh: $force))->handle($inputFactory);

        $record->refresh();

        $this->info("brand_wheel_analysis_results.id={$record->id}");
        $this->line("status: {$record->status}");

        if ($record->axis_state_counts !== null) {
            $this->line('axis_state_counts: '.json_encode($record->axis_state_counts, JSON_UNESCAPED_UNICODE));
        }

        if ($record->axes !== null) {
            foreach ($record->axes as $axis) {
                $keys = implode(',', array_column($axis['matched_sub_elements'] ?? [], 'key'));
                $this->line(sprintf('  %s: %s [%s]', $axis['axis_key'], $axis['state'], $keys));
            }
        }

        if ($record->error_code !== null) {
            $this->warn("error_code: {$record->error_code} / {$record->error_message}");
        }

        if (! empty($outputPath)) {
            $this->writeOutputFile((string) $outputPath, $record);
        }

        return self::SUCCESS;
    }

    /**
     * $record(BrandWheelAnalysisResultモデル)のカラムのみから出力を組み立てる。
     * このモデルにはリードの企業名・担当者名・連絡先を保持するカラムが
     * 存在しないため、LeadSessionを別途読み出さない限りPIIが混入する経路が
     * 無い。ここでは意図的にLeadSession/Analysis/Projectには一切触れない。
     */
    private function writeOutputFile(string $path, BrandWheelAnalysisResult $record): void
    {
        $axes = array_map(function (array $axis) {
            return [
                'axis_key' => $axis['axis_key'],
                'state' => $axis['state'],
                'claimed_sub_element_count' => $axis['claimed_sub_element_count'],
                'discarded_count' => count($axis['discarded_sub_elements'] ?? []),
                'matched_sub_elements' => $axis['matched_sub_elements'] ?? [],
                'discarded_sub_elements' => $axis['discarded_sub_elements'] ?? [],
            ];
        }, $record->axes ?? []);

        $payload = [
            'brand_wheel_analysis_result_id' => $record->id,
            'website_analysis_id' => $record->website_analysis_id,
            'status' => $record->status,
            'provider' => $record->provider,
            'model' => $record->model,
            'prompt_version' => $record->prompt_version,
            'is_mock' => $record->is_mock,
            'generated_at' => $record->generated_at?->toIso8601String(),
            'usage_input_tokens' => $record->usage_input_tokens,
            'usage_output_tokens' => $record->usage_output_tokens,
            'duration_ms' => $record->duration_ms,
            'axis_state_counts' => $record->axis_state_counts,
            'core_value' => [
                'readable' => $record->core_value_readable,
                'evidence' => $record->core_value_evidence,
            ],
            'axes' => $axes,
        ];

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->info("結果をファイルへ書き出しました: {$path}");
    }
}
