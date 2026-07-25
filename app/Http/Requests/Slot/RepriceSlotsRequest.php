<?php

declare(strict_types=1);

namespace App\Http\Requests\Slot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The reprice action: push a court's CURRENT effective rates onto its already
 * generated `available` schedule, on demand.
 *
 * This is deliberately thin. Everything that decides *what* gets touched —
 * the `available`-only status guard, the "never before today" floor — is
 * SlotGeneratorService::reprice()'s job, because it must hold true no matter
 * how this action is invoked (this request, a console command, a future API).
 * All that belongs here is: does this court exist, and is the requested date
 * window well-formed.
 */
class RepriceSlotsRequest extends FormRequest
{
    /** Gated by `permission:slots.generate` on the route — the same gate the bulk generator uses. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'court_id' => [
                'required',
                'integer',
                Rule::exists('courts', 'id')->whereNull('deleted_at'),
            ],
            // Both ends are optional: a blank `from_date` means "today", a blank
            // `to_date` means "no upper bound". The service resolves those
            // defaults; this only checks the shape when something was sent.
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'court_id' => 'court',
            'from_date' => 'start date',
            'to_date' => 'end date',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'court_id.exists' => 'That court no longer exists.',
            'from_date.date_format' => 'Enter the start date in YYYY-MM-DD format.',
            'to_date.date_format' => 'Enter the end date in YYYY-MM-DD format.',
            'to_date.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
