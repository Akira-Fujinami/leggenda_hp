<?php

namespace App\Services\Admin;

use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\LeadSession;
use App\Models\Project;
use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CI-1(2026-10-01): App\Console\Commands\PurgeExpiredLeadSessionsが
 * 独自に持っていた「削除すべきファイルのパスを集める→DB::transaction()で
 * DB行を消す→コミット後にベストエフォートでファイルを消す」ロジックを
 * ここへ切り出した(依頼AUと同じ「コピーして2か所に持たない」要件)。
 * 依頼CI-2(App\Services\Admin\LeadCompanyDeletionService、会社単位の
 * 物理削除)もこれを再利用する ―― 対象のProject集合をどうやって選んだか
 * (セッションの保持期間切れ/会社単位)によらず、削除の機構自体は1つ。
 *
 * 【依頼CI-1で塞いだ既存の穴】旧実装はreports.storage_pathと
 * AnalysisStoragePaths::analysisDir()(巡回の生HTML等)だけを削除しており、
 * analysis_attachments(商談相手にアップロードされた営業資料PPTX等)の
 * 実ファイルを一切削除していなかった ―― DB行はProjectのcascadeOnDeleteで
 * 消えるが、ディスク上のファイル(attachments/{analysisId}/{uuid}.{ext})は
 * 孤児として残り続けていた。collectFileTargets()がattachment_targetsを
 * 新たに集めることで、このサービスを使うすべての削除経路で同時に直る。
 *
 * ファイル削除をDB::transaction()の外(コミット後)で行う理由は旧実装と
 * 同じ ―― ファイル削除はロールバックできないため、トランザクション内で
 * 行うとロールバック時に「DB行は残っているのにファイルだけ消えた」
 * 不整合が起こりうる。
 */
class LeadDataDeletionService
{
    public function __construct(private readonly AnalysisStoragePaths $paths) {}

    /**
     * DB削除より先に呼ぶこと(cascadeで行が消えた後では辿れない)。
     *
     * @param  Collection<int, Analysis>  $analyses
     * @return array{
     *     report_paths: list<string>,
     *     storage_targets: list<array{analysis_id: int, dir: string, size: int}>,
     *     attachment_targets: list<array{path: string, size: int}>,
     * }
     */
    public function collectFileTargets(Collection $analyses): array
    {
        $disk = Storage::disk('analysis');

        $reportPaths = [];
        foreach ($analyses as $analysis) {
            foreach ($analysis->reports as $report) {
                if ($report->storage_path !== '') {
                    $reportPaths[] = $report->storage_path;
                }
            }
        }

        $storageTargets = [];
        foreach ($analyses as $analysis) {
            $dir = $this->paths->analysisDir($analysis->id);
            if (! $disk->exists($dir)) {
                continue;
            }
            $size = collect($disk->allFiles($dir))->sum(fn (string $file) => $disk->size($file));
            $storageTargets[] = ['analysis_id' => $analysis->id, 'dir' => $dir, 'size' => $size];
        }

        // 依頼CI-1: size_bytes列が既にDBにあるため、Storage::size()を
        // 呼ばずに済む(AnalysisAttachmentServiceが保存時に記録済みの値を
        // そのまま信用する、既存方針と同じ)。
        $attachmentTargets = AnalysisAttachment::query()
            ->whereIn('analysis_id', $analyses->pluck('id'))
            ->get(['storage_path', 'size_bytes'])
            ->map(fn (AnalysisAttachment $attachment) => ['path' => $attachment->storage_path, 'size' => $attachment->size_bytes])
            ->all();

        return [
            'report_paths' => $reportPaths,
            'storage_targets' => $storageTargets,
            'attachment_targets' => $attachmentTargets,
        ];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, LeadSession>  $sessions  省略可(Projectだけを消したい呼び出しにも使えるようにする)。
     */
    public function deleteProjectsAndSessions(Collection $projects, ?Collection $sessions = null): void
    {
        DB::transaction(function () use ($projects, $sessions) {
            foreach ($projects as $project) {
                $project->delete();
            }
            foreach ($sessions ?? [] as $session) {
                $session->delete();
            }
        });
    }

    /**
     * コミット後に呼ぶこと。ベストエフォート ―― 失敗してもDBの削除自体は
     * 成功扱いのまま、失敗したパスはLog::warningに残す(件数のみ、
     * リードの個人情報(会社名・担当者名・メール・電話番号)は出さない)。
     *
     * @param  list<string>  $reportPaths
     * @param  list<array{analysis_id: int, dir: string, size: int}>  $storageTargets
     * @param  list<array{path: string, size: int}>  $attachmentTargets
     * @return array{freed_bytes: int, failed_count: int}
     */
    public function deleteFiles(array $reportPaths, array $storageTargets, array $attachmentTargets): array
    {
        $disk = Storage::disk('analysis');
        $freedBytes = 0;
        $failedCount = 0;

        foreach ($reportPaths as $reportPath) {
            try {
                $disk->delete($reportPath);
            } catch (\Throwable $e) {
                $failedCount++;
                Log::warning('LeadDataDeletionService: failed to delete a report file after DB commit', [
                    'path' => $reportPath,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        foreach ($attachmentTargets as $target) {
            try {
                $disk->delete($target['path']);
                $freedBytes += $target['size'];
            } catch (\Throwable $e) {
                $failedCount++;
                Log::warning('LeadDataDeletionService: failed to delete an attachment file after DB commit', [
                    'path' => $target['path'],
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        foreach ($storageTargets as $target) {
            try {
                $disk->deleteDirectory($target['dir']);
                $freedBytes += $target['size'];
            } catch (\Throwable $e) {
                $failedCount++;
                Log::warning('LeadDataDeletionService: failed to delete an analysis storage directory after DB commit', [
                    'path' => $target['dir'],
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return ['freed_bytes' => $freedBytes, 'failed_count' => $failedCount];
    }
}
