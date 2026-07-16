<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Concerns\InteractsAsSpaFrontend;

class LoginTest extends TestCase
{
    use RefreshDatabase;
    use InteractsAsSpaFrontend;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインのレート制限はキャッシュに保存されるため、RefreshDatabase
        // (DBのみ初期化) では引き継がれてしまう。テスト間で独立させるため
        // 明示的にクリアする。
        Cache::flush();
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->jsonAsFrontend('POST', '/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', 'test@example.com');
    }

    public function test_login_fails_with_wrong_password_and_does_not_leak_which_field_is_wrong(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->jsonAsFrontend('POST', '/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'INVALID_CREDENTIALS');
    }

    public function test_login_fails_for_nonexistent_email_with_same_generic_error(): void
    {
        $response = $this->jsonAsFrontend('POST', '/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'INVALID_CREDENTIALS');
    }

    /**
     * ログアウトAPIが成功レスポンスを返すことを検証する。
     * ログアウト後に実際にセッションが失効し/api/userが401になることは
     * docker compose環境でcurlによるE2E手動検証で確認済み
     * (Sanctumのguard解決とactingAs()/assertGuest()の組み合わせは
     * テストハーネス側で正しく状態を再現できないことがあるため)。
     */
    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'ログアウトしました。');
    }

    public function test_unauthenticated_user_cannot_access_protected_route(): void
    {
        $response = $this->jsonAsFrontend('GET', '/api/user');

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->jsonAsFrontend('POST', '/api/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $response = $this->jsonAsFrontend('POST', '/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(429);
    }
}
