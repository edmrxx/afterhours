<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtSlot;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Populates a believable dataset: four courts, roughly a fortnight of hourly
 * slots either side of today, and bookings spread across every status so the
 * dashboard charts, filters and empty-vs-populated states all have something
 * real to render.
 *
 * NEVER runs by default — DatabaseSeeder gates it behind local + SEED_DEMO.
 */
class DemoDataSeeder extends Seeder
{
    /** Hourly slots from 06:00 to 21:00 inclusive of the 21:00–22:00 block. */
    private const OPEN_HOUR = 6;

    private const CLOSE_HOUR = 22;

    private const DAYS_BACK = 4;

    private const DAYS_FORWARD = 14;

    /**
     * Pricing is club-wide now (App\Services\PricingService), so the courts
     * carry no rate of their own — every slot is priced by the global non-peak
     * / peak table, exactly as a real generator run would resolve it.
     *
     * @var list<array{name: string, code: string, sort: int, description: string}>
     */
    private const COURTS = [
        [
            'name' => 'Center Court',
            'code' => 'PHEA-C1',
            'sort' => 1,
            'description' => 'Championship indoor court with tournament-grade acrylic surface, LED lighting and covered spectator seating.',
        ],
        [
            'name' => 'Rizal Court',
            'code' => 'PHEA-C2',
            'sort' => 2,
            'description' => 'Indoor court with cushioned flooring and air-conditioned rest area. Ideal for league night doubles.',
        ],
        [
            'name' => 'Bonifacio Court',
            'code' => 'PHEA-C3',
            'sort' => 3,
            'description' => 'Semi-covered court with natural ventilation. The regulars\' favourite for early morning games.',
        ],
        [
            'name' => 'Sampaguita Court',
            'code' => 'PHEA-C4',
            'sort' => 4,
            'description' => 'Outdoor court under shade netting. Best value for casual play and beginner clinics.',
        ],
    ];

    public function run(): void
    {
        $courts = $this->seedCourts();
        $slotCount = $this->seedSlots($courts);
        $verifier = $this->demoStaffUser();
        $bookingCount = $this->seedBookings($courts, $verifier);

        $this->command?->info(sprintf(
            'Demo data: %d courts, %d slots, %d bookings.',
            $courts->count(),
            $slotCount,
            $bookingCount,
        ));
    }

    /**
     * @return Collection<int, Court>
     */
    private function seedCourts(): Collection
    {
        return collect(self::COURTS)->map(function (array $spec): Court {
            $court = Court::withTrashed()->firstOrNew(['code' => $spec['code']]);

            $court->fill([
                'name' => $spec['name'],
                'description' => $spec['description'],
                'is_active' => true,
                'sort_order' => $spec['sort'],
            ]);

            if (blank($court->slug)) {
                $court->slug = Court::uniqueSlug($spec['name'], $court->exists ? $court->getKey() : null);
            }

            $court->save();

            if ($court->trashed()) {
                $court->restore();
            }

            return $court;
        })->values();
    }

    /**
     * Bulk-upsert hourly slots. The (court_id, slot_date, start_time) unique
     * index makes re-running a no-op rather than a duplicate storm.
     *
     * @param  Collection<int, Court>  $courts
     */
    private function seedSlots(Collection $courts): int
    {
        $start = Carbon::today()->subDays(self::DAYS_BACK);
        $end = Carbon::today()->addDays(self::DAYS_FORWARD);
        $now = Carbon::now();
        $pricing = app(\App\Services\PricingService::class);
        $written = 0;

        foreach ($courts as $court) {
            $rows = [];

            for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
                // Weekends carry a modest surcharge — makes the revenue report
                // show variation instead of a flat line. The time-of-day pricing
                // itself comes from the global non-peak/peak table, exactly as a
                // real generator run would resolve it.
                $weekendPremium = $date->isWeekend() ? 50.00 : 0.00;

                for ($hour = self::OPEN_HOUR; $hour < self::CLOSE_HOUR; $hour++) {
                    $startTime = sprintf('%02d:00:00', $hour);

                    $rows[] = [
                        'court_id' => $court->getKey(),
                        'slot_date' => $date->toDateString(),
                        'start_time' => $startTime,
                        'end_time' => sprintf('%02d:00:00', $hour + 1),
                        'price' => round($pricing->rateFor($startTime) + $weekendPremium, 2),
                        'status' => CourtSlot::STATUS_AVAILABLE,
                        'held_booking_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                CourtSlot::query()->upsert(
                    $chunk,
                    ['court_id', 'slot_date', 'start_time'],
                    ['end_time', 'price', 'updated_at'],
                );
                $written += count($chunk);
            }
        }

        return $written;
    }

    /**
     * A Staff account so confirmed/rejected bookings have a plausible actor
     * and the users screen shows more than the lone admin.
     */
    private function demoStaffUser(): User
    {
        $staff = User::withTrashed()->firstOrNew(['username' => 'staff']);

        if (! $staff->exists) {
            $staff->fill([
                'name' => 'Grace Villanueva',
                'email' => 'staff@phea.test',
                'phone' => '09171234567',
                'password' => Hash::make('staff123'),
                'is_active' => true,
                'must_change_password' => true,
            ]);
            $staff->save();
        }

        if ($staff->trashed()) {
            $staff->restore();
        }

        if (! $staff->hasRole(RolePermissionSeeder::ROLE_STAFF)) {
            $staff->assignRole(RolePermissionSeeder::ROLE_STAFF);
        }

        return $staff;
    }

    /**
     * Spread bookings across the whole state machine, keeping each slot's
     * status consistent with the booking that owns it.
     *
     * @param  Collection<int, Court>  $courts
     */
    private function seedBookings(Collection $courts, User $verifier): int
    {
        if (Booking::query()->exists()) {
            $this->command?->warn('Bookings already present — skipping demo booking generation.');

            return 0;
        }

        // seedCourts() builds a plain Support collection via collect()->map(),
        // not an Eloquent collection, so modelKeys() is not available here.
        $courtIds = $courts->pluck('id')->all();

        // Past slots become history (completed / rejected / expired); future
        // slots carry the live pipeline (holds, pending checks, confirmations).
        $pastSlots = CourtSlot::query()
            ->whereIn('court_id', $courtIds)
            ->past()
            ->inRandomOrder()
            ->limit(40)
            ->get();

        $futureSlots = CourtSlot::query()
            ->whereIn('court_id', $courtIds)
            ->upcoming()
            ->inRandomOrder()
            ->limit(55)
            ->get();

        $plan = [
            // [pool key, count, factory state, resulting slot status]
            ['past', 22, 'completed', CourtSlot::STATUS_BOOKED],
            ['past', 6, 'rejected', CourtSlot::STATUS_AVAILABLE],
            ['past', 8, 'expired', CourtSlot::STATUS_AVAILABLE],
            ['future', 18, 'confirmed', CourtSlot::STATUS_BOOKED],
            ['future', 12, 'pendingVerification', CourtSlot::STATUS_HELD],
            ['future', 9, 'awaitingPayment', CourtSlot::STATUS_HELD],
            ['future', 5, 'cancelled', CourtSlot::STATUS_AVAILABLE],
        ];

        $pools = ['past' => $pastSlots, 'future' => $futureSlots];
        $cursors = ['past' => 0, 'future' => 0];
        $created = 0;

        foreach ($plan as [$poolKey, $count, $state, $slotStatus]) {
            for ($i = 0; $i < $count; $i++) {
                $slot = $pools[$poolKey]->get($cursors[$poolKey]++);

                if (! $slot instanceof CourtSlot) {
                    break;
                }

                // Pin the actor to the demo staff member rather than letting
                // the factory mint a throwaway user per booking.
                $overrides = [
                    // Backdate creation so the dashboard trend chart has a
                    // curve rather than a single spike.
                    'created_at' => $this->bookingCreatedAt($slot),
                ];

                if (in_array($state, ['confirmed', 'completed'], true)) {
                    $overrides['confirmed_by'] = $verifier->getKey();
                } elseif ($state === 'rejected') {
                    $overrides['rejected_by'] = $verifier->getKey();
                }

                /** @var Booking $booking */
                $booking = Booking::factory()
                    ->forSlot($slot)
                    ->{$state}()
                    ->create($overrides);

                $slot->forceFill([
                    'status' => $slotStatus,
                    'held_booking_id' => $slotStatus === CourtSlot::STATUS_HELD ? $booking->getKey() : null,
                ])->saveQuietly();

                $created++;
            }
        }

        return $created;
    }

    private function bookingCreatedAt(CourtSlot $slot): Carbon
    {
        $reference = $slot->startsAt();
        $created = $reference->copy()->subDays(random_int(0, 10))->subHours(random_int(0, 12));

        // Never claim a booking was made in the future.
        return $created->greaterThan(Carbon::now())
            ? Carbon::now()->subMinutes(random_int(5, 600))
            : $created;
    }
}
