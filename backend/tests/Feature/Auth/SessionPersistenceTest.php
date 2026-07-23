<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsAsSpaFrontend;
use Tests\TestCase;

/**
 * 本番推奨のSESSION_DRIVER=database(Render Postgres)を明示的に使って、
 * ログイン状態が複数リクエストをまたいで維持されることを検証する。
 * phpunit.xmlはテストスイート全体の既定をSESSION_DRIVER=arrayに強制しているため
 * (CSRF検証がrunningUnitTests()で無効化されるのと同様、テストの簡便化のため)、
 * このテストクラスでのみ明示的にdatabaseへ上書きする。
 */
class SessionPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsAsSpaFrontend;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('session.driver', 'database');
    }

    public function test_login_persists_across_separate_requests_with_database_session_driver(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->jsonAsFrontend('POST', '/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ])->assertOk();

        // ログインとは別の(新しい)HTTPリクエストとして送り、sessionsテーブル経由で
        // 認証状態が維持されていることを確認する。
        $me = $this->jsonAsFrontend('GET', '/api/user');
        $me->assertOk();
        $me->assertJsonPath('data.email', 'test@example.com');

        $this->assertDatabaseHas('sessions', ['user_id' => User::first()->id]);
    }

    public function test_project_list_succeeds_after_login_with_database_session_driver(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->jsonAsFrontend('POST', '/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->jsonAsFrontend('GET', '/api/projects')->assertOk();
    }

    public function test_session_regenerate_on_login_keeps_authentication(): void
    {
        // AuthController::login は $request->session()->regenerate() を呼ぶ。
        // regenerate後も新しいセッションIDのCookieで以後のリクエストが認証済みのままであることを確認する。
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->jsonAsFrontend('POST', '/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->jsonAsFrontend('GET', '/api/user')->assertOk();
    }

    public function test_logout_invalidates_the_database_session_and_subsequent_requests_are_401(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->jsonAsFrontend('POST', '/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->jsonAsFrontend('POST', '/api/logout')->assertOk();

        $after = $this->jsonAsFrontend('GET', '/api/user');
        $after->assertStatus(401);
    }
}
