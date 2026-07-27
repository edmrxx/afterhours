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
 * The payment settings screen: BDO required, GoTyme and GCash optional.
 *
 * The rules that matter here are the ones a careless edit would quietly undo:
 * BDO must stay required so checkout can never be published with nowhere to
 * send money; the optional methods must stay optional as a whole but
 * all-or-nothing within themselves; a BANK account number must never inherit
 * the GCash mobile rule (or the reverse); and replacing one QR must never
 * delete another one's file.
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
        // wallet keyed on a Philippine mobile number, whichever position it
        // holds in the catalogue.
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload([
                'gcash_account_name' => 'The Paddle Room',
                'gcash_account_number' => '0123456789',
            ]))
            ->assertInvalid(['gcash_account_number']);
    }

    public function test_gcash_is_now_optional_and_a_blank_gcash_saves(): void
    {
        // GCash was the required method before BDO took that role. Leaving it
        // entirely blank must now be a valid, saveable state.
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        self::assertSame('', $this->setting('gcash_account_name'));
        self::assertSame('', $this->setting('gcash_account_number'));
    }

    public function test_bdo_is_required_and_a_blank_bdo_is_rejected(): void
    {
        // Without a required method an admin could publish a checkout with
        // nowhere to send money.
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload([
                'bdo_account_name' => '',
                'bdo_account_number' => '',
            ]))
            ->assertInvalid(['bdo_account_name', 'bdo_account_number']);
    }

    public function test_the_bdo_number_is_validated_as_a_bank_number_not_a_mobile_one(): void
    {
        // A mobile number is 11 digits, so it passes the 6-20 bank rule — the
        // guarantee that matters is the reverse: BDO must NOT be forced into
        // the 09XXXXXXXXX shape, and a 12-digit account must be accepted.
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload([
                'bdo_account_number' => '0012 3456 7890',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Separators stripped, digits kept verbatim — never reshaped to 09...
        self::assertSame('001234567890', $this->setting('bdo_account_number'));
    }

    public function test_a_bdo_number_that_is_not_digits_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload([
                'bdo_account_number' => 'not-an-account',
            ]))
            ->assertInvalid(['bdo_account_number']);
    }

    public function test_replacing_the_gotyme_qr_deletes_the_superseded_file_and_leaves_the_bdo_qr_alone(): void
    {
        $gotyme = [
            'gotyme_account_name' => 'The Paddle Room Inc',
            'gotyme_account_number' => '0123456789',
        ];

        $this->actingAs($this->admin())
            ->put('/admin/settings/payment', $this->payload($gotyme + [
                'bdo_qr' => $this->qrImage('bdo.png'),
                'gotyme_qr' => $this->qrImage('gotyme.png'),
            ]))
            ->assertRedirect();

        $bdoPath = $this->setting('bdo_qr_path');
        $supersededPath = $this->setting('gotyme_qr_path');

        self::assertNotNull($bdoPath);
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
        // one image must never take another one's file down with it.
        self::assertSame($bdoPath, $this->setting('bdo_qr_path'));
        Storage::disk('public')->assertExists($bdoPath);
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
     * A valid save with BDO — the one required method — filled in, and every
     * optional method blank. The shipped default.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'bdo_account_name' => 'The Paddle Room',
            'bdo_account_number' => '001234567890',
            'gotyme_account_name' => '',
            'gotyme_account_number' => '',
            'gcash_account_name' => '',
            'gcash_account_number' => '',
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
