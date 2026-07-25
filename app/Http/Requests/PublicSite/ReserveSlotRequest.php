<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicSite;

use App\Models\CourtSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Guest checkout — the moment a visitor claims one or more times across one or
 * more courts (possibly non-contiguous).
 *
 * Everything the customer can influence is validated here; nothing about the
 * booking's money or timing is taken from this payload. The price is copied
 * from the slot rows inside BookingService, so a tampered form cannot change
 * what is owed.
 *
 * The availability checks below are a courtesy that produces a nice inline
 * error — they are NOT the race guard. The authoritative check is the
 * `lockForUpdate()` re-read inside BookingService::reserve().
 */
class ReserveSlotRequest extends FormRequest
{
    /** Resolved once so the rules and the after-hook share one query. */
    private ?Collection $slots = null;

    public function authorize(): bool
    {
        // Public funnel: the slots' own state is the only gate.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slot_ids' => ['required', 'array', 'min:1'],

            'slot_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('court_slots', 'id'),
            ],

            'customer_name' => ['required', 'string', 'min:2', 'max:150'],

            // Philippine mobile numbers only — SMS confirmations depend on it.
            // Normalised to 09XXXXXXXXX in prepareForValidation().
            'customer_phone' => ['required', 'string', 'regex:/^09\d{9}$/'],

            'customer_email' => ['required', 'string', 'email:rfc,filter', 'max:190'],

            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slot_ids.required' => 'Please choose at least one time slot first.',
            'slot_ids.array' => 'Please choose at least one time slot first.',
            'slot_ids.min' => 'Please choose at least one time slot first.',
            'slot_ids.*.integer' => 'One of the selected times is not valid.',
            'slot_ids.*.distinct' => 'The same time slot was selected twice.',
            'slot_ids.*.exists' => 'One of the selected times is no longer on the schedule. Please pick another one.',
            'customer_name.required' => 'Please tell us who the booking is for.',
            'customer_name.min' => 'Please enter your full name.',
            'customer_phone.required' => 'We need a mobile number to send your confirmation to.',
            'customer_phone.regex' => 'Please enter a valid Philippine mobile number, e.g. 0917 123 4567.',
            'customer_email.required' => 'Please enter an email address so we can send your confirmation.',
            'customer_email.email' => 'That email address does not look right.',
            'notes.max' => 'Please keep your note under 500 characters.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'slot_ids' => 'selected times',
            'slot_ids.*' => 'one of the selected times',
            'customer_name' => 'name',
            'customer_phone' => 'mobile number',
            'customer_email' => 'email address',
        ];
    }

    /**
     * Tidy the payload before any rule runs: trim everything, collapse the many
     * ways a Filipino number gets typed (+63, 63, 9…, spaces, dashes) into the
     * single canonical 09XXXXXXXXX form we store and text.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'customer_name' => $this->squash($this->input('customer_name')),
            'customer_phone' => $this->normalisePhone($this->input('customer_phone')),
            'customer_email' => $this->blankToNull(mb_strtolower(trim((string) $this->input('customer_email')))),
            'notes' => $this->blankToNull($this->squash($this->input('notes'))),
        ]);
    }

    /**
     * Business-rule checks that need the slot rows themselves.
     *
     * Every requested id is checked independently so the customer can see
     * exactly which pick failed (blocked vs. unavailable vs. in the past)
     * rather than one flat error covering the whole set.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('slot_ids') || $validator->errors()->has('slot_ids.*')) {
                return;
            }

            $ids = $this->input('slot_ids');

            if (! is_array($ids) || $ids === []) {
                return;
            }

            $slots = $this->slots();

            foreach ($ids as $index => $rawId) {
                if ($validator->errors()->has("slot_ids.{$index}")) {
                    continue;
                }

                $id = is_numeric($rawId) ? (int) $rawId : null;
                $slot = $id !== null ? $slots->firstWhere('id', $id) : null;

                if (! $slot instanceof CourtSlot) {
                    $validator->errors()->add(
                        "slot_ids.{$index}",
                        'One of the selected times is no longer on the schedule. Please pick another one.',
                    );

                    continue;
                }

                if ($slot->court === null || ! $slot->court->is_active) {
                    $validator->errors()->add(
                        "slot_ids.{$index}",
                        'That court is not accepting bookings right now.',
                    );

                    continue;
                }

                if ($slot->status === CourtSlot::STATUS_BLOCKED) {
                    $validator->errors()->add(
                        "slot_ids.{$index}",
                        'One of the selected times is not open for booking. Please choose a different time.',
                    );

                    continue;
                }

                if ($slot->status !== CourtSlot::STATUS_AVAILABLE) {
                    $validator->errors()->add(
                        "slot_ids.{$index}",
                        'Sorry, one of the selected times was just taken by someone else. Please pick another one.',
                    );

                    continue;
                }

                if ($slot->hasStarted()) {
                    $validator->errors()->add(
                        "slot_ids.{$index}",
                        'One of the selected times has already passed. Please pick an upcoming schedule.',
                    );
                }
            }
        });
    }

    /**
     * Every slot being claimed, with its court eager-loaded.
     *
     * Memoised so the after-hook and the controller share a single query.
     *
     * @return Collection<int, CourtSlot>
     */
    public function slots(): Collection
    {
        if ($this->slots instanceof Collection) {
            return $this->slots;
        }

        $ids = $this->input('slot_ids');

        if (! is_array($ids)) {
            return $this->slots = new Collection;
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null, $ids),
            static fn (?int $id): bool => $id !== null,
        )));

        if ($ids === []) {
            return $this->slots = new Collection;
        }

        return $this->slots = CourtSlot::query()
            ->with('court:id,name,slug,is_active')
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * The customer payload in the shape BookingService::reserve() expects.
     *
     * @return array<string, string|null>
     */
    public function customer(): array
    {
        return [
            'customer_name' => (string) $this->validated('customer_name'),
            'customer_phone' => (string) $this->validated('customer_phone'),
            'customer_email' => $this->validated('customer_email'),
            'notes' => $this->validated('notes'),
        ];
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Reduce any run of whitespace to a single space and trim the ends.
     */
    private function squash(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * 0917 123 4567 / +63 917 123 4567 / 63 917 1234567 / 9171234567
     * all become 09171234567. Anything else is returned trimmed so the regex
     * rule can reject it with a readable message.
     */
    private function normalisePhone(mixed $value): string
    {
        $raw = trim((string) $value);
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return $raw;
        }

        // 639171234567 → 09171234567
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0'.substr($digits, 2);
        }

        // 9171234567 → 09171234567
        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0'.$digits;
        }

        // 009171234567 / 0063… — drop the international access prefix.
        if (str_starts_with($digits, '0063') && strlen($digits) === 14) {
            return '0'.substr($digits, 4);
        }

        return $digits;
    }
}
