<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the appearance settings.
 *
 * The brand colour is stored as a *design-token name*, never a hex value.
 * Tailwind v4 owns the actual colours in `resources/css/app.css`; letting an
 * admin type `#ff00ff` would fork the palette and break every hover, ring and
 * focus shade that the token ramp provides for free.
 */
class UpdateThemeSettingsRequest extends FormRequest
{
    /**
     * The curated palette. Every entry must be a colour ramp that really
     * exists in `@theme`, and only shades declared there may be referenced by
     * the UI (brand has 50–900; the status ramps have 50/500/600 and up).
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const PALETTES = [
        'brand' => [
            'label' => 'Violet',
            'description' => 'The PHEA default. Full 50–900 ramp.',
        ],
        'info' => [
            'label' => 'Ocean',
            'description' => 'Calm, corporate blue.',
        ],
        'success' => [
            'label' => 'Court green',
            'description' => 'Sporty and outdoorsy.',
        ],
        'warn' => [
            'label' => 'Sunset',
            'description' => 'Warm amber, high energy.',
        ],
        'danger' => [
            'label' => 'Clay',
            'description' => 'Bold red-clay accent.',
        ],
    ];

    /**
     * Light/dark handling is not wired to a renderer yet — this records the
     * intent so the preference survives until it is.
     *
     * @var array<string, string>
     */
    public const COLOR_SCHEMES = [
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'Match device',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'primary_palette' => ['required', 'string', Rule::in(array_keys(self::PALETTES))],
            'color_scheme' => ['required', 'string', Rule::in(array_keys(self::COLOR_SCHEMES))],
            'sidebar_collapsed' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'primary_palette.in' => 'Choose one of the available brand colours.',
            'color_scheme.in' => 'Choose light, dark, or match device.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'primary_palette' => 'brand colour',
            'color_scheme' => 'appearance',
            'sidebar_collapsed' => 'sidebar preference',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sidebar_collapsed' => $this->boolean('sidebar_collapsed'),
        ]);
    }

    /**
     * Palette catalogue in the shape the Vue page consumes.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function paletteOptions(): array
    {
        return array_values(array_map(
            static fn (string $token, array $meta): array => [
                'value' => $token,
                'label' => $meta['label'],
                'description' => $meta['description'],
            ],
            array_keys(self::PALETTES),
            self::PALETTES,
        ));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function colorSchemeOptions(): array
    {
        return array_values(array_map(
            static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys(self::COLOR_SCHEMES),
            self::COLOR_SCHEMES,
        ));
    }
}
