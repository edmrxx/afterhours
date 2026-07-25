<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** @var class-string<User> */
    protected $model = User::class;

    /**
     * Hashed once per process — bcrypt at 12 rounds is far too slow to run
     * for every factory row.
     */
    protected static ?string $password = null;

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
        $first = fake()->randomElement(self::FIRST_NAMES);
        $last = fake()->randomElement(self::LAST_NAMES);
        $name = $first.' '.$last;

        return [
            'name' => $name,
            'username' => Str::lower(Str::slug($first.'.'.Str::of($last)->replace(' ', ''), '.')).fake()->unique()->numberBetween(100, 9999),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone' => self::philippineMobile(),
            'password' => static::$password ??= Hash::make('password'),
            'avatar_path' => null,
            'is_active' => true,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * 09xx xxx xxxx — the only mobile format Philippine gateways accept.
     */
    public static function philippineMobile(): string
    {
        $prefixes = ['0917', '0918', '0919', '0920', '0921', '0927', '0928', '0929',
            '0935', '0936', '0939', '0945', '0956', '0966', '0977', '0995', '0997'];

        return $prefixes[array_rand($prefixes)].str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes): array => [
            'must_change_password' => true,
        ]);
    }
}
