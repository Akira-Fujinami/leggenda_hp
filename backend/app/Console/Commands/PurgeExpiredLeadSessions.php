<?php

namespace App\Console\Commands;

use App\Models\LeadSession;
use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 有効期限切れから一定日数(config('lead.retention_days_after_expiry'))を
 * 過ぎたLeadSessionと、そこから生成されたProject一式(Website/Analysis/
 * WebsiteAnalysis/MetricResult/Recommendation/Screenshot/Report等はProjectの
 * cascadeOnDeleteで連鎖削除される)を削除する。個人情報(会社名・氏名・
 * メール・電話番号)を保持し続けないための保持期間ポリシー。
 *
 * デフォルトは常に--dry-run相当(何も削除しない)。実際に削除するには
 * --executeを明示する必要がある。production環境では--executeを渡しても
 * 常に拒否する(PurgeMockDataコマンドと同じ方針)。
 *
 * 依頼M-2(2026-08-25): 従来はレポートファイル(Word/PDF)とDB行だけを
 * 削除しており、クロールで保存した生HTML等(analyses/{analysisId}配下)は
 * 一切削除していなかった ―― DB行は消えてもファイルは孤児として
 * 残り続け、retention_days_after_expiryが実質的に効かず容量が
 * 上限なく増加する問題があった(依頼者指摘)。ここでAnalysis単位の
 * ストレージディレクトリ丸ごとの削除を追加する。
 *
 * ファイル削除はDB::transaction()の外(コミット後)で行う ――
 * ファイル削除はロールバックできないため、トランザクション内で行うと
 * ロールバック時に「DB行は残っているのにファイルだけ消えた」不整合が
 * 起こりうる(既存のレポートファイル削除も同じ問題を抱えていたため、
 * あわせてトランザクションの外へ出した)。ファイル削除に失敗しても
 * DBの削除自体は成功扱いとし、失敗したパスはログに残す。
 */
#[Signature('lead:purge-expired-sessions {--execute : 実際に削除する(指定しない場合は常にdry-run)} {--force : 確認プロンプトをスキップする(--executeと併用時のみ意味を持つ)}')]
#[Description('保持期間を過ぎたリードセッションとその配下データ(Project/Website/Analysis等・レポートファイル・解析用ストレージ)を安全に確認・削除する')]
class PurgeExpiredLeadSessions extends Command
{
    public function handle(AnalysisStoragePaths $paths): int
    {
        $execute = (bool) $this->option('execute');

        if ($execute && app()->environment('production')) {
            $this->error('production環境ではこのコマンドを--executeで実行できません。');

            return self::FAILURE;
        }

        $retentionDays = (int) config('lead.retention_days_after_expiry');
        $cutoff = now()->subDays($retentionDays);

        $targets = LeadSession::query()->where('expires_at', '<', $cutoff)->with('projects.analyses.reports')->get();
        $projectCount = $targets->sum(fn (LeadSession $s) => $s->projects->count());
        $reportCount = $targets->sum(fn (LeadSession $s) => $s->projects->sum(
            fn ($project) => $project->analyses->sum(fn ($analysis) => $analysis->reports->count())
        ));

        // 依頼M-2: 削除予定のAnalysisストレージディレクトリと合計サイズを、
        // DB削除の実行有無に関わらず必ず算出する(dry-runでも実行時でも
        // 同じ集計ロジックを通ることで、表示と実際の削除対象がずれない)。
        $disk = Storage::disk('analysis');
        $storageTargets = [];
        foreach ($targets as $session) {
            foreach ($session->projects as $project) {
                foreach ($project->analyses as $analysis) {
                    $dir = $paths->analysisDir($analysis->id);
                    if (! $disk->exists($dir)) {
                        continue;
                    }
                    $size = collect($disk->allFiles($dir))->sum(fn (string $file) => $disk->size($file));
                    $storageTargets[] = ['analysis_id' => $analysis->id, 'dir' => $dir, 'size' => $size];
                }
            }
        }
        $totalStorageBytes = array_sum(array_column($storageTargets, 'size'));

        $this->line('=== 対象件数 ===');
        $this->line("有効期限切れから{$retentionDays}日以上経過したLeadSession: {$targets->count()}件");
        $this->line("連鎖削除されるProject(Website/Analysis等を含む): {$projectCount}件");
        $this->line("削除されるレポートファイル(Word/PDF): {$reportCount}件");
        $this->line('=== 解析用ストレージ(依頼M-2) ===');
        $this->line('削除予定のディレクトリ: '.count($storageTargets).'件、合計 '.$this->formatBytes($totalStorageBytes));
        foreach ($storageTargets as $target) {
            $this->line("  {$target['dir']} (".$this->formatBytes($target['size']).')');
        }

        if (! $execute) {
            $this->newLine();
            $this->info('dry-runモードのため、何も削除していません。実際に削除するには --execute を指定してください。');

            return self::SUCCESS;
        }

        if ($targets->isEmpty()) {
            $this->info('削除対象がありません。');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('上記の件数を本当に削除しますか?この操作は元に戻せません。', false)) {
            $this->warn('削除を中止しました。');

            return self::SUCCESS;
        }

        // 依頼M-2: 削除するレポートファイルのパスを、DB削除より先に(cascade
        // されてしまう前に)集めておく。実際のファイル削除はDBコミット後。
        $reportPaths = [];
        foreach ($targets as $session) {
            foreach ($session->projects as $project) {
                foreach ($project->analyses as $analysis) {
                    foreach ($analysis->reports as $report) {
                        if ($report->storage_path !== '') {
                            $reportPaths[] = $report->storage_path;
                        }
                    }
                }
            }
        }

        DB::transaction(function () use ($targets) {
            foreach ($targets as $session) {
                foreach ($session->projects as $project) {
                    $project->delete();
                }
                $session->delete();
            }
        });

        // ここに到達した時点でDBのコミットは完了している。ファイル削除は
        // ベストエフォート ―― 失敗してもDBの削除自体は成功扱いのまま、
        // 失敗したパスだけログに残す(トランザクション外のため、ここでの
        // 失敗はもうロールバックに影響しない)。
        $failedPaths = [];
        foreach ($reportPaths as $reportPath) {
            try {
                $disk->delete($reportPath);
            } catch (\Throwable $e) {
                $failedPaths[] = $reportPath;
                Log::warning('lead:purge-expired-sessions: failed to delete a report file after DB commit', [
                    'path' => $reportPath,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
        foreach ($storageTargets as $target) {
            try {
                $disk->deleteDirectory($target['dir']);
            } catch (\Throwable $e) {
                $failedPaths[] = $target['dir'];
                Log::warning('lead:purge-expired-sessions: failed to delete an analysis storage directory after DB commit', [
                    'path' => $target['dir'],
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info('削除しました。');
        $this->line("LeadSession: {$targets->count()}件");
        $this->line("Project(カスケード含む): {$projectCount}件");
        $this->line('レポートファイル: '.count($reportPaths).'件');
        $this->line('解析用ストレージディレクトリ: '.count($storageTargets).'件、合計 '.$this->formatBytes($totalStorageBytes));

        if ($failedPaths !== []) {
            $this->warn(count($failedPaths).'件のファイル/ディレクトリ削除に失敗しました(ログを参照してください)。DBの削除自体は完了しています。');
        }

        return self::SUCCESS;
    }

    private function formatBytes(int|float $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes}B";
        }
        if ($bytes < 1024 ** 2) {
            return round($bytes / 1024, 1).'KB';
        }
        if ($bytes < 1024 ** 3) {
            return round($bytes / 1024 ** 2, 1).'MB';
        }

        return round($bytes / 1024 ** 3, 2).'GB';
    }
}
