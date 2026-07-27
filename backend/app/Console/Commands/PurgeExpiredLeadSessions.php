<?php

namespace App\Console\Commands;

use App\Models\LeadSession;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 有効期限切れから一定日数(config('lead.retention_days_after_expiry'))を
 * 過ぎたLeadSessionと、そこから生成されたProject一式(Website/Analysis/
 * WebsiteAnalysis/MetricResult/Recommendation/Screenshot/Report等はProjectの
 * cascadeOnDeleteで連鎖削除される)を削除する。個人情報(会社名・氏名・
 * メール・電話番号)を保持し続けないための保持期間ポリシー。
 *
 * ReportのDB行はcascadeOnDeleteで消えるが、Word/PDFの実ファイルは
 * Storage上に別途存在するため、DB行を消す前に明示的に削除する
 * (PurgeMockDataコマンドのExternalDataSnapshot.raw_storage_path削除と同じ方針)。
 *
 * デフォルトは常に--dry-run相当(何も削除しない)。実際に削除するには
 * --executeを明示する必要がある。production環境では--executeを渡しても
 * 常に拒否する(PurgeMockDataコマンドと同じ方針)。
 */
#[Signature('lead:purge-expired-sessions {--execute : 実際に削除する(指定しない場合は常にdry-run)} {--force : 確認プロンプトをスキップする(--executeと併用時のみ意味を持つ)}')]
#[Description('保持期間を過ぎたリードセッションとその配下データ(Project/Website/Analysis等)を安全に確認・削除する')]
class PurgeExpiredLeadSessions extends Command
{
    public function handle(): int
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

        $this->line('=== 対象件数 ===');
        $this->line("有効期限切れから{$retentionDays}日以上経過したLeadSession: {$targets->count()}件");
        $this->line("連鎖削除されるProject(Website/Analysis等を含む): {$projectCount}件");
        $this->line("削除されるレポートファイル(Word/PDF): {$reportCount}件");

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

        DB::transaction(function () use ($targets) {
            foreach ($targets as $session) {
                foreach ($session->projects as $project) {
                    foreach ($project->analyses as $analysis) {
                        foreach ($analysis->reports as $report) {
                            if ($report->storage_path !== '') {
                                Storage::disk('analysis')->delete($report->storage_path);
                            }
                        }
                    }

                    $project->delete();
                }

                $session->delete();
            }
        });

        $this->newLine();
        $this->info('削除しました。');
        $this->line("LeadSession: {$targets->count()}件");
        $this->line("Project(カスケード含む): {$projectCount}件");
        $this->line("レポートファイル: {$reportCount}件");

        return self::SUCCESS;
    }
}
