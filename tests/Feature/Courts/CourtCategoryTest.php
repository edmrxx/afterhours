<?php

declare(strict_types=1);

namespace Tests\Feature\Courts;

use App\Models\Court;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating and editing a court now carries a CATEGORY, and that category is the
 * only thing deciding what the court charges (see App\Services\PricingService).
 *
 * These tests exist because the category is a REQUIRED field added to a form
 * that shipped without it: a request that omits it, or sends a category the
 * rate table has no row for, must never quietly land a court on the wrong
 * money.
 *
 * Note the update routes address a court by SLUG, not id — Court overrides
 * getRouteKeyName() so the public site can use readable links, and the admin
 * routes inherit that binding.
 */
class CourtCategoryTest extends TestCase
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

    /**
     * A complete, valid new-court payload — the form posts every field at once,
     * so the tests do too. Override only the field under test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Skinny Court',
            'code' => 'AH-SK1',
            'category' => Court::CATEGORY_SKINNY,
            'description' => 'The narrow court along the wall.',
            'is_active' => true,
            'sort_order' => 3,
        ], $overrides);
    }

    public function test_an_admin_can_create_a_court_on_the_skinny_category(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/courts', $this->payload())
            ->assertRedirect();

        $court = Court::query()->where('code', 'AH-SK1')->firstOrFail();

        self::assertSame(Court::CATEGORY_SKINNY, $court->categoryKey());
        self::assertSame('Skinny Court', $court->categoryLabel());
    }

    public function test_a_court_created_without_a_category_defaults_to_normal(): void
    {
        $payload = $this->payload(['name' => 'Court 3', 'code' => 'AH-C3']);
        unset($payload['category']);

        $this->actingAs($this->admin())
            ->post('/admin/courts', $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        self::assertSame(
            Court::CATEGORY_NORMAL,
            Court::query()->where('code', 'AH-C3')->firstOrFail()->categoryKey(),
            'An omitted category must fall back to the full-size court, not fail validation.',
        );
    }

    public function test_a_category_the_rate_table_has_no_row_for_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/courts', $this->payload(['category' => 'premium']))
            ->assertInvalid(['category']);

        self::assertSame(0, Court::query()->where('code', 'AH-SK1')->count());
    }

    public function test_an_admin_can_move_an_existing_court_to_another_category(): void
    {
        $court = Court::factory()->create([
            'name' => 'Court 1',
            'code' => 'AH-C1',
            'category' => Court::CATEGORY_NORMAL,
        ]);

        $this->actingAs($this->admin())
            ->put("/admin/courts/{$court->slug}",$this->payload([
                'name' => 'Court 1',
                'code' => 'AH-C1',
                'category' => Court::CATEGORY_SKINNY,
                'remove_photo' => false,
            ]))
            ->assertRedirect();

        self::assertSame(Court::CATEGORY_SKINNY, $court->refresh()->categoryKey());
    }

    public function test_an_update_that_omits_the_category_keeps_the_courts_current_one(): void
    {
        $court = Court::factory()->create([
            'name' => 'Skinny Court',
            'code' => 'AH-SK1',
            'category' => Court::CATEGORY_SKINNY,
        ]);

        $payload = $this->payload([
            'name' => 'Skinny Court',
            'code' => 'AH-SK1',
            'remove_photo' => false,
        ]);
        unset($payload['category']);

        $this->actingAs($this->admin())
            ->put("/admin/courts/{$court->slug}",$payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        self::assertSame(
            Court::CATEGORY_SKINNY,
            $court->refresh()->categoryKey(),
            'A partial submission must never silently reprice a court to the normal rate.',
        );
    }
}
