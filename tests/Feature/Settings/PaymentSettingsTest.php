<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The payment settings screen, with GoTyme as the optional second method.
 *
 * The rules that matter here are the ones a careless edit would quietly undo:
 * GoTyme must stay optional as a whole but all-or-nothing within itself, its
 * account number is a BANK number and must never inherit the GCash mobile
 * rule, and replacing one QR must never delete the other one's file.
 */
class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake('public');
    }

    public function test_saving_gotyme_details_persists_all_three_keys_and_the_uploaded_qr(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload([
                'gotyme_account_name' => 'The Paddle Room Inc',
                'gotyme_account_number' => '0123456789',
                'gotyme_qr' => $this->qrImage('gotyme.png'),
            ]))
            ->assertRedirect();

        self::assertSame('The Paddle Room Inc', $this->setting('gotyme_account_name'));
        self::assertSame('0123456789', $this->setting('gotyme_account_number'));

        $path = $this->setting('gotyme_qr_path');

        self::assertNotNull($path, 'The uploaded GoTyme QR must be recorded in gotyme_qr_path.');
        Storage::disk('public')->assertExists($path);
    }

    public function test_gotyme_is_optional_and_saving_with_every_field_blank_succeeds(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Blank, not absent: the keys exist so the screen renders empty fields,
        // and an unconfigured GoTyme is the default state, not a broken one.
        self::assertSame('', $this->setting('gotyme_account_name'));
        self::assertSame('', $this->setting('gotyme_account_number'));
        self::assertNull($this->setting('gotyme_qr_path'));
    }

    public function test_a_gotyme_account_name_without_a_number_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload([
                'gotyme_account_name' => 'The Paddle Room Inc',
            ]))
            ->assertInvalid(['gotyme_account_number']);
    }

    public function test_a_gotyme_account_number_without_a_name_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload([
                'gotyme_account_number' => '0123456789',
            ]))
            ->assertInvalid(['gotyme_account_name']);
    }

    public function test_a_gotyme_account_number_is_not_validated_as_a_mobile_number(): void
    {
        // A ten-digit bank account number that starts 01 — nothing a
        // 09XXXXXXXXX rule would ever accept. The separators an admin types
        // are stripped on the way in; the digits themselves are not touched.
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload([
                'gotyme_account_name' => 'The Paddle Room Inc',
                'gotyme_account_number' => '0123 4567 89',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        self::assertSame('0123456789', $this->setting('gotyme_account_number'));
    }

    public function test_the_gcash_number_is_still_validated_as_a_mobile_number(): void
    {
        // The same value GoTyme accepts above must fail here: GCash is a
        // wallet keyed on a Philippine mobile number.
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload([
                'gcash_account_number' => '0123456789',
            ]))
            ->assertInvalid(['gcash_account_number']);
    }

    public function test_replacing_the_gotyme_qr_deletes_the_superseded_file_and_leaves_the_gcash_qr_alone(): void
    {
        $gotyme = [
            'gotyme_account_name' => 'The Paddle Room Inc',
            'gotyme_account_number' => '0123456789',
        ];

        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload($gotyme + [
                'gcash_qr' => $this->qrImage('gcash.png'),
                'gotyme_qr' => $this->qrImage('gotyme.png'),
            ]))
            ->assertRedirect();

        $gcashPath = $this->setting('gcash_qr_path');
        $supersededPath = $this->setting('gotyme_qr_path');

        self::assertNotNull($gcashPath);
        self::assertNotNull($supersededPath);

        // Second save uploads a new GoTyme QR and touches nothing else.
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload($gotyme + [
                'gotyme_qr' => $this->qrImage('gotyme-new.png'),
            ]))
            ->assertRedirect();

        $currentPath = $this->setting('gotyme_qr_path');

        self::assertNotSame($supersededPath, $currentPath);
        Storage::disk('public')->assertExists($currentPath);
        Storage::disk('public')->assertMissing($supersededPath);

        // The other method's QR is neither re-pointed nor deleted — replacing
        // one image must never take the other one's file down with it.
        self::assertSame($gcashPath, $this->setting('gcash_qr_path'));
        Storage::disk('public')->assertExists($gcashPath);
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* --------------------------------------------------------------------- */

    private function admin(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    /**
     * A valid save with GCash filled in and GoTyme blank — the shipped default.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'gcash_account_name' => 'The Paddle Room',
            'gcash_account_number' => '09171234567',
            'gotyme_account_name' => '',
            'gotyme_account_number' => '',
            'payment_instructions' => 'Scan the QR, send the exact amount, then type the reference number here.',
        ], $overrides);
    }

    /** Big enough to clear the 200x200 "still scannable" floor. */
    private function qrImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 400, 400);
    }

    private function setting(string $key): ?string
    {
        return Setting::query()
            ->group(Setting::GROUP_PAYMENT)
            ->key($key)
            ->value('value');
    }
}
