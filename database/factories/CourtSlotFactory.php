<?php

namespace Database\Factories;

use App\Models\Court;
use App\Models\CourtSlot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CourtSlot>
 */
class CourtSlotFactory extends Factory
{
    /** @var class-string<CourtSlot> */
    protected $model = CourtSlot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // 06:00 through 21:00, one-hour blocks — a realistic operating day.
        $hour = fake()->numberBetween(6, 21);

        return [
            'court_id' => Court::factory(),
            'slot_date' => Carbon::today()->addDays(fake()->numberBetween(1, 14))->toDateString(),
            'start_time' => sprintf('%02d:00:00', $hour),
            'end_time' => sprintf('%02d:00:00', ($hour + 1) % 24),
            'price' => fake()->randomElement([250.00, 300.00, 350.00, 400.00, 450.00, 500.00]),
            'status' => CourtSlot::STATUS_AVAILABLE,
            'held_booking_id' => null,
        ];
    }

    public function forCourt(Court|int $court): static
    {
        // Pricing is club-wide now, so a slot's price is no longer a property of
        // its court — it keeps the definition's default (or an explicit override).
        return $this->state(fn (array $attributes): array => [
            'court_id' => $court instanceof Court ? $court->getKey() : $court,
        ]);
    }

    public function onDate(Carbon|string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'slot_date' => Carbon::parse((string) $date)->toDateString(),
        ]);
    }

    public function atHour(int $hour): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_time' => sprintf('%02d:00:00', $hour),
            'end_time' => sprintf('%02d:00:00', ($hour + 1) % 24),
        ]);
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CourtSlot::STATUS_AVAILABLE,
            'held_booking_id' => null,
        ]);
    }

    public function held(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CourtSlot::STATUS_HELD,
        ]);
    }

    public function booked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CourtSlot::STATUS_BOOKED,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CourtSlot::STATUS_BLOCKED,
            'held_booking_id' => null,
        ]);
    }

    /**
     * A slot in the past — useful for asserting `upcoming` filters it out.
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes): array => [
            'slot_date' => Carbon::today()->subDays(fake()->numberBetween(1, 14))->toDateString(),
        ]);
    }
}
