<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\AnalysisJob as AnalysisJobRecord;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 通常の新規分析は常駐Worker(docker/scripts/backend-entrypoint.render.sh)が
 * 自動処理するため、このコマンドは「jobsテーブルにStartAnalysisJobが存在しない
 * まま止まっているAnalysis」(例: dispatch自体が何らかの理由で失敗した等)を
 * 手動で復旧するための保守コマンド。
 *
 * 安全条件を全て満たす場合のみ再dispatchし、以下は一切行わない:
 * - Analysis.statusの直接変更(StartAnalysisJob自身がRunningへ遷移させる)
 * - DBレコードの復元・作成
 * - 既にjobs/failed_jobsに存在する場合の重複dispatch
 */
#[Signature('analysis:requeue-stuck {analysisId : 対象のAnalysis ID} {--dry-run : 実際には再dispatchせず判定結果のみ表示する}')]
#[Description('StartAnalysisJobがjobsテーブルに存在しないままpendingで止まっているAnalysisを、安全条件を満たす場合のみ再dispatchする')]
class RequeueStuckAnalysis extends Command
{
    public function handle(): int
    {
        $analysisId = (int) $this->argument('analysisId');
        $dryRun = (bool) $this->option('dry-run');

        $analysis = Analysis::find($analysisId);

        if ($analysis === null) {
            $this->error("Analysis id={$analysisId} was not found.");

            return self::FAILURE;
        }

        $violations = $this->safetyViolations($analysis);

        if ($violations !== []) {
            $this->warn("Analysis id={$analysisId} does not satisfy the safe-requeue conditions:");
            foreach ($violations as $violation) {
                $this->line("  - {$violation}");
            }

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info("[dry-run] Analysis id={$analysisId} is safe to requeue; StartAnalysisJob would be dispatched.");

            return self::SUCCESS;
        }

        StartAnalysisJob::dispatch($analysisId)->onQueue('analysis');

        Log::warning('analysis:requeue-stuck dispatched a StartAnalysisJob for a stuck analysis', [
            'analysis_id' => $analysisId,
        ]);

        $this->info("Analysis id={$analysisId}: StartAnalysisJob has been requeued.");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function safetyViolations(Analysis $analysis): array
    {
        $violations = [];

        if ($analysis->status !== AnalysisStatus::Pending) {
            $violations[] = "status is '{$analysis->status->value}', expected 'pending'.";
        }

        if ($analysis->started_at !== null) {
            $violations[] = 'started_at is already set (analysis is not actually stuck before start).';
        }

        $existingJobCount = AnalysisJobRecord::query()->where('analysis_id', $analysis->id)->count();

        if ($existingJobCount > 0) {
            $violations[] = "analysis_jobs already has {$existingJobCount} row(s) for this analysis.";
        }

        if ($this->tableHasStartAnalysisJob('jobs', $analysis->id)) {
            $violations[] = 'a StartAnalysisJob for this analysis is already present in the jobs table.';
        }

        if ($this->tableHasStartAnalysisJob('failed_jobs', $analysis->id)) {
            $violations[] = 'a StartAnalysisJob for this analysis is already present in failed_jobs.';
        }

        return $violations;
    }

    /**
     * jobs/failed_jobsのpayload(JSON)内のserialize()済みcommand文字列を見て、
     * 同一analysisId宛てのStartAnalysisJobが既にあるかを判定する。
     * unserialize()は使わず文字列一致のみで判定し、任意コード実行のリスクを避ける。
     */
    private function tableHasStartAnalysisJob(string $table, int $analysisId): bool
    {
        $rows = DB::table($table)
            ->where('payload', 'like', '%StartAnalysisJob%')
            ->get(['payload']);

        $needle = '"analysisId";i:'.$analysisId.';';

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->payload, true);
            $command = $decoded['data']['command'] ?? null;

            if (is_string($command) && str_contains($command, $needle)) {
                return true;
            }
        }

        return false;
    }
}
