<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production-safe by default: roles, the bootstrap admin and the settings
     * rows the UI expects. Demo content is opt-in and never touches a real
     * environment.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
            PaymentSettingsSeeder::class,
        ]);

        if ($this->shouldSeedDemoData()) {
            $this->call(DemoDataSeeder::class);
        } else {
            $this->command?->line('Demo data skipped. Run with SEED_DEMO=true locally, or --class=DemoDataSeeder.');
        }
    }

    /**
     * Two doors, both deliberate: an explicit `--class=DemoDataSeeder` run (in
     * which case this method is never reached), or local + SEED_DEMO=true.
     */
    private function shouldSeedDemoData(): bool
    {
        return app()->environment('local')
            && filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOLEAN);
    }
}
