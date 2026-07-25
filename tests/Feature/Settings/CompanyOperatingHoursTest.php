<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The structured operating window on the Company screen: it stores the open/close
 * clock, derives the human "7:00 AM – 2:00 AM daily" label from it, and is the
 * source the Slots generator defaults its hours from.
 */
class CompanyOperatingHoursTest extends TestCase
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
     * The company form submits every field at once; override only what a test
     * cares about.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'The Paddle Room',
            'company_email' => 'hello@thepaddleroom.test',
            'operating_opening_time' => '07:00',
            'operating_closing_time' => '02:00',
        ], $overrides);
    }

    private function stored(string $key): ?string
    {
        return Setting::query()->group(Setting::GROUP_COMPANY)->key($key)->value('value');
    }

    public function test_saving_the_operating_window_stores_the_structured_times(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/company', $this->payload([
                'operating_opening_time' => '06:00',
                'operating_closing_time' => '23:00',
            ]))
            ->assertRedirect();

        self::assertSame('06:00', $this->stored('operating_opening_time'));
        self::assertSame('23:00', $this->stored('operating_closing_time'));
    }

    public function test_the_human_label_is_derived_from_the_window(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/company', $this->payload([
                'operating_opening_time' => '07:00',
                'operating_closing_time' => '02:00',
            ]))
            ->assertRedirect();

        // En-dash, 12-hour, "daily" suffix — the exact string the header preview
        // and any label reader render.
        self::assertSame('7:00 AM – 2:00 AM daily', $this->stored('operating_hours'));
    }

    public function test_a_window_that_crosses_midnight_is_accepted(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/company', $this->payload([
                'operating_opening_time' => '07:00',
                'operating_closing_time' => '02:00',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_the_closing_time_may_not_equal_the_opening_time(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/company', $this->payload([
                'operating_opening_time' => '09:00',
                'operating_closing_time' => '09:00',
            ]))
            ->assertInvalid(['operating_closing_time']);
    }

    public function test_a_window_shorter_than_one_hour_is_rejected(): void
    {
        // Guards the sync reshape: a zero-slot window must never be savable, or
        // "sync to operating hours" would read every hour as closed.
        $this->actingAs($this->admin())
            ->put('/admin/settings/company', $this->payload([
                'operating_opening_time' => '07:00',
                'operating_closing_time' => '07:30',
            ]))
            ->assertInvalid(['operating_closing_time']);
    }

    public function test_a_window_of_exactly_one_hour_is_accepted(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/company', $this->payload([
                'operating_opening_time' => '07:00',
                'operating_closing_time' => '08:00',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_omitting_the_operating_window_does_not_error(): void
    {
        // Simulates an older cached build of this screen (or any client that
        // predates the two structured fields): the request never includes
        // operating_opening_time/closing_time at all. The save must still
        // succeed for the rest of the form.
        $payload = $this->payload();
        unset($payload['operating_opening_time'], $payload['operating_closing_time']);

        $this->actingAs($this->admin())
            ->put('/admin/settings/company', $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_omitting_the_operating_window_preserves_the_previously_saved_value(): void
    {
        $admin = $this->admin();

        // First save sets a deliberately non-default window.
        $this->actingAs($admin)
            ->put('/admin/settings/company', $this->payload([
                'operating_opening_time' => '06:00',
                'operating_closing_time' => '22:00',
            ]))
            ->assertRedirect();

        // Second save, from a client that doesn't send the two fields at all,
        // must leave the window exactly as it was — not reset it to 07:00/02:00.
        $payload = $this->payload(['company_phone' => '09171234567']);
        unset($payload['operating_opening_time'], $payload['operating_closing_time']);

        $this->actingAs($admin)
            ->put('/admin/settings/company', $payload)
            ->assertRedirect();

        self::assertSame('06:00', $this->stored('operating_opening_time'));
        self::assertSame('22:00', $this->stored('operating_closing_time'));
        self::assertSame('6:00 AM – 10:00 PM daily', $this->stored('operating_hours'));
    }

    public function test_the_operating_label_is_never_taken_from_the_form(): void
    {
        // Even if a client forges an operating_hours field, the stored label is
        // recomputed from the times, never trusted from input.
        $this->actingAs($this->admin())
            ->put('/admin/settings/company', $this->payload([
                'operating_opening_time' => '08:00',
                'operating_closing_time' => '20:00',
                'operating_hours' => 'FORGED VALUE',
            ]))
            ->assertRedirect();

        self::assertSame('8:00 AM – 8:00 PM daily', $this->stored('operating_hours'));
    }

    public function test_the_slots_screen_exposes_the_operating_window_for_the_generator(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/company', $this->payload([
                'operating_opening_time' => '05:30',
                'operating_closing_time' => '22:30',
            ]))
            ->assertRedirect();

        $this->actingAs($this->admin())
            ->get('/admin/slots')
            ->assertInertia(fn ($page) => $page
                ->where('options.operating_opening_time', '05:30')
                ->where('options.operating_closing_time', '22:30'));
    }
}
