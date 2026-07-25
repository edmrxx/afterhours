<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Rejecting a payment the admin could not match in the app it came from.
 *
 * The reason is optional — sometimes there simply is nothing to say beyond
 * "no such transaction" — but when supplied it is quoted verbatim in the
 * customer's rejection email and SMS, so it is trimmed, length-capped and
 * stripped of control characters before it ever reaches the notification.
 */
class RejectBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('booking');

        if (! $booking instanceof Booking) {
            return false;
        }

        return $this->user()?->can('reject', $booking) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'rejection reason',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.max' => 'Keep the rejection reason under 500 characters — it is sent to the customer as-is.',
        ];
    }

    /**
     * Normalise before validating: whitespace-only input is the same as none.
     */
    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (! is_string($reason)) {
            $this->merge(['reason' => null]);

            return;
        }

        // Collapse runs of whitespace so a stray newline block does not turn
        // into a wall of blank lines inside the customer email.
        $clean = trim((string) preg_replace('/\s+/u', ' ', $reason));

        $this->merge(['reason' => $clean === '' ? null : Str::limit($clean, 500, '')]);
    }

    /**
     * The cleaned reason, or null when the admin gave none.
     */
    public function reason(): ?string
    {
        $reason = $this->validated()['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
