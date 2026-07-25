<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePaymentSettingsRequest;
use App\Models\Setting;
use App\Services\AuditTrailService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment settings — the values the public checkout renders.
 *
 * Two methods share this screen: GCash, which is required because it is the
 * live method today, and GoTyme, which is entirely optional. Everything here
 * goes through {@see SettingsService}; the `settings` table is never touched
 * directly. The QR images themselves live on the `public` disk and only their
 * relative paths are stored, so moving domains or swapping disks does not
 * invalidate the row.
 */
class PaymentSettingsController extends Controller
{
    /** Where uploaded QR images land on the `public` disk. */
    private const QR_DIRECTORY = 'settings/payment';

    /**
     * The two QR slots: upload field => [settings key, label for error copy].
     * Both flow through the same code path so neither can drift.
     */
    private const QR_FIELDS = [
        'gcash_qr' => ['gcash_qr_path', 'GCash'],
        'gotyme_qr' => ['gotyme_qr_path', 'GoTyme'],
    ];

    /** Declared storage type per key — never inferred, so a null stays an image. */
    private const TYPES = [
        'gcash_qr_path' => 'image',
        'gcash_account_name' => 'string',
        'gcash_account_number' => 'string',
        'gotyme_qr_path' => 'image',
        'gotyme_account_name' => 'string',
        'gotyme_account_number' => 'string',
        'payment_instructions' => 'text',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditTrailService $audit,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/Payment', [
            'settings' => $this->present(),
        ]);
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

        foreach (self::QR_FIELDS as $field => [$key, $label]) {
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

        $after = [
            'gcash_account_name' => (string) $request->input('gcash_account_name', ''),
            'gcash_account_number' => (string) $request->input('gcash_account_number', ''),
            'gotyme_account_name' => (string) $request->input('gotyme_account_name', ''),
            'gotyme_account_number' => (string) $request->input('gotyme_account_number', ''),
            'payment_instructions' => (string) $request->input('payment_instructions', ''),
            'gcash_qr_path' => $resolved['gcash_qr_path'],
            'gotyme_qr_path' => $resolved['gotyme_qr_path'],
        ];

        $this->settings->setMany(Setting::GROUP_PAYMENT, $after, self::TYPES);

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

        foreach (array_keys(self::TYPES) as $key) {
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
        $gcashQr = $this->pathOf($stored['gcash_qr_path'] ?? null);
        $gotymeQr = $this->pathOf($stored['gotyme_qr_path'] ?? null);

        return [
            'gcash_account_name' => $stored['gcash_account_name'] ?? '',
            'gcash_account_number' => $stored['gcash_account_number'] ?? '',
            'gcash_qr_path' => $gcashQr,
            'gcash_qr_url' => $this->urlOf($gcashQr),
            'gotyme_account_name' => $stored['gotyme_account_name'] ?? '',
            'gotyme_account_number' => $stored['gotyme_account_number'] ?? '',
            'gotyme_qr_path' => $gotymeQr,
            'gotyme_qr_url' => $this->urlOf($gotymeQr),
            'payment_instructions' => $stored['payment_instructions'] ?? '',
        ];
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
