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
    /** An account number shaped like a bank's — digits, never a phone number. */
    public const NUMBER_BANK = 'bank';

    /** A wallet keyed on a Philippine mobile number. */
    public const NUMBER_MOBILE = 'mobile';

    /**
     * Every method the system knows how to publish.
     *
     * This is THE list. The settings screen renders a card per entry, the
     * validator builds its rules from it, the controller derives its QR slots
     * and storage types from it, and the seeder creates its keys from it — so
     * adding a fourth method is one entry here plus a constant on Booking,
     * not a hunt through five files that each kept their own copy.
     *
     * Order is deliberate and not alphabetical: it is the order checkout
     * renders, and BDO leads because it is the account the club banks into.
     *
     * `required` marks the method that must stay configured — without one, an
     * admin could save a checkout with nowhere to send money. Only BDO carries
     * it; the rest are opt-in and all-or-nothing within themselves.
     *
     * `number_format` is the real distinction between these: BDO and GoTyme are
     * banks whose account numbers are digits, GCash is a wallet keyed on a
     * Philippine mobile number. Validating them alike would invite a customer
     * to mistype either one.
     *
     * @var list<array{
     *     key: string, label: string, prefix: string,
     *     number_label: string, number_format: string, required: bool,
     * }>
     */
    public const CATALOGUE = [
        [
            'key' => Booking::PAYMENT_METHOD_BDO,
            'label' => 'BDO',
            'prefix' => 'bdo',
            'number_label' => 'Account number',
            'number_format' => self::NUMBER_BANK,
            'required' => true,
        ],
        [
            'key' => Booking::PAYMENT_METHOD_GOTYME,
            'label' => 'GoTyme',
            'prefix' => 'gotyme',
            'number_label' => 'Account number',
            'number_format' => self::NUMBER_BANK,
            'required' => false,
        ],
        [
            'key' => Booking::PAYMENT_METHOD_GCASH,
            'label' => 'GCash',
            'prefix' => 'gcash',
            'number_label' => 'GCash number',
            'number_format' => self::NUMBER_MOBILE,
            'required' => false,
        ],
    ];

    /**
     * Every settings key the payment group owns, in catalogue order, followed
     * by the shared instruction block.
     *
     * @return array<string, string>  key => storage type
     */
    public static function settingTypes(): array
    {
        $types = [];

        foreach (self::CATALOGUE as $method) {
            $types[$method['prefix'].'_qr_path'] = 'image';
            $types[$method['prefix'].'_account_name'] = 'string';
            $types[$method['prefix'].'_account_number'] = 'string';
        }

        $types['payment_instructions'] = 'text';

        return $types;
    }

    /** The one method that may never be left unconfigured. */
    public static function requiredMethod(): ?array
    {
        foreach (self::CATALOGUE as $method) {
            if ($method['required']) {
                return $method;
            }
        }

        return null;
    }

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * The published methods, in catalogue order, in the shape checkout consumes.
     *
     * May legitimately be empty — that is the fresh-install state, and it is
     * what drives the "payment details are not published yet" panel.
     *
     * @return list<array<string, string|null>>
     */
    public function published(): array
    {
        $methods = [];

        foreach (self::CATALOGUE as $method) {
            $prefix = $method['prefix'];
            $qrUrl = $this->publicUrl($this->stringSetting($prefix.'_qr_path'));
            $accountNumber = $this->stringSetting($prefix.'_account_number');

            if ($qrUrl === null && $accountNumber === null) {
                continue;
            }

            $methods[] = [
                'key' => $method['key'],
                'label' => $method['label'],
                'qr_url' => $qrUrl,
                'account_name' => $this->stringSetting($prefix.'_account_name'),
                'account_number' => $accountNumber,
                'account_number_label' => $method['number_label'],
                'scan_hint' => sprintf('Scan in the %s app', $method['label']),
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
