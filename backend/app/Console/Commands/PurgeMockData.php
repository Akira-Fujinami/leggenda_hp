<?php

namespace App\Console\Commands;

use App\Models\AiAnalysisResult;
use App\Models\ExternalDataSnapshot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 開発環境に蓄積したMock由来のデータ(ExternalDataSnapshot.is_mock=true /
 * AiAnalysisResult.is_mock=true / E2Eテストが作成したProject一式)を安全に
 * 削除するためのコマンド。
 *
 * デフォルトは常に--dry-run相当(何も削除しない)。実際に削除するには
 * --executeを明示する必要がある。production環境では--executeを渡しても
 * 常に拒否する。
 */
#[Signature('analysis:purge-mock-data {--execute : 実際に削除する(指定しない場合は常にdry-run)} {--include-e2e-projects : E2Eテストが作成したProject一式(user.email LIKE \'e2e-%@example.com\')も削除対象に含める} {--force : 確認プロンプトをスキップする(--executeと併用時のみ意味を持つ)}')]
#[Description('Mock由来のExternalDataSnapshot/AiAnalysisResult(・任意でE2Eテストデータ)を安全に確認・削除する')]
class PurgeMockData extends Command
{
    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $includeE2eProjects = (bool) $this->option('include-e2e-projects');

        if ($execute && app()->environment('production')) {
            $this->error('production環境ではこのコマンドを--executeで実行できません。');

            return self::FAILURE;
        }

        $mockSnapshots = ExternalDataSnapshot::query()->where('is_mock', true)->get();
        $mockAiResults = AiAnalysisResult::query()->where('is_mock', true)->get();
        $e2eProjects = $includeE2eProjects
            ? Project::query()->whereIn('user_id', User::query()->where('email', 'like', 'e2e-%@example.com')->pluck('id'))->get()
            : collect();

        $this->line('=== 対象件数 ===');
        $this->line("ExternalDataSnapshot (is_mock=true): {$mockSnapshots->count()}件");
        $this->line("AiAnalysisResult (is_mock=true): {$mockAiResults->count()}件");

        if ($includeE2eProjects) {
            $this->line("E2Eテスト由来のProject (カスケードでWebsite/Analysis/WebsiteAnalysis/MetricResult/Recommendation等を含む): {$e2eProjects->count()}件");
        } else {
            $this->line('E2Eテスト由来のProjectは対象外です(--include-e2e-projectsを指定すると対象に含められます)。');
        }

        if (! $execute) {
            $this->newLine();
            $this->info('dry-runモードのため、何も削除していません。実際に削除するには --execute を指定してください。');

            return self::SUCCESS;
        }

        if ($mockSnapshots->isEmpty() && $mockAiResults->isEmpty() && $e2eProjects->isEmpty()) {
            $this->info('削除対象がありません。');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('上記の件数を本当に削除しますか?この操作は元に戻せません。', false)) {
            $this->warn('削除を中止しました。');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($mockSnapshots, $mockAiResults, $e2eProjects) {
            foreach ($mockSnapshots as $snapshot) {
                if ($snapshot->raw_storage_path !== null) {
                    Storage::disk('analysis')->delete($snapshot->raw_storage_path);
                }
                $snapshot->delete();
            }

            foreach ($mockAiResults as $aiResult) {
                $aiResult->delete();
            }

            foreach ($e2eProjects as $project) {
                $project->delete();
            }
        });

        $this->newLine();
        $this->info('削除しました。');
        $this->line("ExternalDataSnapshot: {$mockSnapshots->count()}件");
        $this->line("AiAnalysisResult: {$mockAiResults->count()}件");
        if ($includeE2eProjects) {
            $this->line("Project(カスケード含む): {$e2eProjects->count()}件");
        }

        return self::SUCCESS;
    }
}
