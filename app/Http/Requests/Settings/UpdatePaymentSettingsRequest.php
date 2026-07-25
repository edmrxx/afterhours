<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the payment settings screen.
 *
 * These values are the only thing standing between a customer and a successful
 * off-platform payment, so the rules are strict: every QR must be a real image
 * the browser can render, and each account number must match the shape its own
 * provider uses — GCash is a wallet keyed on a Philippine mobile number, GoTyme
 * is a bank and its account number is nothing of the sort.
 *
 * GCash stays required: it is the live method and must not become
 * unconfigurable. GoTyme is optional as a whole but all-or-nothing within
 * itself, so an admin cannot publish a name with no number to pay it into.
 */
class UpdatePaymentSettingsRequest extends FormRequest
{
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
        return [
            'gcash_account_name' => ['required', 'string', 'min:2', 'max:120'],

            // Normalised to 09XXXXXXXXX by prepareForValidation() before this runs.
            'gcash_account_number' => ['required', 'string', 'regex:/^09\d{9}$/'],

            // GoTyme is optional, but half a method is worse than none: a name
            // with no number cannot be paid, and a number with no name gives
            // the customer nothing to check the transfer against.
            'gotyme_account_name' => ['nullable', 'required_with:gotyme_account_number', 'string', 'min:2', 'max:120'],

            // A GoTyme account number is a BANK account number, not a mobile
            // number — never the 09XXXXXXXXX rule above. Separators are already
            // stripped by prepareForValidation() before this runs.
            'gotyme_account_number' => ['nullable', 'required_with:gotyme_account_name', 'string', 'regex:/^\d{6,20}$/'],

            'payment_instructions' => ['required', 'string', 'min:10', 'max:2000'],

            'gcash_qr' => self::qrRules(),

            // Identical rules to the GCash QR, including the SVG rejection —
            // the file arrives from the same upload widget and is rendered in
            // the same place, so a weaker rule here would just be a second door
            // into the same room.
            'gotyme_qr' => self::qrRules(),

            'remove_gcash_qr' => ['boolean'],
            'remove_gotyme_qr' => ['boolean'],
        ];
    }

    /**
     * Shared file rules for both QR uploads.
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
        return [
            'gcash_account_number.regex' => 'Enter a valid GCash mobile number, for example 0917 123 4567.',
            'gcash_qr.dimensions' => 'The QR image must be at least 200 x 200 pixels so it stays scannable.',
            'gcash_qr.image' => 'Upload the QR as a PNG, JPG or WebP image.',
            'gcash_qr.mimetypes' => 'Upload the QR as a PNG, JPG or WebP image.',
            'gcash_qr.mimes' => 'Upload the QR as a PNG, JPG or WebP image.',
            'gcash_qr.max' => 'The QR image must be 2MB or smaller.',
            'gotyme_account_name.required_with' => 'Add the GoTyme account name so customers can check who they are paying.',
            'gotyme_account_number.required_with' => 'Add the GoTyme account number, or clear the account name to leave GoTyme switched off.',
            'gotyme_account_number.regex' => 'Enter the GoTyme account number as 6 to 20 digits — it is a bank account number, not a mobile number.',
            'gotyme_qr.dimensions' => 'The QR image must be at least 200 x 200 pixels so it stays scannable.',
            'gotyme_qr.image' => 'Upload the QR as a PNG, JPG or WebP image.',
            'gotyme_qr.mimetypes' => 'Upload the QR as a PNG, JPG or WebP image.',
            'gotyme_qr.mimes' => 'Upload the QR as a PNG, JPG or WebP image.',
            'gotyme_qr.max' => 'The QR image must be 2MB or smaller.',
            'payment_instructions.min' => 'Give customers enough detail to complete the payment.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'gcash_account_name' => 'GCash account name',
            'gcash_account_number' => 'GCash number',
            'gotyme_account_name' => 'GoTyme account name',
            'gotyme_account_number' => 'GoTyme account number',
            'payment_instructions' => 'payment instructions',
            'gcash_qr' => 'GCash QR code',
            'gotyme_qr' => 'GoTyme QR code',
        ];
    }

    /**
     * Accept whatever the admin pasted — `+63 917 123 4567`, `0917-123-4567`,
     * `639171234567` — and store one canonical shape.
     *
     * Input that is not recognisably a Philippine mobile number is left
     * untouched so the regex rule reports a format error rather than the
     * normaliser silently mangling it into a "required" failure.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('gcash_account_number');
        $gotyme = $this->input('gotyme_account_number');

        $this->merge([
            'remove_gcash_qr' => $this->boolean('remove_gcash_qr'),
            'remove_gotyme_qr' => $this->boolean('remove_gotyme_qr'),
            'gcash_account_number' => is_string($raw)
                ? (self::normalisePhilippineMobile($raw) ?? trim($raw))
                : $raw,
            'gotyme_account_number' => is_string($gotyme)
                ? self::normaliseBankAccountNumber($gotyme)
                : $gotyme,
        ]);
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
