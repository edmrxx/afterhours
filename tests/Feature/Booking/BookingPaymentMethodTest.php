<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\Setting;
use App\Services\BookingService;
use App\Services\PaymentMethodService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The second QR, end to end over HTTP: what the checkout offers, what the POST
 * is allowed to carry, and what lands on the booking.
 *
 * The seam these guard is the one that broke in review — the checkout renders
 * a chooser only for the methods it published, so the request may only demand
 * a method when there was one to choose. A site that has never filled in its
 * payment settings is a shipped state, not a misconfiguration, and a payment
 * phoned in against it must still submit.
 *
 * The receipt screenshot is the required proof now — the checkout no longer
 * asks for a reference number, so `payment_proof` is the one field every
 * success-path submission below must carry.
 */
class BookingPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private CourtSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $court = Court::factory()->create();
        $this->slot = CourtSlot::factory()
            ->forCourt($court)
            ->onDate(Carbon::today()->addDay())
            ->atHour(9)
            ->available()
            ->create();
    }

    public function test_the_payment_page_offers_both_methods_when_both_are_configured(): void
    {
        $this->publishBdo();
        $this->publishGotyme();

        $booking = $this->reserve();

        $this->get('/booking/'.$booking->code.'/payment')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('PublicSite/Payment')
                ->has('payment.methods', 2)
                // BDO first — catalogue order is the contract, and the checkout
                // pre-selects methods[0] for the single-method case.
                ->where('payment.methods.0.key', Booking::PAYMENT_METHOD_BDO)
                ->where('payment.methods.0.account_number_label', 'Account number')
                ->where('payment.methods.0.account_number', '001234567890')
                ->where('payment.methods.1.key', Booking::PAYMENT_METHOD_GOTYME)
                // Both are banks: neither is ever labelled as a mobile number.
                ->where('payment.methods.1.account_number_label', 'Account number')
                ->where('payment.methods.1.account_number', '0123456789')
                ->etc()
            );
    }

    public function test_gcash_still_publishes_and_still_reads_as_a_wallet(): void
    {
        // Kept as an optional third method: its card must still label the field
        // as a mobile number, and it sorts after the two banks.
        $this->publishBdo();
        $this->publishGcash();

        $booking = $this->reserve();

        $this->get('/booking/'.$booking->code.'/payment')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payment.methods', 2)
                ->where('payment.methods.0.key', Booking::PAYMENT_METHOD_BDO)
                ->where('payment.methods.1.key', Booking::PAYMENT_METHOD_GCASH)
                ->where('payment.methods.1.account_number_label', 'GCash number')
                ->etc()
            );
    }

    public function test_the_payment_page_offers_only_gcash_when_gotyme_is_not_configured(): void
    {
        $this->publishGcash();

        $booking = $this->reserve();

        $this->get('/booking/'.$booking->code.'/payment')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('PublicSite/Payment')
                ->has('payment.methods', 1)
                ->where('payment.methods.0.key', Booking::PAYMENT_METHOD_GCASH)
                ->etc()
            );
    }

    public function test_a_half_configured_method_is_never_offered(): void
    {
        $this->publishGcash();

        // An account name with no number and no QR: nothing the guest could
        // act on, so it must not render as an empty card.
        $this->settings([
            'gotyme_account_name' => 'The Paddle Room Inc',
            'gotyme_account_number' => '',
            'gotyme_qr_path' => null,
        ]);

        $booking = $this->reserve();

        $this->get('/booking/'.$booking->code.'/payment')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payment.methods', 1)
                ->where('payment.methods.0.key', Booking::PAYMENT_METHOD_GCASH)
                ->etc()
            );
    }

    public function test_submitting_a_payment_records_the_chosen_method_on_the_booking(): void
    {
        $this->publishGcash();
        $this->publishGotyme();

        $booking = $this->reserve();

        $this->post('/booking/'.$booking->code.'/payment', [
            // GoTyme rather than GCash, so a default-to-the-first-wallet bug
            // cannot pass this assertion by accident.
            'payment_method' => Booking::PAYMENT_METHOD_GOTYME,
            'payment_proof' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertRedirect('/booking/'.$booking->code);

        $booking->refresh();

        self::assertSame(Booking::STATUS_PENDING_VERIFICATION, $booking->status);
        self::assertSame(Booking::PAYMENT_METHOD_GOTYME, $booking->payment_method);
        self::assertSame('GoTyme', $booking->paymentMethodLabel());
        self::assertNotNull($booking->payment_proof_path);
    }

    public function test_the_checkout_no_longer_collects_a_reference_number_but_still_accepts_one(): void
    {
        $this->publishGcash();

        $booking = $this->reserve();

        $this->post('/booking/'.$booking->code.'/payment', [
            'payment_method' => Booking::PAYMENT_METHOD_GCASH,
            'payment_proof' => UploadedFile::fake()->image('receipt.jpg'),
            'payment_reference' => '1234567890123',
        ])->assertRedirect('/booking/'.$booking->code);

        $booking->refresh();

        self::assertSame(Booking::STATUS_PENDING_VERIFICATION, $booking->status);
        self::assertSame('1234567890123', $booking->payment_reference);
    }

    public function test_a_payment_submits_without_any_reference_number(): void
    {
        $this->publishGcash();

        $booking = $this->reserve();

        $this->post('/booking/'.$booking->code.'/payment', [
            'payment_method' => Booking::PAYMENT_METHOD_GCASH,
            'payment_proof' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertRedirect('/booking/'.$booking->code);

        $booking->refresh();

        self::assertSame(Booking::STATUS_PENDING_VERIFICATION, $booking->status);
        self::assertNull($booking->payment_reference);
        self::assertNotNull($booking->payment_proof_path);
    }

    public function test_a_payment_without_a_receipt_screenshot_is_rejected(): void
    {
        $this->publishGcash();

        $booking = $this->reserve();

        $this->post('/booking/'.$booking->code.'/payment', [
            'payment_method' => Booking::PAYMENT_METHOD_GCASH,
        ])->assertInvalid(['payment_proof']);

        $booking->refresh();

        self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $booking->status);
    }

    public function test_an_unknown_payment_method_is_rejected(): void
    {
        $this->publishGcash();
        $this->publishGotyme();

        $booking = $this->reserve();

        $this->post('/booking/'.$booking->code.'/payment', [
            'payment_method' => 'paymaya',
            'payment_reference' => '1234567890123',
        ])->assertInvalid(['payment_method']);

        $booking->refresh();

        self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $booking->status);
        self::assertNull($booking->payment_method);
    }

    public function test_a_missing_payment_method_is_rejected_when_the_checkout_published_one(): void
    {
        $this->publishGcash();

        $booking = $this->reserve();

        $this->post('/booking/'.$booking->code.'/payment', [
            'payment_reference' => '1234567890123',
        ])->assertInvalid(['payment_method']);

        $booking->refresh();

        self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $booking->status);
    }

    public function test_a_payment_still_submits_when_no_payment_method_is_published(): void
    {
        // The shipped default: payment settings never filled in. The checkout
        // shows "contact us and we will take your payment directly" and no
        // chooser, so the request must not demand one — otherwise the guest
        // who phoned in and paid can never submit proof and the hold simply
        // lapses.
        $booking = $this->reserve();

        $this->get('/booking/'.$booking->code.'/payment')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payment.methods', 0)
                ->etc()
            );

        $this->post('/booking/'.$booking->code.'/payment', [
            'payment_method' => '',
            'payment_proof' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertRedirect('/booking/'.$booking->code);

        $booking->refresh();

        self::assertSame(Booking::STATUS_PENDING_VERIFICATION, $booking->status);
        self::assertNotNull($booking->payment_proof_path);
        // Unrecorded, never guessed: the pages render this as an em dash.
        self::assertNull($booking->payment_method);
        self::assertNull($booking->paymentMethodLabel());
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* --------------------------------------------------------------------- */

    private function reserve(): Booking
    {
        return app(BookingService::class)->reserve([$this->slot->getKey()], [
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
        ]);
    }

    private function publishBdo(): void
    {
        $this->settings([
            'bdo_account_name' => 'The Paddle Room',
            'bdo_account_number' => '001234567890',
            'payment_instructions' => 'Scan the QR, send the exact amount, then type the reference here.',
        ]);
    }

    private function publishGcash(): void
    {
        $this->settings([
            'gcash_account_name' => 'The Paddle Room',
            'gcash_account_number' => '09171234567',
            'payment_instructions' => 'Scan the QR, send the exact amount, then type the reference here.',
        ]);
    }

    private function publishGotyme(): void
    {
        $this->settings([
            'gotyme_account_name' => 'The Paddle Room Inc',
            'gotyme_account_number' => '0123456789',
        ]);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function settings(array $values): void
    {
        // Derived, not listed: a method added to the catalogue must not need a
        // matching line here before its settings can be written in a test.
        app(SettingsService::class)->setMany(
            Setting::GROUP_PAYMENT,
            $values,
            PaymentMethodService::settingTypes(),
        );
    }
}
