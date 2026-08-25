<?php

namespace Tests\Feature\Console;

use App\Models\Analysis;
use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼M-2: 過去のlead:purge-expired-sessions実行でDB行だけが消え、
 * ディスク上に孤児として残っているディレクトリを一覧表示する
 * (削除は一切行わない、dry-run専用)。
 */
class ListOrphanedAnalysisStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_directories_with_no_matching_analysis_row(): void
    {
        Storage::fake('analysis');
        $paths = app(AnalysisStoragePaths::class);

        // 存在するAnalysis(孤児ではない)。
        $analysis = Analysis::factory()->create();
        Storage::disk('analysis')->put($paths->analysisDir($analysis->id).'/raw/homepage.html', 'kept content');

        // Analysis行が存在しないディレクトリ(孤児)。
        $orphanId = $analysis->id + 1000;
        Storage::disk('analysis')->put($paths->analysisDir($orphanId).'/raw/homepage.html', str_repeat('x', 500));

        $this->artisan('lead:list-orphaned-analysis-storage')
            ->expectsOutputToContain("analyses/{$orphanId}")
            ->assertSuccessful();

        // 削除は一切行わない ―― 孤児側・既存側ともにファイルが残っている。
        Storage::disk('analysis')->assertExists($paths->analysisDir($analysis->id).'/raw/homepage.html');
        Storage::disk('analysis')->assertExists($paths->analysisDir($orphanId).'/raw/homepage.html');
    }

    public function test_does_not_list_the_directory_of_an_existing_analysis(): void
    {
        Storage::fake('analysis');
        $paths = app(AnalysisStoragePaths::class);
        $analysis = Analysis::factory()->create();
        Storage::disk('analysis')->put($paths->analysisDir($analysis->id).'/raw/homepage.html', 'kept content');

        $this->artisan('lead:list-orphaned-analysis-storage')
            ->expectsOutputToContain('孤児ディレクトリはありません')
            ->assertSuccessful();
    }

    public function test_command_has_no_execute_option_and_cannot_delete_anything(): void
    {
        $definition = Artisan::all()['lead:list-orphaned-analysis-storage']->getDefinition();

        $this->assertFalse($definition->hasOption('execute'));
    }
}
