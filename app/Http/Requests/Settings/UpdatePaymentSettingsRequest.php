<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\PaymentMethodService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the payment settings screen.
 *
 * These values are the only thing standing between a customer and a successful
 * off-platform payment, so the rules are strict: every QR must be a real image
 * the browser can render, and each account number must match the shape its own
 * provider uses. BDO and GoTyme are banks whose account numbers are digits;
 * GCash is a wallet keyed on a Philippine mobile number, and validating them
 * alike would invite a customer to mistype either one.
 *
 * Every rule below is built from {@see PaymentMethodService::CATALOGUE} rather
 * than typed out per method, so a method added there is validated the moment it
 * exists instead of silently accepting anything until someone remembers this
 * file.
 *
 * The catalogue's `required` method stays required: it is what the club banks
 * into and must not become unconfigurable. Every other method is optional as a
 * whole but all-or-nothing within itself, so an admin cannot publish a name
 * with no number to pay it into.
 */
class UpdatePaymentSettingsRequest extends FormRequest
{
    /** A bank account number, separators already stripped. */
    private const BANK_PATTERN = '/^\d{6,20}$/';

    /** Normalised to 09XXXXXXXXX by prepareForValidation() before this runs. */
    private const MOBILE_PATTERN = '/^09\d{9}$/';

    /**
     * Route middleware already enforces `settings.update`; this is defence in
     * depth so the request cannot be replayed through another entry point.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'payment_instructions' => ['required', 'string', 'min:10', 'max:2000'],
        ];

        foreach (PaymentMethodService::CATALOGUE as $method) {
            $prefix = $method['prefix'];
            $name = $prefix.'_account_name';
            $number = $prefix.'_account_number';
            $pattern = $method['number_format'] === PaymentMethodService::NUMBER_MOBILE
                ? self::MOBILE_PATTERN
                : self::BANK_PATTERN;

            if ($method['required']) {
                $rules[$name] = ['required', 'string', 'min:2', 'max:120'];
                $rules[$number] = ['required', 'string', 'regex:'.$pattern];
            } else {
                // Optional, but half a method is worse than none: a name with no
                // number cannot be paid, and a number with no name gives the
                // customer nothing to check the transfer against.
                $rules[$name] = ['nullable', 'required_with:'.$number, 'string', 'min:2', 'max:120'];
                $rules[$number] = ['nullable', 'required_with:'.$name, 'string', 'regex:'.$pattern];
            }

            // Identical file rules for every QR — they arrive from the same
            // upload widget and render in the same place, so a weaker rule on
            // one would just be a second door into the same room.
            $rules[$prefix.'_qr'] = self::qrRules();
            $rules['remove_'.$prefix.'_qr'] = ['boolean'];
        }

        return $rules;
    }

    /**
     * Shared file rules for every QR upload.
     *
     * @return list<string>
     */
    private static function qrRules(): array
    {
        return [
            'nullable',
            'file',
            // `image` rejects SVG by default in Laravel 11+, which is what
            // we want — an SVG QR is a script-injection vector.
            'image',
            'mimetypes:image/png,image/jpeg,image/webp',
            'mimes:png,jpg,jpeg,webp',
            'max:2048',
            // A QR smaller than this is unscannable once a phone camera
            // has had its way with it.
            'dimensions:min_width=200,min_height=200,max_width=4000,max_height=4000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'payment_instructions.min' => 'Give customers enough detail to complete the payment.',
        ];

        foreach (PaymentMethodService::CATALOGUE as $method) {
            $prefix = $method['prefix'];
            $label = $method['label'];
            $qr = $prefix.'_qr';

            $messages[$prefix.'_account_number.regex'] = $method['number_format'] === PaymentMethodService::NUMBER_MOBILE
                ? sprintf('Enter a valid %s mobile number, for example 0917 123 4567.', $label)
                : sprintf('Enter the %s account number as 6 to 20 digits — it is a bank account number, not a mobile number.', $label);

            if (! $method['required']) {
                $messages[$prefix.'_account_name.required_with'] = sprintf(
                    'Add the %s account name so customers can check who they are paying.',
                    $label,
                );
                $messages[$prefix.'_account_number.required_with'] = sprintf(
                    'Add the %s account number, or clear the account name to leave %s switched off.',
                    $label,
                    $label,
                );
            }

            $messages[$qr.'.dimensions'] = 'The QR image must be at least 200 x 200 pixels so it stays scannable.';
            $messages[$qr.'.image'] = 'Upload the QR as a PNG, JPG or WebP image.';
            $messages[$qr.'.mimetypes'] = 'Upload the QR as a PNG, JPG or WebP image.';
            $messages[$qr.'.mimes'] = 'Upload the QR as a PNG, JPG or WebP image.';
            $messages[$qr.'.max'] = 'The QR image must be 2MB or smaller.';
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = ['payment_instructions' => 'payment instructions'];

        foreach (PaymentMethodService::CATALOGUE as $method) {
            $label = $method['label'];

            $attributes[$method['prefix'].'_account_name'] = $label.' account name';
            $attributes[$method['prefix'].'_account_number'] = $label.' '.mb_strtolower($method['number_label']);
            $attributes[$method['prefix'].'_qr'] = $label.' QR code';
        }

        return $attributes;
    }

    /**
     * Normalise each number to the one canonical shape its provider uses, so
     * `+63 917 123 4567`, `0917-123-4567` and `639171234567` all store alike,
     * and `1234 5678 90` loses only its separators.
     *
     * Input that is not recognisably the right shape is left untouched so the
     * regex rule reports a format error, rather than the normaliser silently
     * mangling it into a baffling "required" failure.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (PaymentMethodService::CATALOGUE as $method) {
            $prefix = $method['prefix'];
            $raw = $this->input($prefix.'_account_number');

            $merge['remove_'.$prefix.'_qr'] = $this->boolean('remove_'.$prefix.'_qr');

            if (! is_string($raw)) {
                continue;
            }

            $merge[$prefix.'_account_number'] = $method['number_format'] === PaymentMethodService::NUMBER_MOBILE
                ? (self::normalisePhilippineMobile($raw) ?? trim($raw))
                : self::normaliseBankAccountNumber($raw);
        }

        $this->merge($merge);
    }

    /**
     * Reduce a Philippine mobile number to `09XXXXXXXXX`, or null when the
     * input is not one.
     */
    public static function normalisePhilippineMobile(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        // 00 63 9XXXXXXXXX  →  strip the international access prefix.
        if (str_starts_with($digits, '0063')) {
            $digits = substr($digits, 4);
        }

        return match (true) {
            // 639XXXXXXXXX
            strlen($digits) === 12 && str_starts_with($digits, '639') => '0'.substr($digits, 2),
            // 09XXXXXXXXX
            strlen($digits) === 11 && str_starts_with($digits, '09') => $digits,
            // 9XXXXXXXXX (national significant number)
            strlen($digits) === 10 && str_starts_with($digits, '9') => '0'.$digits,
            default => null,
        };
    }

    /**
     * Drop the separators an admin naturally types into a bank account number
     * — `1234 5678 90`, `1234-5678-90` — and nothing else.
     *
     * Same reasoning as the mobile normaliser: anything that is not a bank
     * account number survives intact so the digits rule reports a format error,
     * rather than the normaliser stripping it down to an empty string and
     * turning it into a baffling "required" failure.
     */
    public static function normaliseBankAccountNumber(string $value): string
    {
        return preg_replace('/[\s-]+/', '', trim($value)) ?? trim($value);
    }
}
