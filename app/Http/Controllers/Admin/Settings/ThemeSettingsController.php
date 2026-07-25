<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateThemeSettingsRequest;
use App\Models\Setting;
use App\Services\AuditTrailService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Appearance settings.
 *
 * Only *token names* are persisted here. The actual colours are declared once
 * in `resources/css/app.css`, so a palette change can never introduce a hex
 * value that the rest of the design system does not know about.
 */
class ThemeSettingsController extends Controller
{
    private const TYPES = [
        'primary_palette' => 'string',
        'color_scheme' => 'string',
        'sidebar_collapsed' => 'boolean',
    ];

    private const DEFAULTS = [
        'primary_palette' => 'brand',
        'color_scheme' => 'light',
        'sidebar_collapsed' => false,
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditTrailService $audit,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/Theme', [
            'settings' => $this->stored(),
            'palettes' => UpdateThemeSettingsRequest::paletteOptions(),
            'colorSchemes' => UpdateThemeSettingsRequest::colorSchemeOptions(),
        ]);
    }

    public function update(UpdateThemeSettingsRequest $request): RedirectResponse
    {
        $before = $this->stored();

        $after = [
            'primary_palette' => (string) $request->input('primary_palette', ''),
            'color_scheme' => (string) $request->input('color_scheme', ''),
            'sidebar_collapsed' => $request->boolean('sidebar_collapsed'),
        ];

        $this->settings->setMany(Setting::GROUP_THEME, $after, self::TYPES);

        $this->recordAudit($before, $after);

        return to_route('admin.settings.theme')
            ->with('success', 'Appearance settings saved.');
    }

    /* --------------------------------------------------------------------- */
    /* Internals                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * Stored values, coerced to the exact types the Vue form binds to and
     * falling back to the shipped defaults when a key has never been written.
     *
     * @return array{primary_palette: string, color_scheme: string, sidebar_collapsed: bool}
     */
    private function stored(): array
    {
        $group = $this->settings->all(Setting::GROUP_THEME);

        $palette = is_string($group['primary_palette'] ?? null) ? $group['primary_palette'] : '';
        $scheme = is_string($group['color_scheme'] ?? null) ? $group['color_scheme'] : '';

        return [
            'primary_palette' => array_key_exists($palette, UpdateThemeSettingsRequest::PALETTES)
                ? $palette
                : self::DEFAULTS['primary_palette'],

            'color_scheme' => array_key_exists($scheme, UpdateThemeSettingsRequest::COLOR_SCHEMES)
                ? $scheme
                : self::DEFAULTS['color_scheme'],

            'sidebar_collapsed' => (bool) ($group['sidebar_collapsed'] ?? self::DEFAULTS['sidebar_collapsed']),
        ];
    }

    /**
     * @param  array<string, scalar>  $before
     * @param  array<string, scalar>  $after
     */
    private function recordAudit(array $before, array $after): void
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) === $value) {
                continue;
            }

            $old[$key] = $before[$key] ?? null;
            $new[$key] = $value;
        }

        if ($new === []) {
            return;
        }

        $this->audit->log(
            module: 'Settings',
            action: 'update',
            description: 'Updated appearance settings: '.implode(', ', array_keys($new)).'.',
            oldValues: $old,
            newValues: $new,
        );
    }
}
