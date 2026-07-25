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
            'description' => fake()->sentence(14),
            'photo_path' => null,
            // Pricing is club-wide now (App\Services\PricingService), so a court
            // carries no rate of its own.
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
}
