<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /** @var class-string<Booking> */
    protected $model = Booking::class;

    /** @var list<string> */
    private const FIRST_NAMES = [
        'Juan', 'Maria', 'Jose', 'Ana', 'Ramon', 'Liza', 'Carlo', 'Grace',
        'Miguel', 'Andrea', 'Paolo', 'Bea', 'Rafael', 'Divine', 'Emmanuel',
        'Kristine', 'Noel', 'Jasmine', 'Dexter', 'Rowena', 'Arnel', 'Cherry',
    ];

    /** @var list<string> */
    private const LAST_NAMES = [
        'Dela Cruz', 'Santos', 'Reyes', 'Bautista', 'Garcia', 'Mendoza',
        'Torres', 'Aquino', 'Ramos', 'Villanueva', 'Castillo', 'Domingo',
        'Navarro', 'Salazar', 'Gonzales', 'Fernandez', 'Bernardo', 'Pascual',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(self::FIRST_NAMES).' '.fake()->randomElement(self::LAST_NAMES);

        return [
            'code' => Booking::generateCode(),
            'court_id' => Court::factory(),
            // Keep the slot on the same court as the booking — a mismatch is
            // impossible in production and breaks any test that joins them.
            'court_slot_id' => fn (array $attributes) => CourtSlot::factory()
                ->state(['court_id' => $attributes['court_id']])
                ->held(),
            'customer_name' => $name,
            'customer_phone' => UserFactory::philippineMobile(),
            'customer_email' => fake()->optional(0.7)->safeEmail(),
            'notes' => fake()->optional(0.3)->sentence(10),
            'amount' => fake()->randomElement([250.00, 300.00, 350.00, 400.00, 450.00, 500.00]),
            'status' => Booking::STATUS_AWAITING_PAYMENT,
            'payment_reference' => null,
            // Travels with the reference in every state below, so it is reset
            // here for the same reason: a booking that has not paid yet cannot
            // have named an app to pay from.
            'payment_method' => null,
            'payment_proof_path' => null,
            'payment_submitted_at' => null,
            'hold_expires_at' => Carbon::now()->addMinutes((int) config('booking.hold_minutes', 30)),
            'confirmed_at' => null,
            'confirmed_by' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
            'cancelled_at' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    /**
     * Attach the booking to an existing slot, inheriting its court and price.
     */
    public function forSlot(CourtSlot $slot): static
    {
        return $this->state(fn (array $attributes): array => [
            'court_id' => $slot->court_id,
            'court_slot_id' => $slot->getKey(),
            'amount' => $slot->price,
        ]);
    }

    /**
     * Attach the booking to a whole set of existing slots — the multi-slot
     * counterpart of forSlot(). Slots are assumed same-court, exactly as the
     * real BookingService::reserve() contract requires. Mirrors the same
     * "primary slot" convention reserve() uses: court_slot_id is set to the
     * earliest-starting slot (by slot_date, then start_time — NOT by id),
     * and amount is the sum of every slot's price.
     *
     * Like forSlot(), this state does NOT itself set `held_booking_id` on the
     * slots — that side effect stays the responsibility of whatever test or
     * seeder calls it.
     *
     * @param  iterable<int, CourtSlot>  $slots
     */
    public function forSlots(iterable $slots): static
    {
        $slots = collect($slots)->values();

        return $this->state(function (array $attributes) use ($slots): array {
            $primary = $slots->sortBy([
                ['slot_date', 'asc'],
                ['start_time', 'asc'],
            ])->first();

            return [
                'court_id' => $primary->court_id,
                'court_slot_id' => $primary->getKey(),
                'amount' => $slots->sum(fn (CourtSlot $slot): float => (float) $slot->price),
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /* Lifecycle states                                                    */
    /* ------------------------------------------------------------------ */

    public function awaitingPayment(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Booking::STATUS_AWAITING_PAYMENT,
            'payment_reference' => null,
            'payment_method' => null,
            'payment_submitted_at' => null,
            'hold_expires_at' => Carbon::now()->addMinutes((int) config('booking.hold_minutes', 30)),
            'confirmed_at' => null,
            'confirmed_by' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'cancelled_at' => null,
        ]);
    }

    public function pendingVerification(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Booking::STATUS_PENDING_VERIFICATION,
            'payment_reference' => self::gcashReference(),
            // Emitted alongside the reference, never left null: a null means
            // "submitted before the site offered a choice", which is a real but
            // historical state. Seeded demo rows are new, so leaving it unset
            // would paint an em dash on every booking in the admin queue and
            // hide the method column's actual behaviour.
            'payment_method' => Booking::PAYMENT_METHOD_GCASH,
            'payment_submitted_at' => Carbon::now()->subMinutes(fake()->numberBetween(1, 120)),
            // Submitting the reference buys the admin a longer window.
            'hold_expires_at' => Carbon::now()->addMinutes((int) config('booking.verification_hold_minutes', 720)),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Booking::STATUS_CONFIRMED,
            'payment_reference' => self::gcashReference(),
            'payment_method' => Booking::PAYMENT_METHOD_GCASH,
            'payment_submitted_at' => Carbon::now()->subHours(fake()->numberBetween(2, 48)),
            // A confirmed booking no longer expires.
            'hold_expires_at' => null,
            'confirmed_at' => Carbon::now()->subHours(fake()->numberBetween(1, 24)),
            'confirmed_by' => User::factory(),
        ]);
    }

    public function completed(): static
    {
        return $this->confirmed()->state(fn (array $attributes): array => [
            'status' => Booking::STATUS_COMPLETED,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Booking::STATUS_REJECTED,
            'payment_reference' => self::gcashReference(),
            'payment_method' => Booking::PAYMENT_METHOD_GCASH,
            'payment_submitted_at' => Carbon::now()->subHours(fake()->numberBetween(2, 48)),
            'hold_expires_at' => null,
            'rejected_at' => Carbon::now()->subHours(fake()->numberBetween(1, 24)),
            'rejected_by' => User::factory(),
            'rejection_reason' => fake()->randomElement([
                'GCash reference number not found in the merchant statement.',
                'Amount received does not match the booking total.',
                'Duplicate reference — already used by another booking.',
                'No payment received within the verification window.',
            ]),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Booking::STATUS_EXPIRED,
            'hold_expires_at' => Carbon::now()->subMinutes(fake()->numberBetween(5, 600)),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Booking::STATUS_CANCELLED,
            'hold_expires_at' => null,
            'cancelled_at' => Carbon::now()->subHours(fake()->numberBetween(1, 48)),
        ]);
    }

    /**
     * A hold whose clock has already blown but which the release job has not
     * picked up yet — the exact row `scopeExpiredHolds` must find.
     */
    public function staleHold(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => fake()->randomElement(Booking::HOLDING_STATUSES),
            'hold_expires_at' => Carbon::now()->subMinutes(fake()->numberBetween(1, 240)),
        ]);
    }

    /**
     * GCash reference numbers are 13 digits.
     */
    private static function gcashReference(): string
    {
        return (string) random_int(1000000000000, 9999999999999);
    }
}
