<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 管理者ダッシュボードの認証(依頼者指定の共有アカウント方式)。DBの
 * ユーザー/roleには一切依存せず、.env(config('admin.*'))との照合と
 * Laravel session(admin_authenticated)のみで保護する(依頼#13・#19)。
 */
class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.username' => 'admin',
            'admin.password_hash' => Hash::make('correct-password'),
        ]);
    }

    public function test_unauthenticated_get_to_admin_shows_the_login_ui_without_dashboard_data(): void
    {
        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('管理者ログイン');
        // ダッシュボードの見出し・サイドバーのナビゲーションが含まれない
        // こと(依頼#8「背景にダッシュボードを表示した状態でモーダルだけ
        // 被せる設計は避ける」)。
        $response->assertDontSee('管理者ダッシュボード', false);
        $response->assertDontSee('診断企業');
        $response->assertDontSee('ログアウト');
    }

    /**
     * 2026-08-19: 本番(Render)でX-Forwarded-Protoを信頼していないため
     * (bootstrap/app.php参照)、route()の絶対URL生成がhttp://scheme付きに
     * なり、https://で配信されたページ内のfetch()がmixed contentとして
     * ブロックされていた("Failed to fetch")。ログインモーダルのfetch先が
     * scheme/hostを含まない相対パスであることを直接検証する ―― どのorigin
     * (frontendを含む)にも一切依存しないことの根拠(依頼者指摘の再発防止)。
     */
    public function test_login_modal_fetches_a_relative_path_not_an_absolute_url(): void
    {
        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee("fetch('/admin/auth'", false);
        $response->assertDontSee('http://', false);
        $response->assertDontSee('https://', false);
    }

    public function test_unauthenticated_get_to_companies_also_shows_only_the_login_ui(): void
    {
        $response = $this->get('/admin/companies');

        $response->assertOk();
        $response->assertSee('管理者ログイン');
        $response->assertDontSee('診断企業');
    }

    public function test_correct_credentials_authenticate_and_set_the_session_flag(): void
    {
        $response = $this->postJson('/admin/auth', [
            'username' => 'admin',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $this->assertTrue(session('admin_authenticated'));

        // 同じセッションのままダッシュボードへ遷移できる(モーダル無し)。
        $dashboard = $this->get('/admin');
        $dashboard->assertOk();
        $dashboard->assertSee('管理者ダッシュボード', false);
        $dashboard->assertDontSee('管理者ログイン');
    }

    public function test_wrong_password_is_rejected_with_a_generic_message_and_no_session(): void
    {
        $response = $this->postJson('/admin/auth', [
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'ユーザー名またはパスワードが正しくありません。');
        $this->assertNull(session('admin_authenticated'));
    }

    /**
     * 2026-08-19: /admin/auth へのリクエストで例外(このテストでは
     * ValidationException)が起きた際、Accept: application/jsonを送って
     * いるにも関わらずLaravel標準のHTMLエラーページが返り、フロント側の
     * response.json()が"Unexpected token '<'"で失敗する不具合があった
     * (依頼者指摘)。原因はbootstrap/app.phpのshouldRenderJsonWhen()が
     * `api/*`パスのみを対象にしており`/admin/*`が対象外だったこと ――
     * 実際にCSRFトークン不一致(419)で再現することをcurlで確認済み
     * (PHPUnitのテスト環境はCSRF自体をbypassするため419そのものは
     * ここでは再現できないが、同じ$wantsJsonの判定ロジックを通る
     * ValidationExceptionで同じ修正が効いていることを検証する)。
     */
    public function test_missing_credentials_returns_json_not_html(): void
    {
        $response = $this->postJson('/admin/auth', []);

        $response->assertStatus(422);
        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonStructure(['message', 'errors']);
    }

    public function test_wrong_username_gives_the_same_generic_message_as_wrong_password(): void
    {
        $response = $this->postJson('/admin/auth', [
            'username' => 'not-admin',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'ユーザー名またはパスワードが正しくありません。');
    }

    public function test_login_is_refused_when_admin_credentials_are_not_configured(): void
    {
        config(['admin.username' => null, 'admin.password_hash' => null]);

        $response = $this->postJson('/admin/auth', [
            'username' => 'admin',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(401);
        $this->assertNull(session('admin_authenticated'));
    }

    public function test_rate_limit_blocks_repeated_failed_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/admin/auth', ['username' => 'admin', 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $response = $this->postJson('/admin/auth', ['username' => 'admin', 'password' => 'wrong']);

        $response->assertStatus(429);
    }

    public function test_direct_json_request_to_a_protected_page_without_session_is_blocked_with_401(): void
    {
        // DevToolsでモーダルのDOMを消して直接叩いてきたケースを模す
        // (依頼#7・#19「モーダルだけ隠して突破できないように」)。
        $response = $this->getJson('/admin/companies');

        $response->assertStatus(401);
    }

    public function test_write_action_without_session_is_blocked_regardless_of_request_format(): void
    {
        $company = \App\Models\LeadCompany::factory()->create();

        $response = $this->patch("/admin/companies/{$company->id}/sales-status", ['sales_status' => 'won']);

        $response->assertStatus(401);
        $this->assertSame('uncontacted', $company->fresh()->sales_status);
    }

    public function test_authenticated_dashboard_nav_and_logout_use_relative_urls(): void
    {
        $response = $this->withSession(['admin_authenticated' => true])->get('/admin');

        $response->assertOk();
        $response->assertSee('action="/admin/logout"', false);
        $response->assertSee('href="/admin/companies"', false);
        $response->assertSee('href="/admin/analyses"', false);
        $response->assertDontSee('http://', false);
        $response->assertDontSee('https://', false);
    }

    public function test_logout_clears_the_session_and_blocks_further_access(): void
    {
        $this->withSession(['admin_authenticated' => true]);

        $logout = $this->post('/admin/logout');
        $logout->assertRedirect('/admin');

        $response = $this->get('/admin');
        $response->assertOk();
        $response->assertSee('管理者ログイン');
        $response->assertDontSee('管理者ダッシュボード', false);
    }
}
