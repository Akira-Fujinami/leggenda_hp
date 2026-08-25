<?php

namespace Tests\Feature\Console;

use App\Enums\ReportGenerationStatus;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeExpiredLeadSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeExpiredSessionWithProject(): LeadSession
    {
        $session = LeadSession::factory()->create(['expires_at' => now()->subDays(200)]);
        $user = User::factory()->create();
        $project = new Project(['name' => 'expired-lead-project']);
        $project->user_id = $user->id;
        $project->lead_session_id = $session->id;
        $project->save();

        return $session;
    }

    public function test_dry_run_by_default_does_not_delete_anything(): void
    {
        $this->makeExpiredSessionWithProject();

        $this->artisan('lead:purge-expired-sessions')->assertSuccessful();

        $this->assertDatabaseCount('lead_sessions', 1);
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_execute_with_force_deletes_expired_sessions_and_their_projects(): void
    {
        $this->makeExpiredSessionWithProject();

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertSuccessful();

        $this->assertDatabaseCount('lead_sessions', 0);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_session_still_within_the_retention_window_is_not_purged(): void
    {
        LeadSession::factory()->create(['expires_at' => now()->subDay()]);

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertSuccessful();

        $this->assertDatabaseCount('lead_sessions', 1);
    }

    public function test_execute_with_force_also_deletes_report_files_from_storage(): void
    {
        Storage::fake('analysis');

        $session = $this->makeExpiredSessionWithProject();
        $project = $session->projects->first();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);
        Storage::disk('analysis')->put("reports/{$analysis->id}/report.pdf", '%PDF-fake');
        Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => 'pdf',
            'storage_path' => "reports/{$analysis->id}/report.pdf",
            'status' => ReportGenerationStatus::Completed,
        ]);

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertSuccessful();

        Storage::disk('analysis')->assertMissing("reports/{$analysis->id}/report.pdf");
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_execute_is_refused_in_production(): void
    {
        $this->makeExpiredSessionWithProject();
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertFailed();

        $this->assertDatabaseCount('lead_sessions', 1);
    }

    /**
     * 依頼M-2: dry-runで削除予定の解析用ストレージディレクトリと合計サイズが
     * 表示され、実際には何も消えないこと。
     */
    public function test_dry_run_shows_storage_directories_and_deletes_nothing(): void
    {
        Storage::fake('analysis');
        $session = $this->makeExpiredSessionWithProject();
        $project = $session->projects->first();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);
        $dir = app(AnalysisStoragePaths::class)->analysisDir($analysis->id);
        Storage::disk('analysis')->put("{$dir}/raw/homepage.html", str_repeat('x', 100));

        $this->artisan('lead:purge-expired-sessions')
            ->expectsOutputToContain($dir)
            ->assertSuccessful();

        Storage::disk('analysis')->assertExists("{$dir}/raw/homepage.html");
        $this->assertDatabaseCount('lead_sessions', 1);
    }

    /**
     * 依頼M-2: --executeで対象のAnalysisストレージディレクトリが削除されること。
     */
    public function test_execute_deletes_the_target_analysis_storage_directory(): void
    {
        Storage::fake('analysis');
        $session = $this->makeExpiredSessionWithProject();
        $project = $session->projects->first();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);
        $dir = app(AnalysisStoragePaths::class)->analysisDir($analysis->id);
        Storage::disk('analysis')->put("{$dir}/raw/homepage.html", str_repeat('x', 100));

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertSuccessful();

        Storage::disk('analysis')->assertMissing("{$dir}/raw/homepage.html");
    }

    /**
     * 依頼M-2最重要: 削除対象外(保持期間内)のAnalysisのディレクトリは
     * 消えないこと。
     */
    public function test_execute_does_not_delete_directories_of_analyses_outside_the_target(): void
    {
        Storage::fake('analysis');
        $this->makeExpiredSessionWithProject();

        // 保持期間内(削除対象外)の別のLeadSession/Project/Analysis。
        $keepSession = LeadSession::factory()->create(['expires_at' => now()->addDays(10)]);
        $user = User::factory()->create();
        $keepProject = new Project(['name' => 'kept-lead-project']);
        $keepProject->user_id = $user->id;
        $keepProject->lead_session_id = $keepSession->id;
        $keepProject->save();
        $keepAnalysis = Analysis::factory()->create(['project_id' => $keepProject->id]);
        $keepDir = app(AnalysisStoragePaths::class)->analysisDir($keepAnalysis->id);
        Storage::disk('analysis')->put("{$keepDir}/raw/homepage.html", 'keep me');

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertSuccessful();

        Storage::disk('analysis')->assertExists("{$keepDir}/raw/homepage.html");
        $this->assertDatabaseCount('lead_sessions', 1);
        $this->assertDatabaseHas('lead_sessions', ['id' => $keepSession->id]);
    }

    /**
     * 依頼M-2: ファイル削除に失敗してもDBの削除自体は成功扱いとし、
     * 失敗したパスをログに残すこと。
     */
    public function test_file_deletion_failure_does_not_block_db_deletion_and_is_logged(): void
    {
        Log::spy();
        $session = $this->makeExpiredSessionWithProject();
        $project = $session->projects->first();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);
        Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => 'pdf',
            'storage_path' => "reports/{$analysis->id}/report.pdf",
            'status' => ReportGenerationStatus::Completed,
        ]);

        // 'analysis' diskをMockeryのContractモックへ差し替え、delete()呼び出し
        // (レポートファイル削除)だけを例外で失敗させる。他の呼び出し
        // (ディレクトリ削除・存在確認・サイズ集計)は無害な戻り値にする。
        $diskMock = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $diskMock->shouldReceive('delete')->once()->andThrow(new \RuntimeException('simulated deletion failure'));
        $diskMock->shouldReceive('deleteDirectory')->andReturnTrue();
        $diskMock->shouldReceive('exists')->andReturnFalse();
        $diskMock->shouldReceive('allFiles')->andReturn([]);
        $diskMock->shouldReceive('size')->andReturn(0);
        Storage::shouldReceive('disk')->with('analysis')->andReturn($diskMock);

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertSuccessful();

        $this->assertDatabaseCount('lead_sessions', 0);
        $this->assertDatabaseCount('reports', 0);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === 'lead:purge-expired-sessions: failed to delete a report file after DB commit')
            ->once();
    }

    /**
     * 依頼M-2最重要: DBのトランザクションが失敗(ロールバック)した場合、
     * ファイル削除コード自体に到達しないこと(このコマンドの実装は
     * ファイル削除をDB::transaction()の外・完了後に置いているため、
     * transaction()自体が例外を投げればファイル削除は一切実行されない)。
     */
    public function test_files_are_not_deleted_when_the_transaction_fails(): void
    {
        Storage::fake('analysis');
        $session = $this->makeExpiredSessionWithProject();
        $project = $session->projects->first();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);
        $dir = app(AnalysisStoragePaths::class)->analysisDir($analysis->id);
        Storage::disk('analysis')->put("{$dir}/raw/homepage.html", str_repeat('x', 100));

        // DB全体をmockeryモックに差し替えるとassertDatabaseCount()等の
        // テストヘルパー自身が壊れるため、実インスタンスを包むpartial mockで
        // transaction()呼び出しだけを失敗させる(他の呼び出しは実装へ委譲)。
        $dbMock = \Mockery::mock(app('db'))->makePartial();
        $dbMock->shouldReceive('transaction')->once()->andThrow(new \RuntimeException('simulated transaction failure'));
        DB::swap($dbMock);

        try {
            $this->artisan('lead:purge-expired-sessions --execute --force');
        } catch (\Throwable) {
            // 例外が伝播すること自体は許容する(このテストの関心はファイルの
            // 生死のみ)。
        }

        Storage::disk('analysis')->assertExists("{$dir}/raw/homepage.html");
        $this->assertDatabaseCount('lead_sessions', 1);
    }
}
