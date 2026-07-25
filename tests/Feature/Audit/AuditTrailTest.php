<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditTrail;
use App\Models\Court;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    public function test_creating_a_court_writes_an_audit_entry_with_the_correct_module_and_action(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/courts', [
            'name' => 'Center Court',
            'code' => 'CRT-1',
            'description' => 'The main court.',
            'base_price' => 400,
            'is_active' => true,
            'sort_order' => 1,
        ])->assertRedirect();

        $court = Court::query()->where('code', 'CRT-1')->firstOrFail();

        $entry = AuditTrail::query()
            ->where('module', 'Courts')
            ->where('action', 'create')
            ->where('auditable_type', $court->getMorphClass())
            ->where('auditable_id', $court->getKey())
            ->first();

        self::assertNotNull($entry, 'Creating a court must write a Courts/create audit entry.');
        self::assertSame($admin->getKey(), $entry->user_id);
    }

    public function test_updating_a_court_writes_an_audit_entry_with_the_changed_values(): void
    {
        $admin = $this->admin();
        $court = Court::factory()->create(['name' => 'Old Name', 'base_price' => 300]);

        $this->actingAs($admin)->put('/admin/courts/'.$court->getRouteKey(), [
            'name' => 'New Name',
            'code' => $court->code,
            'description' => $court->description,
            'base_price' => 350,
            'is_active' => true,
            'sort_order' => $court->sort_order,
        ])->assertRedirect();

        $entry = AuditTrail::query()
            ->where('module', 'Courts')
            ->where('action', 'update')
            ->where('auditable_type', $court->getMorphClass())
            ->where('auditable_id', $court->getKey())
            ->first();

        self::assertNotNull($entry, 'Updating a court must write a Courts/update audit entry.');
        self::assertSame('New Name', $entry->new_values['name'] ?? null);
    }

    public function test_deleting_a_court_writes_an_audit_entry(): void
    {
        $admin = $this->admin();
        $court = Court::factory()->create();
        $courtId = $court->getKey();
        $morphClass = $court->getMorphClass();

        $this->actingAs($admin)->delete('/admin/courts/'.$court->getRouteKey())->assertRedirect();

        $entry = AuditTrail::query()
            ->where('module', 'Courts')
            ->where('action', 'delete')
            ->where('auditable_type', $morphClass)
            ->where('auditable_id', $courtId)
            ->first();

        self::assertNotNull($entry, 'Deleting a court must write a Courts/delete audit entry.');
    }

    public function test_login_is_recorded_in_the_audit_trail(): void
    {
        $user = User::factory()->create([
            'username' => 'audit.tester',
            'password' => bcrypt('correct-password'),
            'must_change_password' => false,
        ]);

        $this->post('/login', [
            'username' => 'audit.tester',
            'password' => 'correct-password',
        ])->assertRedirect();

        $entry = AuditTrail::query()
            ->where('module', 'Authentication')
            ->where('action', 'login')
            ->where('user_id', $user->getKey())
            ->first();

        self::assertNotNull($entry, 'A successful login must write an Authentication/login audit entry.');
    }

    public function test_logout_is_recorded_in_the_audit_trail(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/logout')->assertRedirect();

        $entry = AuditTrail::query()
            ->where('module', 'Authentication')
            ->where('action', 'logout')
            ->where('user_id', $admin->getKey())
            ->first();

        self::assertNotNull($entry, 'Logging out must write an Authentication/logout audit entry.');
    }
}
