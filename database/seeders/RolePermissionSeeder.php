<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission catalogue is the contract in docs/ARCHITECTURE.md §2.
 * Idempotent by design — re-running only fills gaps, never duplicates.
 */
class RolePermissionSeeder extends Seeder
{
    public const GUARD = 'web';

    /**
     * Every permission in the system, grouped for readability only.
     *
     * @var array<string, list<string>>
     */
    public const CATALOGUE = [
        'dashboard' => ['dashboard.view'],
        'courts' => ['courts.view', 'courts.create', 'courts.update', 'courts.delete'],
        'slots' => ['slots.view', 'slots.create', 'slots.update', 'slots.delete', 'slots.generate'],
        'bookings' => ['bookings.view', 'bookings.verify', 'bookings.delete'],
        'users' => ['users.view', 'users.create', 'users.update', 'users.delete'],
        'roles' => ['roles.view', 'roles.create', 'roles.update', 'roles.delete'],
        'settings' => ['settings.view', 'settings.update'],
        'audit' => ['audit.view'],
        'reports' => ['reports.view', 'reports.export'],
    ];

    public const ROLE_ADMIN = 'Admin';

    public const ROLE_STAFF = 'Staff';

    /**
     * Staff run the day-to-day desk: they schedule slots and verify payments,
     * but never touch users, roles, settings or the audit log.
     *
     * @var list<string>
     */
    public const STAFF_PERMISSIONS = [
        'dashboard.view',
        'courts.view',
        'slots.view',
        'slots.create',
        'slots.update',
        'slots.delete',
        'slots.generate',
        'bookings.view',
        'bookings.verify',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::allPermissions() as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
            ]);
        }

        $admin = Role::firstOrCreate(['name' => self::ROLE_ADMIN, 'guard_name' => self::GUARD]);
        $admin->syncPermissions(self::allPermissions());

        $staff = Role::firstOrCreate(['name' => self::ROLE_STAFF, 'guard_name' => self::GUARD]);
        $staff->syncPermissions(self::STAFF_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Permissions synced: %d total, Admin=%d, Staff=%d.',
            count(self::allPermissions()),
            $admin->permissions()->count(),
            $staff->permissions()->count(),
        ));
    }

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return array_values(array_merge(...array_values(self::CATALOGUE)));
    }
}
