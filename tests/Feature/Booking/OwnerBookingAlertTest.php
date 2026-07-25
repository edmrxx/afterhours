<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\OwnerBookingAlert;
use App\Services\BookingService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The owner's opt-in "someone booked / that booking is confirmed" heads-up:
 * sent only when an address is configured, only for reserve() and confirm(),
 * and never as a work item that could break the booking flow it reports on.
 */
class OwnerBookingAlertTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_EMAIL = 'owner@thepaddleroom.test';

    private BookingService $service;

    private CourtSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BookingService::class);

        $court = Court::factory()->create();
        $this->slot = CourtSlot::factory()
            ->forCourt($court)
            ->onDate(Carbon::today()->addDay())
            ->atHour(9)
            ->available()
            ->create();
    }

    public function test_reserve_sends_the_owner_a_reserved_heads_up_when_an_address_is_set(): void
    {
        $this->setOwnerEmail(self::OWNER_EMAIL);
        Notification::fake();

        $this->service->reserve([$this->slot->getKey()], $this->customer());

        Notification::assertSentOnDemand(
            OwnerBookingAlert::class,
            fn (OwnerBookingAlert $n, array $channels, AnonymousNotifiable $to): bool => $n->event === OwnerBookingAlert::EVENT_RESERVED
                && ($to->routes['mail'] ?? null) === self::OWNER_EMAIL,
        );
    }

    public function test_confirm_sends_the_owner_a_confirmed_heads_up(): void
    {
        $this->setOwnerEmail(self::OWNER_EMAIL);
        $admin = User::factory()->create();

        $booking = $this->service->reserve([$this->slot->getKey()], $this->customer());
        $this->service->submitPayment($booking, '1234567890123');

        Notification::fake();
        $this->service->confirm($booking, $admin);

        Notification::assertSentOnDemand(
            OwnerBookingAlert::class,
            fn (OwnerBookingAlert $n, array $channels, AnonymousNotifiable $to): bool => $n->event === OwnerBookingAlert::EVENT_CONFIRMED
                && ($to->routes['mail'] ?? null) === self::OWNER_EMAIL,
        );
    }

    public function test_both_email_variants_render_without_error(): void
    {
        $admin = User::factory()->create();
        $booking = $this->service->reserve([$this->slot->getKey()], $this->customer());

        $reserved = (new \App\Mail\OwnerBookingAlertMail($booking->fresh(), OwnerBookingAlert::EVENT_RESERVED))->render();
        self::assertStringContainsString('new booking', mb_strtolower($reserved));
        self::assertStringContainsString((string) $booking->code, $reserved);

        $this->service->submitPayment($booking, '1234567890123');
        $this->service->confirm($booking, $admin);

        $confirmed = (new \App\Mail\OwnerBookingAlertMail($booking->fresh(), OwnerBookingAlert::EVENT_CONFIRMED))->render();
        self::assertStringContainsString('confirmed', mb_strtolower($confirmed));
        self::assertStringContainsString((string) $booking->code, $confirmed);
    }

    public function test_no_owner_heads_up_is_sent_when_the_address_is_blank(): void
    {
        // Seeded default is an empty string — the opted-out state.
        $this->setOwnerEmail('');
        Notification::fake();

        $this->service->reserve([$this->slot->getKey()], $this->customer());

        Notification::assertNotSentTo(new AnonymousNotifiable, OwnerBookingAlert::class);
    }

    public function test_a_malformed_stored_address_is_never_used(): void
    {
        // A value that somehow bypassed the form validation must still not be
        // handed to the mailer — the service revalidates before dispatching.
        $this->setOwnerEmail('not-an-email');
        Notification::fake();

        $this->service->reserve([$this->slot->getKey()], $this->customer());

        Notification::assertNotSentTo(new AnonymousNotifiable, OwnerBookingAlert::class);
    }

    private function setOwnerEmail(string $email): void
    {
        // Through the service so the group cache is busted the same way a real
        // settings save busts it.
        app(SettingsService::class)->set(Setting::GROUP_SYSTEM, 'owner_notification_email', $email, 'string');
    }

    /**
     * @return array<string, string>
     */
    private function customer(): array
    {
        return [
            'customer_name' => 'Juan Dela Cruz',
            'customer_phone' => '09171234567',
            'customer_email' => 'juan@example.com',
        ];
    }
}
