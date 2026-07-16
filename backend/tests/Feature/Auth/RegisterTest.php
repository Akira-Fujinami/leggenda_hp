<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Concerns\InteractsAsSpaFrontend;

class RegisterTest extends TestCase
{
    use RefreshDatabase;
    use InteractsAsSpaFrontend;

    public function test_user_can_register_successfully(): void
    {
        $response = $this->jsonAsFrontend('POST', '/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.email', 'test@example.com');
        $response->assertJsonPath('message', '登録しました。');
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);

        // 登録直後にログイン状態になっていること
        $this->jsonAsFrontend('GET', '/api/user')->assertOk()->assertJsonPath('data.email', 'test@example.com');
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->jsonAsFrontend('POST', '/api/register', [
            'name' => 'Another User',
            'email' => 'dup@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $response->assertJsonValidationErrors('email');
    }

    public function test_registration_fails_when_password_confirmation_does_not_match(): void
    {
        $response = $this->jsonAsFrontend('POST', '/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_when_password_is_too_short(): void
    {
        $response = $this->jsonAsFrontend('POST', '/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }
}
