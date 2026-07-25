<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Which wallets checkout may currently offer, derived from the payment settings.
 *
 * This derivation lives in exactly one place because two independently-edited
 * layers have to agree on it:
 *
 *  - {@see \App\Http\Controllers\PublicSite\PublicBookingController} renders a
 *    card (and a chooser) per published method;
 *  - {@see \App\Http\Requests\PublicSite\SubmitPaymentRequest} decides whether
 *    "which app did you pay with?" is a question the page was even able to ask.
 *
 * When those two disagreed the guest got a required-field error naming a
 * chooser that had never been rendered, with no control anywhere on the page
 * that could satisfy it. Deriving both from this class makes that state
 * unrepresentable.
 *
 * A method is published only when it carries something the guest can act on —
 * a QR to scan or a number to type. A half-configured method (an account name
 * and nothing else) is dropped rather than rendered as an empty card asking
 * the customer to send money nowhere. GoTyme being absent is therefore not a
 * special case: it simply never enters the list.
 */
class PaymentMethodService
{
    /**
     * Settings prefix, display label and number label per method.
     *
     * Order is deliberate and not alphabetical: GCash is the method the site
     * has run on since launch and the one most guests reach for, and the
     * contract fixes it as the first entry of the checkout payload.
     *
     * GoTyme is a bank, not a wallet — its account number is not a mobile
     * number, so it gets the neutral field label.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const CATALOGUE = [
        [Booking::PAYMENT_METHOD_GCASH, 'GCash', 'gcash', 'GCash number'],
        [Booking::PAYMENT_METHOD_GOTYME, 'GoTyme', 'gotyme', 'Account number'],
    ];

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * The published methods, GCash first, in the shape the checkout consumes.
     *
     * May legitimately be empty — that is the fresh-install state, and it is
     * what drives the "payment details are not published yet" panel.
     *
     * @return list<array<string, string|null>>
     */
    public function published(): array
    {
        $methods = [];

        foreach (self::CATALOGUE as [$key, $label, $prefix, $numberLabel]) {
            $qrUrl = $this->publicUrl($this->stringSetting($prefix.'_qr_path'));
            $accountNumber = $this->stringSetting($prefix.'_account_number');

            if ($qrUrl === null && $accountNumber === null) {
                continue;
            }

            $methods[] = [
                'key' => $key,
                'label' => $label,
                'qr_url' => $qrUrl,
                'account_name' => $this->stringSetting($prefix.'_account_name'),
                'account_number' => $accountNumber,
                'account_number_label' => $numberLabel,
                'scan_hint' => sprintf('Scan in the %s app', $label),
            ];
        }

        return $methods;
    }

    /**
     * Just the keys, for callers that only need to know what may be chosen.
     *
     * @return list<string>
     */
    public function publishedKeys(): array
    {
        return array_values(array_map(
            static fn (array $method): string => (string) $method['key'],
            $this->published(),
        ));
    }

    /**
     * Is there anything at all for the guest to pay into?
     *
     * False on a site whose payment settings were never filled in, where the
     * checkout shows the "contact us and we will take your payment directly"
     * panel and no chooser. A reference typed after a phoned-in payment is
     * still worth recording, so the method simply goes unrecorded there.
     */
    public function hasPublished(): bool
    {
        return $this->published() !== [];
    }

    /**
     * Trimmed setting, or null when it is blank.
     *
     * SettingsService already collapses '' to the default for every type but
     * `text`; this also drops a value that is nothing but whitespace.
     */
    private function stringSetting(string $key): ?string
    {
        $value = $this->settings->get(Setting::GROUP_PAYMENT, $key);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * Relative disk paths become URLs; an already-absolute URL is left alone so
     * a site that pastes in a hosted QR still works.
     */
    private function publicUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
