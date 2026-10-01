<?php

namespace App\Console\Commands;

use App\Models\LeadSession;
use App\Services\Admin\LeadDataDeletionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
 *
 * 依頼CI-1(2026-10-01): パス収集・DB削除・ファイル削除の実装を
 * App\Services\Admin\LeadDataDeletionServiceへ切り出した(会社単位の
 * 物理削除(依頼CI-2)も同じ機構を再利用する)。この切り出しに伴い、
 * これまで削除していなかった商談相手向け添付資料(analysis_attachments、
 * attachments/{analysisId}/配下のPPTX等)も削除されるようになった ――
 * DB行はcascadeOnDeleteで消えていたが実ファイルは孤児として残り続けて
 * いた既存の穴で、意図した変更である。ファイル削除失敗時のログ
 * メッセージは'LeadDataDeletionService: ...'に変わる(旧'lead:purge-
 * expired-sessions: ...'から、削除処理の実体が移ったことを反映)。
 */
#[Signature('lead:purge-expired-sessions {--execute : 実際に削除する(指定しない場合は常にdry-run)} {--force : 確認プロンプトをスキップする(--executeと併用時のみ意味を持つ)}')]
#[Description('保持期間を過ぎたリードセッションとその配下データ(Project/Website/Analysis等・レポートファイル・解析用ストレージ)を安全に確認・削除する')]
class PurgeExpiredLeadSessions extends Command
{
    public function handle(LeadDataDeletionService $deletionService): int
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

        // 依頼CI-1(2026-10-01): ファイル収集・削除のロジックはApp\Services\
        // Admin\LeadDataDeletionServiceへ切り出した(依頼AU・依頼CIと同じ
        // 「コピーして2か所に持たない」要件 ―― 会社単位の物理削除
        // (LeadCompanyDeletionService)もこれを再利用する)。dry-runでも
        // 実行時でも同じ$allAnalysesに対して同じcollectFileTargets()を
        // 呼ぶことで、表示と実際の削除対象がずれないこと(依頼M-2)を
        // 引き続き満たす。
        $allAnalyses = $targets->flatMap(fn (LeadSession $s) => $s->projects)->flatMap(fn ($project) => $project->analyses);
        $fileTargets = $deletionService->collectFileTargets($allAnalyses);
        $reportPaths = $fileTargets['report_paths'];
        $storageTargets = $fileTargets['storage_targets'];
        $attachmentTargets = $fileTargets['attachment_targets'];
        $totalStorageBytes = array_sum(array_column($storageTargets, 'size'));
        $totalAttachmentBytes = array_sum(array_column($attachmentTargets, 'size'));

        // 依頼CF-6(2026-09-29): このコマンドがスケジューラ未登録で一度も
        // 自動実行されていなかった(依頼者指摘)。この依頼ではdry-runの
        // 登録のみを行う(--executeは入れない、別依頼で判断)ため、まずは
        // 「削除予定の件数・解放見込み容量」「ディスク使用量」をログへ
        // 必ず残す ―― Renderのダッシュボードを見なくても、ログだけで
        // 逼迫しているかどうかを判断できるようにする(依頼者指定)。
        // リードの個人情報(会社名・担当者名・メール・電話番号・トークン)は
        // 一切含めない ―― 件数と容量のみ。
        Log::info('lead:purge-expired-sessions: dry-run summary', [
            'execute' => $execute,
            'retention_days' => $retentionDays,
            'expired_lead_session_count' => $targets->count(),
            'cascaded_project_count' => $projectCount,
            'report_file_count' => $reportCount,
            'analysis_storage_directory_count' => count($storageTargets),
            'analysis_storage_bytes_to_free' => $totalStorageBytes,
            // 依頼CI-1(2026-10-01): 既存の穴(添付ファイルを削除していなかった)
            // を塞いだことで新たに分かる値。キーを追加するだけで、既存の
            // キーは変更しない。
            'attachment_file_count' => count($attachmentTargets),
            'attachment_bytes_to_free' => $totalAttachmentBytes,
        ]);

        $diskRoot = (string) config('filesystems.disks.analysis.root');
        if ($diskRoot !== '' && is_dir($diskRoot)) {
            // 依頼CF-6: 保存先ディレクトリ(analysisディスク全体)の合計
            // サイズ・空き容量。ディスク逼迫が依頼CE-1で見つけた
            // 「status=fetchedなのにファイルが読めない」現象の候補に
            // 挙がっているため、これが分かれば本番ログだけで逼迫の有無を
            // 判断できる(依頼者指定)。
            //
            // 合計サイズはPHPで1ファイルずつSPLで数え上げず`du -sb`を使う
            // ―― 実測9.8GBの既存データに対し全ファイルをstat()すると
            // 毎日のスケジュール実行のたびに時間がかかる(このディレクトリは
            // 上のstorageTargetsの集計対象〈期限切れセッションぶんのみ〉
            // よりずっと広い、ディスク全体が対象のため)。duはファイル
            // システム側の集計を使うため大幅に速い。
            $diskUsedBytes = $this->directorySizeBytes($diskRoot);
            $diskFreeBytes = disk_free_space($diskRoot);

            Log::info('lead:purge-expired-sessions: analysis disk usage', [
                'disk_root' => $diskRoot,
                'used_bytes' => $diskUsedBytes,
                'free_bytes' => $diskFreeBytes !== false ? (int) $diskFreeBytes : null,
            ]);
        }

        $this->line('=== 対象件数 ===');
        $this->line("有効期限切れから{$retentionDays}日以上経過したLeadSession: {$targets->count()}件");
        $this->line("連鎖削除されるProject(Website/Analysis等を含む): {$projectCount}件");
        $this->line("削除されるレポートファイル(Word/PDF): {$reportCount}件");
        $this->line('=== 解析用ストレージ(依頼M-2) ===');
        $this->line('削除予定のディレクトリ: '.count($storageTargets).'件、合計 '.$this->formatBytes($totalStorageBytes));
        foreach ($storageTargets as $target) {
            $this->line("  {$target['dir']} (".$this->formatBytes($target['size']).')');
        }
        $this->line('=== 添付資料(依頼CI-1、既存の削除漏れを修正) ===');
        $this->line('削除予定の添付ファイル: '.count($attachmentTargets).'件、合計 '.$this->formatBytes($totalAttachmentBytes));

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

        // 依頼CI-1: $reportPaths/$storageTargets/$attachmentTargetsは
        // 既にDB削除より先(このメソッドの冒頭)で集め終わっている
        // (collectFileTargets()はDB読み取りのみで副作用が無いため、
        // confirm()より前に呼んでも問題ない)。

        $deletionService->deleteProjectsAndSessions(
            $targets->flatMap(fn (LeadSession $s) => $s->projects),
            $targets,
        );

        // ここに到達した時点でDBのコミットは完了している。ファイル削除は
        // ベストエフォート(LeadDataDeletionService::deleteFiles()参照)。
        $deleted = $deletionService->deleteFiles($reportPaths, $storageTargets, $attachmentTargets);

        $this->newLine();
        $this->info('削除しました。');
        $this->line("LeadSession: {$targets->count()}件");
        $this->line("Project(カスケード含む): {$projectCount}件");
        $this->line('レポートファイル: '.count($reportPaths).'件');
        $this->line('解析用ストレージディレクトリ: '.count($storageTargets).'件、合計 '.$this->formatBytes($totalStorageBytes));
        $this->line('添付ファイル: '.count($attachmentTargets).'件、合計 '.$this->formatBytes($totalAttachmentBytes));

        if ($deleted['failed_count'] > 0) {
            $this->warn("{$deleted['failed_count']}件のファイル/ディレクトリ削除に失敗しました(ログを参照してください)。DBの削除自体は完了しています。");
        }

        return self::SUCCESS;
    }

    /**
     * 依頼CF-6: `du -sb`(バイト単位の合計サイズ)を使う。コマンドが
     * 使えない/失敗した環境(du自体が無い等)ではnullを返し、呼び出し元の
     * ログには'used_bytes'を出さない ―― 失敗を握りつぶして0等の誤った
     * 数字を記録しないため。
     */
    private function directorySizeBytes(string $dir): ?int
    {
        $output = @shell_exec('du -sb '.escapeshellarg($dir).' 2>/dev/null');
        if ($output === null || $output === false || trim($output) === '') {
            return null;
        }

        $bytes = (int) strtok(trim($output), "\t ");

        return $bytes > 0 ? $bytes : null;
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
