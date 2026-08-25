<?php

namespace App\Console\Commands;

use App\Models\Analysis;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼M-2: これまでのlead:purge-expired-sessionsはDB行(LeadSession/
 * Project/Analysis等)だけを削除し、解析用ストレージ(analyses/{analysisId}
 * 配下の生HTML等)を一切削除していなかった。そのため、過去のpurge実行で
 * DB行だけが消え、ディスク上にはディレクトリが孤児として残っている
 * 可能性がある。
 *
 * このコマンドは一覧表示専用であり、削除は一切行わない
 * (--executeオプション自体を持たない ―― 構造的に削除できない)。
 * 何がどれだけあるかを見てから、削除するかどうかは依頼者が別途判断する。
 */
#[Signature('lead:list-orphaned-analysis-storage')]
#[Description('DBにAnalysis行が存在しない analyses/{id} ディレクトリを一覧表示する(削除は行わない、dry-run専用)')]
class ListOrphanedAnalysisStorage extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk('analysis');

        if (! $disk->exists('analyses')) {
            $this->info('analyses/ ディレクトリ自体が存在しません。');

            return self::SUCCESS;
        }

        $existingAnalysisIds = Analysis::query()->pluck('id')->flip();

        $orphans = [];
        foreach ($disk->directories('analyses') as $dir) {
            // ディレクトリ名(analyses/{id})から数値IDを取り出す。数値以外の
            // 名前(想定外の混入物)は孤児判定の対象外とし、別途警告だけ出す。
            $basename = basename($dir);
            if (! ctype_digit($basename)) {
                $this->warn("想定外のディレクトリ名のためスキップしました: {$dir}");

                continue;
            }

            $analysisId = (int) $basename;
            if ($existingAnalysisIds->has($analysisId)) {
                continue;
            }

            $size = collect($disk->allFiles($dir))->sum(fn (string $file) => $disk->size($file));
            $orphans[] = ['analysis_id' => $analysisId, 'dir' => $dir, 'size' => $size];
        }

        if ($orphans === []) {
            $this->info('孤児ディレクトリはありません。');

            return self::SUCCESS;
        }

        usort($orphans, fn (array $a, array $b) => $b['size'] <=> $a['size']);

        $totalBytes = array_sum(array_column($orphans, 'size'));

        $this->line('=== 孤児ディレクトリ(DBにAnalysis行が存在しない) ===');
        $this->line(count($orphans).'件、合計 '.$this->formatBytes($totalBytes));
        foreach ($orphans as $orphan) {
            $this->line("  {$orphan['dir']} (".$this->formatBytes($orphan['size']).')');
        }

        $this->newLine();
        $this->comment('このコマンドは一覧表示のみで、削除は行いません。削除する場合は内容を確認のうえ、別途判断してください。');

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
