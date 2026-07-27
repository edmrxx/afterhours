<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePaymentSettingsRequest;
use App\Models\Setting;
use App\Services\AuditTrailService;
use App\Services\PaymentMethodService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment settings — the values the public checkout renders.
 *
 * Every method on this screen comes from {@see PaymentMethodService::CATALOGUE}:
 * its QR slot, its storage types and the card the page renders are all derived
 * from that one list, so adding a method there needs no change here. The
 * catalogue's `required` method must stay configured; the rest are optional.
 *
 * Everything goes through {@see SettingsService}; the `settings` table is never
 * touched directly. The QR images themselves live on the `public` disk and only
 * their relative paths are stored, so moving domains or swapping disks does not
 * invalidate the row.
 */
class PaymentSettingsController extends Controller
{
    /** Where uploaded QR images land on the `public` disk. */
    private const QR_DIRECTORY = 'settings/payment';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditTrailService $audit,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/Payment', [
            'settings' => $this->present(),
            // The screen renders one card per entry rather than hard-coding a
            // section per method, so this is the whole contract it needs.
            'methods' => $this->methodProps(),
        ]);
    }

    /**
     * The catalogue in the shape the settings form consumes: field names it
     * binds to, the copy its card shows, and whether it may be left blank.
     *
     * @return list<array<string, mixed>>
     */
    private function methodProps(): array
    {
        $methods = [];

        foreach (PaymentMethodService::CATALOGUE as $method) {
            $prefix = $method['prefix'];
            $isMobile = $method['number_format'] === PaymentMethodService::NUMBER_MOBILE;

            $methods[] = [
                'key' => $method['key'],
                'label' => $method['label'],
                'prefix' => $prefix,
                'required' => $method['required'],
                'number_label' => $method['number_label'],
                'number_format' => $method['number_format'],
                'number_placeholder' => $isMobile ? '0917 123 4567' : '1234 5678 90',
                'name_hint' => $isMobile
                    ? sprintf('Must match the name registered to the %s wallet.', $method['label'])
                    : sprintf('Must match the name on the %s bank account.', $method['label']),
                'number_hint' => $isMobile
                    ? 'Philippine mobile number. +63 and 63 prefixes are accepted and normalised.'
                    : sprintf('%s is a bank, not a wallet — this is the account number, 6 to 20 digits, never a mobile number.', $method['label']),
                'qr_hint' => sprintf('Export the QR straight from the %s app so the code stays crisp. Minimum 200 x 200 pixels.', $method['label']),
            ];
        }

        return $methods;
    }

    public function update(UpdatePaymentSettingsRequest $request): RedirectResponse
    {
        $before = $this->stored();

        // Upload first: if the disk refuses a file we must not have written
        // half the group already. Both slots are resolved before anything is
        // committed, and a failure on the second unwinds the first — a rejected
        // save must not strand an unreferenced image on the disk.
        $previous = [];
        $resolved = [];
        $uploaded = [];

        foreach (PaymentMethodService::CATALOGUE as $method) {
            $field = $method['prefix'].'_qr';
            $key = $method['prefix'].'_qr_path';
            $label = $method['label'];

            $previous[$key] = $this->pathOf($before[$key] ?? null);

            if ($request->hasFile($field)) {
                $stored = $request->file($field)->store(self::QR_DIRECTORY, 'public');

                if (! is_string($stored) || $stored === '') {
                    Storage::disk('public')->delete($uploaded);

                    return back()
                        ->withInput()
                        ->with('error', "The {$label} QR image could not be saved. Check the storage permissions and try again.");
                }

                $resolved[$key] = $stored;
                $uploaded[] = $stored;

                continue;
            }

            $resolved[$key] = $request->boolean('remove_'.$field) ? null : $previous[$key];
        }

        $after = ['payment_instructions' => (string) $request->input('payment_instructions', '')];

        foreach (PaymentMethodService::CATALOGUE as $method) {
            $prefix = $method['prefix'];

            $after[$prefix.'_account_name'] = (string) $request->input($prefix.'_account_name', '');
            $after[$prefix.'_account_number'] = (string) $request->input($prefix.'_account_number', '');
            $after[$prefix.'_qr_path'] = $resolved[$prefix.'_qr_path'];
        }

        $this->settings->setMany(Setting::GROUP_PAYMENT, $after, PaymentMethodService::settingTypes());

        // Only bin the old images once the new paths are committed — a failed
        // write must never leave the checkout pointing at a deleted file. The
        // check is against every committed path, not just the slot's own, so
        // replacing one QR can never delete a file the other still points at.
        $live = array_values(array_filter($resolved, static fn (?string $path): bool => $path !== null));

        foreach ($previous as $old) {
            if ($old !== null && ! in_array($old, $live, true)) {
                Storage::disk('public')->delete($old);
            }
        }

        $this->recordAudit($before, $after);

        return to_route('admin.settings.payment')
            ->with('success', 'Payment settings saved. The checkout page is live with these details.');
    }

    /* --------------------------------------------------------------------- */
    /* Internals                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * The stored group, with every managed key present as a plain scalar.
     *
     * @return array<string, string|null>
     */
    private function stored(): array
    {
        $group = $this->settings->all(Setting::GROUP_PAYMENT);

        $out = [];

        foreach (array_keys(PaymentMethodService::settingTypes()) as $key) {
            $value = $group[$key] ?? null;
            $out[$key] = is_scalar($value) ? (string) $value : null;
        }

        return $out;
    }

    /**
     * Props for the settings page: stored values plus a resolved public URL
     * per QR, which the client cannot derive from a relative path.
     *
     * @return array<string, string|null>
     */
    private function present(): array
    {
        $stored = $this->stored();

        $props = ['payment_instructions' => $stored['payment_instructions'] ?? ''];

        foreach (PaymentMethodService::CATALOGUE as $method) {
            $prefix = $method['prefix'];
            $qr = $this->pathOf($stored[$prefix.'_qr_path'] ?? null);

            $props[$prefix.'_account_name'] = $stored[$prefix.'_account_name'] ?? '';
            $props[$prefix.'_account_number'] = $stored[$prefix.'_account_number'] ?? '';
            $props[$prefix.'_qr_path'] = $qr;
            $props[$prefix.'_qr_url'] = $this->urlOf($qr);
        }

        return $props;
    }

    /**
     * Treat a blank string the same as "no image".
     */
    private function pathOf(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function urlOf(?string $path): ?string
    {
        return $path !== null ? Storage::disk('public')->url($path) : null;
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
            description: 'Updated payment settings: '.implode(', ', array_keys($new)).'.',
            oldValues: $old,
            newValues: $new,
        );
    }
}
