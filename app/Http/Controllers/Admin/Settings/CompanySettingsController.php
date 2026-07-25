<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCompanySettingsRequest;
use App\Models\Setting;
use App\Services\AuditTrailService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company profile — the identity shown in the public header and every email.
 */
class CompanySettingsController extends Controller
{
    /** Where uploaded logos land on the `public` disk. */
    private const LOGO_DIRECTORY = 'settings/company';

    /** Declared storage type per key. */
    private const TYPES = [
        'company_name' => 'string',
        'company_legal_name' => 'string',
        'company_email' => 'string',
        'company_phone' => 'string',
        'company_address' => 'text',
        // The operating window is structured now; `operating_hours` is the human
        // label derived from it on save (see operatingHoursLabel()), kept as its
        // own row so the header preview and any future reader need no change.
        'operating_opening_time' => 'string',
        'operating_closing_time' => 'string',
        'operating_hours' => 'string',
        'company_logo_path' => 'image',
    ];

    /**
     * The mail layout and mailables read the short keys (`company.name`,
     * `company.email`, …) while the settings table is seeded with the
     * `company_*` spelling. Rather than reach into a peer's file, the four
     * overlapping values are mirrored to both spellings on every save so the
     * templates and this screen can never drift apart.
     *
     * @var array<string, string> canonical key => alias key
     */
    private const MAIL_ALIASES = [
        'company_name' => 'name',
        'company_email' => 'email',
        'company_phone' => 'phone',
        'company_address' => 'address',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditTrailService $audit,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/Company', [
            'settings' => $this->present(),
        ]);
    }

    public function update(UpdateCompanySettingsRequest $request): RedirectResponse
    {
        $before = $this->stored();
        $previousLogo = $this->pathOf($before['company_logo_path'] ?? null);

        $logoPath = $previousLogo;

        if ($request->hasFile('company_logo')) {
            $stored = $request->file('company_logo')->store(self::LOGO_DIRECTORY, 'public');

            if (! is_string($stored) || $stored === '') {
                return back()
                    ->withInput()
                    ->with('error', 'The logo could not be saved. Check the storage permissions and try again.');
            }

            $logoPath = $stored;
        } elseif ($request->boolean('remove_company_logo')) {
            $logoPath = null;
        }

        // Optional at the request layer (see UpdateCompanySettingsRequest) so a
        // client that doesn't know about these two fields yet — an older cached
        // build of this screen — can still save the rest of the form. Falling
        // back to what's ALREADY stored, not a hardcoded 07:00/02:00, means an
        // omitted field preserves whatever the operator last set rather than
        // silently resetting it on every unrelated save.
        $opening = (string) $request->input('operating_opening_time', $before['operating_opening_time'] ?: '07:00');
        $closing = (string) $request->input('operating_closing_time', $before['operating_closing_time'] ?: '02:00');

        $after = [
            'company_name' => (string) $request->input('company_name', ''),
            'company_legal_name' => (string) $request->input('company_legal_name', ''),
            'company_email' => (string) $request->input('company_email', ''),
            'company_phone' => (string) $request->input('company_phone', ''),
            'company_address' => (string) $request->input('company_address', ''),
            'operating_opening_time' => $opening,
            'operating_closing_time' => $closing,
            // Derived here, never accepted from the form, so the label and the
            // structured window can never disagree.
            'operating_hours' => $this->operatingHoursLabel($opening, $closing),
            'company_logo_path' => $logoPath,
        ];

        $aliases = [];
        $aliasTypes = [];

        foreach (self::MAIL_ALIASES as $canonical => $alias) {
            $aliases[$alias] = $after[$canonical];
            $aliasTypes[$alias] = self::TYPES[$canonical];
        }

        $this->settings->setMany(
            Setting::GROUP_COMPANY,
            [...$after, ...$aliases],
            [...self::TYPES, ...$aliasTypes],
        );

        if ($previousLogo !== null && $previousLogo !== $logoPath) {
            Storage::disk('public')->delete($previousLogo);
        }

        $this->recordAudit($before, $after);

        return to_route('admin.settings.company')
            ->with('success', 'Company details saved.');
    }

    /* --------------------------------------------------------------------- */
    /* Internals                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * @return array<string, string|null>
     */
    private function stored(): array
    {
        $group = $this->settings->all(Setting::GROUP_COMPANY);

        $out = [];

        foreach (array_keys(self::TYPES) as $key) {
            $value = $group[$key] ?? null;
            $out[$key] = is_scalar($value) ? (string) $value : null;
        }

        return $out;
    }

    /**
     * @return array<string, string|null>
     */
    private function present(): array
    {
        $stored = $this->stored();
        $logoPath = $this->pathOf($stored['company_logo_path']);

        return [
            'company_name' => $stored['company_name'] ?? '',
            'company_legal_name' => $stored['company_legal_name'] ?? '',
            'company_email' => $stored['company_email'] ?? '',
            'company_phone' => $stored['company_phone'] ?? '',
            'company_address' => $stored['company_address'] ?? '',
            'operating_opening_time' => ($stored['operating_opening_time'] ?? '') ?: '07:00',
            'operating_closing_time' => ($stored['operating_closing_time'] ?? '') ?: '02:00',
            // Read-only mirror for the header preview; the screen recomputes it
            // live as the times change, but this is the saved truth.
            'operating_hours' => $stored['operating_hours'] ?? '',
            'company_logo_path' => $logoPath,
            'company_logo_url' => $logoPath !== null ? Storage::disk('public')->url($logoPath) : null,
        ];
    }

    private function pathOf(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Format the structured window into the human label the header preview
     * shows, e.g. "7:00 AM – 2:00 AM daily". The JS on the Company screen builds
     * the exact same string live, so what the operator previews is what is saved.
     */
    private function operatingHoursLabel(string $opening, string $closing): string
    {
        return sprintf('%s – %s daily', $this->to12h($opening), $this->to12h($closing));
    }

    /** "16:00" → "4:00 PM". A malformed value is returned unchanged. */
    private function to12h(string $hhmm): string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})/', trim($hhmm), $m)) {
            return $hhmm;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $suffix = $hour < 12 ? 'AM' : 'PM';
        $twelve = $hour % 12 === 0 ? 12 : $hour % 12;

        return sprintf('%d:%02d %s', $twelve, $minute, $suffix);
    }

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
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
            description: 'Updated company settings: '.implode(', ', array_keys($new)).'.',
            oldValues: $old,
            newValues: $new,
        );
    }
}
