<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_login_succeeds(): void
    {
        $user = User::factory()->create([
            'username' => 'juan.player',
            'password' => bcrypt('correct-password'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $response = $this->post('/login', [
            'username' => 'juan.player',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_fails(): void
    {
        User::factory()->create([
            'username' => 'juan.player',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->post('/login', [
            'username' => 'juan.player',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_inactive_user_is_rejected_with_a_clear_message_not_a_wrong_password_message(): void
    {
        User::factory()->create([
            'username' => 'deactivated.user',
            'password' => bcrypt('correct-password'),
            'is_active' => false,
        ]);

        $response = $this->post('/login', [
            'username' => 'deactivated.user',
            'password' => 'correct-password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();

        $errors = session('errors');
        self::assertStringContainsString('deactivated', $errors->first('username'));
    }

    public function test_rate_limiter_locks_out_after_repeated_failures(): void
    {
        User::factory()->create([
            'username' => 'juan.player',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->post('/login', [
                'username' => 'juan.player',
                'password' => 'wrong-password',
            ]);
        }

        // The account/IP pair has now burned through its allowance — even the
        // CORRECT password must be refused until the lockout clears.
        $response = $this->post('/login', [
            'username' => 'juan.player',
            'password' => 'correct-password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();

        $errors = session('errors');
        self::assertStringContainsString('Too many sign-in attempts', $errors->first('username'));
    }

    public function test_must_change_password_forces_the_redirect(): void
    {
        User::factory()->create([
            'username' => 'fresh.admin',
            'password' => bcrypt('correct-password'),
            'must_change_password' => true,
        ]);

        $response = $this->post('/login', [
            'username' => 'fresh.admin',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('password.change'));

        // The gate must also apply to any other admin route on the same session.
        $this->get(route('admin.dashboard'))->assertRedirect(route('password.change'));
    }

    public function test_logout_ends_the_session(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'must_change_password' => false,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user);
        $this->get(route('admin.dashboard'))->assertOk();

        $this->post(route('logout'));

        $this->assertGuest();
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('login|juan.player|127.0.0.1');

        parent::tearDown();
    }
}
