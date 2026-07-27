<?php

namespace Database\Factories;

use App\Models\Court;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Court>
 */
class CourtFactory extends Factory
{
    /** @var class-string<Court> */
    protected $model = Court::class;

    /** @var list<string> */
    private const LABELS = [
        'Center Court', 'Court A', 'Court B', 'Court C', 'Champions Court',
        'Rizal Court', 'Mabini Court', 'Bonifacio Court', 'Sunrise Court',
        'Bayanihan Court', 'Sampaguita Court', 'Narra Court',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The trailing sequence, not the label, is what keeps name/slug/code
        // unique — drawing labels with unique() would overflow past 12 courts.
        $sequence = fake()->unique()->numberBetween(1, 99999);
        $name = fake()->randomElement(self::LABELS).' '.$sequence;

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'code' => 'CRT-'.$sequence,
            // The court carries no rate of its own: its CATEGORY selects a row
            // of the rate table in settings (App\Services\PricingService).
            // Factories default to the ordinary kind so a test that says nothing
            // about pricing gets the full-size rates.
            'category' => Court::CATEGORY_NORMAL,
            'description' => fake()->sentence(14),
            'photo_path' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /** A court on the cheaper Skinny Court rate row. */
    public function skinny(): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => Court::CATEGORY_SKINNY,
        ]);
    }

    /** A court on the full-size rate row — the default, stated explicitly. */
    public function normal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => Court::CATEGORY_NORMAL,
        ]);
    }
}
