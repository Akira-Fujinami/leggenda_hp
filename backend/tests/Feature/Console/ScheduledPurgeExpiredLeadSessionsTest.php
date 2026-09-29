<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

/**
 * 依頼CF-6(2026-09-29): lead:purge-expired-sessionsをスケジューラへ登録した
 * (bootstrap/app.php::withSchedule())。登録自体が無かった(依頼者指摘)ため、
 * 登録されていること・--executeを付けていないこと(常にdry-run、依頼者指定
 * ―― 実際に削除する判断は別の依頼で行う)をテストで担保する。
 *
 * withSchedule()のクロージャはコンソールカーネルのブート処理
 * (schedule:*系コマンドの実行時)でのみ呼ばれるため、app(Schedule::class)を
 * 直接resolveしても登録内容は見えない ―― 標準の`schedule:list`コマンドの
 * 出力で確認する。
 */
class ScheduledPurgeExpiredLeadSessionsTest extends TestCase
{
    public function test_purge_expired_sessions_is_registered_on_the_schedule(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('lead:purge-expired-sessions')
            ->assertSuccessful();
    }

    /**
     * 依頼CF-6禁止事項: --executeをスケジューラに入れないこと(既存データの
     * 削除の禁止に該当する)。
     */
    public function test_the_scheduled_run_does_not_include_the_execute_flag(): void
    {
        \Illuminate\Support\Facades\Artisan::call('schedule:list');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('lead:purge-expired-sessions', $output);

        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, 'lead:purge-expired-sessions')) {
                $this->assertStringNotContainsString('--execute', $line);
            }
        }
    }
}
