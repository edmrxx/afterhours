<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstraps the one account that can log in on a fresh install.
 * Idempotent — an existing admin keeps its password and profile edits.
 */
class AdminUserSeeder extends Seeder
{
    public const USERNAME = 'admin';

    private const DEFAULT_PASSWORD = 'admin123';

    public function run(): void
    {
        $user = User::withTrashed()->firstOrNew(['username' => self::USERNAME]);
        $created = ! $user->exists;

        if ($created) {
            $user->fill([
                'name' => 'System Administrator',
                'email' => 'admin@afterhours.test',
                'password' => Hash::make(self::DEFAULT_PASSWORD),
                'is_active' => true,
                // The `password.changed` middleware traps them until they rotate it.
                'must_change_password' => true,
            ]);
            $user->save();
        } elseif ($user->trashed()) {
            // Never leave the install without a way in.
            $user->restore();
            $user->forceFill(['is_active' => true])->save();
        }

        if (! $user->hasRole(RolePermissionSeeder::ROLE_ADMIN)) {
            $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        }

        $this->announce($created);
    }

    private function announce(bool $created): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->info($created
            ? 'Admin account created.'
            : 'Admin account already present — left untouched.');

        if (! $created) {
            return;
        }

        $line = str_repeat('*', 74);

        $this->command->warn($line);
        $this->command->warn('*  SECURITY: change the default admin password after first login.       *');
        $this->command->warn('*                                                                      *');
        $this->command->warn('*      username: '.str_pad(self::USERNAME, 54).' *');
        $this->command->warn('*      password: '.str_pad(self::DEFAULT_PASSWORD, 54).' *');
        $this->command->warn('*                                                                      *');
        $this->command->warn('*  These credentials are public knowledge — anyone with this source     *');
        $this->command->warn('*  tree can read them. Rotate before the site is reachable.             *');
        $this->command->warn($line);
    }
}
