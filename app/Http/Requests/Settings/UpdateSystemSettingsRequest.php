<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the booking hold-time settings, the booking code prefix, and
 * the global court pricing table.
 *
 * `booking_verification_hold_minutes` may never be shorter than
 * `booking_hold_minutes` — the verification window is meant to extend the
 * initial hold, not shrink it.
 *
 * The prefix is deliberately restricted to letters/digits — it is joined to
 * the random body with a literal hyphen (Booking::generateCode()), so
 * allowing a hyphen or space here would make a customer-facing code
 * ambiguous to split back apart.
 *
 * Pricing is two tiers with a rate each, plus a single "HH:MM" peak window
 * (non-peak is every hour outside it, so it needs no window of its own). The
 * peak window is deliberately NOT required to be start < end: it legitimately
 * runs 4pm–2am, across midnight (PricingService resolves the wrap). The only
 * window rule is that its start and end may not be identical — a zero-width
 * window prices nothing.
 */
class UpdateSystemSettingsRequest extends FormRequest
{
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
            'booking_hold_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'booking_verification_hold_minutes' => ['required', 'integer', 'min:30', 'max:10080', 'gte:booking_hold_minutes'],
            'booking_code_prefix' => ['required', 'string', 'min:2', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],

            // Optional: blank means the owner heads-up is switched off entirely.
            'owner_notification_email' => ['nullable', 'email:rfc', 'max:150'],

            'pricing_non_peak_rate' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'pricing_peak_rate' => ['required', 'numeric', 'min:0', 'max:99999999.99'],

            // 24-hour HH:MM. The peak window may cross midnight, so no after/before
            // rule between start and end — only that they are not the same instant.
            'pricing_peak_start' => ['required', 'date_format:H:i'],
            'pricing_peak_end' => ['required', 'date_format:H:i', 'different:pricing_peak_start'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'booking_verification_hold_minutes.gte' => 'The verification hold must be at least as long as the initial hold.',
            'booking_code_prefix.regex' => 'The prefix may only contain letters and numbers — no spaces, hyphens or symbols.',
            'pricing_peak_end.different' => 'The peak window cannot start and end at the same time.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'booking_hold_minutes' => 'reservation hold time',
            'booking_verification_hold_minutes' => 'verification hold time',
            'booking_code_prefix' => 'booking code prefix',
            'owner_notification_email' => 'owner notification email',
            'pricing_non_peak_rate' => 'non-peak rate',
            'pricing_peak_rate' => 'peak rate',
            'pricing_peak_start' => 'peak start time',
            'pricing_peak_end' => 'peak end time',
        ];
    }
}
