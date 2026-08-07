<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Models\Booking;
use App\Models\CourtSlot;
use App\Services\BookingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The desk keying in a booking a customer arranged directly with the club.
 *
 * Deliberately the admin-side sibling of ReserveSlotRequest, not a subclass of
 * it: the two share a shape but disagree on almost every judgement call, and
 * inheriting would make each difference look like an oversight rather than a
 * decision. The differences, all of them intentional:
 *
 *  - **A past slot is valid input.** The guest form rejects one because a
 *    visitor picking it is always a mistake; here it is the entire point of
 *    the backfill case — last night's walk-in has to be able to reach the
 *    books.
 *  - **Email is optional.** The public flow needs somewhere to send the
 *    confirmation; nothing is mailed for a manual booking, so demanding an
 *    address the staff member does not have would just invite invented ones.
 *  - **A mode is required.** Money already settled, or a court held for
 *    someone who has not paid — the guest flow has no such fork.
 *
 * As in ReserveSlotRequest, the availability checks here are a courtesy that
 * produces a readable inline error. The authoritative guard is the
 * `lockForUpdate()` re-read inside BookingService::reserveManually().
 */
class StoreManualBookingRequest extends FormRequest
{
    /** Resolved once so the rules and the after-hook share one query. */
    private ?Collection $slots = null;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Booking::class) === true;
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

            'mode' => ['required', 'string', Rule::in(BookingService::MANUAL_MODES)],

            'customer_name' => ['required', 'string', 'min:2', 'max:150'],

            // Same canonical Philippine mobile format the public flow stores,
            // so one customer looks the same however their booking was made —
            // a search for their number must find both.
            'customer_phone' => ['required', 'string', 'regex:/^09\d{9}$/'],

            // Optional, unlike checkout: nothing is mailed for a manual
            // booking, so an address is a nice-to-have record, not a channel.
            'customer_email' => ['nullable', 'string', 'email:rfc,filter', 'max:190'],

            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slot_ids.required' => 'Pick at least one time slot for this booking.',
            'slot_ids.array' => 'Pick at least one time slot for this booking.',
            'slot_ids.min' => 'Pick at least one time slot for this booking.',
            'slot_ids.*.integer' => 'One of the selected times is not valid.',
            'slot_ids.*.distinct' => 'The same time slot was selected twice.',
            'slot_ids.*.exists' => 'One of the selected times is no longer on the schedule. Refresh and pick again.',
            'mode.required' => 'Choose whether this booking is already paid or only being held.',
            'mode.in' => 'Choose whether this booking is already paid or only being held.',
            'customer_name.required' => 'Enter the name this booking is for.',
            'customer_name.min' => 'Enter the customer’s full name.',
            'customer_phone.required' => 'Enter a mobile number so the customer can be reached about this booking.',
            'customer_phone.regex' => 'Enter a valid Philippine mobile number, e.g. 0917 123 4567.',
            'customer_email.email' => 'That email address does not look right.',
            'notes.max' => 'Keep the note under 500 characters.',
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
            'customer_name' => 'customer name',
            'customer_phone' => 'mobile number',
            'customer_email' => 'email address',
            'mode' => 'booking type',
        ];
    }

    /**
     * Tidy the payload before any rule runs — same normalisation the public
     * form applies, so a number typed at the desk is stored identically to one
     * typed by the customer.
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
     * Slot-level checks, reported per pick so staff can see exactly which of
     * their selections failed rather than one flat error over the whole set.
     *
     * Note what is NOT checked: whether the slot has already started. A manual
     * booking of a past hour is a legitimate backfill, and refusing it here
     * would put the club's own history out of reach.
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
                        'One of the selected times is no longer on the schedule. Refresh and pick again.',
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

                // Blocking is how the club takes an hour off the market for
                // maintenance or a private event. A manual booking that could
                // quietly overwrite it would make the block worthless, so the
                // refusal is the same one the public gets — with a message
                // that tells staff exactly how to proceed if they meant it.
                if ($slot->status === CourtSlot::STATUS_BLOCKED) {
                    $validator->errors()->add(
                        "slot_ids.{$index}",
                        'One of the selected times is blocked. Unblock it in Slots first if you really want to book it.',
                    );

                    continue;
                }

                if ($slot->status !== CourtSlot::STATUS_AVAILABLE) {
                    $validator->errors()->add(
                        "slot_ids.{$index}",
                        'One of the selected times is already taken. Refresh and pick another one.',
                    );
                }
            }
        });
    }

    /**
     * Every slot being claimed, with its court eager-loaded. Memoised so the
     * after-hook and the controller share a single query.
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
     * The customer payload in the shape BookingService::reserveManually()
     * expects.
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

    /**
     * @return list<int>
     */
    public function slotIds(): array
    {
        $ids = $this->validated('slot_ids');

        return is_array($ids)
            ? array_values(array_map(static fn (mixed $id): int => (int) $id, $ids))
            : [];
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* --------------------------------------------------------------------- */

    private function squash(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * 0917 123 4567 / +63 917 123 4567 / 63 917 1234567 / 9171234567 all
     * become 09171234567 — the same canonical form the public form produces.
     */
    private function normalisePhone(mixed $value): string
    {
        $raw = trim((string) $value);
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return $raw;
        }

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0'.substr($digits, 2);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0'.$digits;
        }

        if (str_starts_with($digits, '0063') && strlen($digits) === 14) {
            return '0'.substr($digits, 4);
        }

        return $digits;
    }
}
