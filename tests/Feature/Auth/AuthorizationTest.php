<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Real HTTP requests through the actual middleware stack (`permission:` +
 * spatie/laravel-permission), not a policy call in isolation — the contract
 * in docs/ARCHITECTURE.md §2 says permissions are the only access primitive,
 * so this proves the route table actually enforces the catalogue.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function staffUser(): User
    {
        $user = User::factory()->create(['must_change_password' => false, 'is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_STAFF);

        return $user;
    }

    private function adminUser(): User
    {
        $user = User::factory()->create(['must_change_password' => false, 'is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    /**
     * @return list<array{0: string}>
     */
    public static function deniedRoutes(): array
    {
        return [
            ['/admin/users'],
            ['/admin/roles'],
            ['/admin/settings/payment'],
            ['/admin/settings/company'],
            ['/admin/settings/theme'],
            ['/admin/audit-trail'],
        ];
    }

    #[DataProvider('deniedRoutes')]
    public function test_staff_is_denied_settings_users_roles_and_audit_routes(string $uri): void
    {
        $this->actingAs($this->staffUser())->get($uri)->assertForbidden();
    }

    /**
     * @return list<array{0: string}>
     */
    public static function allowedRoutes(): array
    {
        return [
            ['/admin'],
            ['/admin/courts'],
            ['/admin/slots'],
            ['/admin/bookings'],
        ];
    }

    #[DataProvider('allowedRoutes')]
    public function test_staff_is_allowed_dashboard_courts_slots_and_bookings_routes(string $uri): void
    {
        $this->actingAs($this->staffUser())->get($uri)->assertOk();
    }

    public function test_staff_cannot_create_a_user_even_by_posting_directly(): void
    {
        $this->actingAs($this->staffUser())->post('/admin/users', [
            'name' => 'Sneaky',
            'username' => 'sneaky',
            'password' => 'whatever123',
        ])->assertForbidden();

        self::assertDatabaseMissing('users', ['username' => 'sneaky']);
    }

    public function test_admin_is_allowed_every_gated_route(): void
    {
        $admin = $this->adminUser();

        foreach (['/admin/users', '/admin/roles', '/admin/settings/payment', '/admin/audit-trail'] as $uri) {
            $this->actingAs($admin)->get($uri)->assertOk();
        }
    }

    public function test_guest_is_redirected_to_login_for_any_admin_route(): void
    {
        $this->get('/admin/courts')->assertRedirect('/login');
    }
}
