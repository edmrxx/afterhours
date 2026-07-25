<?php

declare(strict_types=1);

namespace App\Http\Requests\Slot;

use App\Models\CourtSlot;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validation for adding a single, hand-placed slot.
 *
 * The interesting rule is the overlap guard. The unique index only stops two
 * slots sharing an exact start time; it happily accepts 08:00–10:00 sitting on
 * top of 09:00–10:00. Double-selling a court is a real-world refund, so the
 * check here is a proper half-open interval test, not an equality test.
 *
 * UpdateSlotRequest extends this class and only overrides the two things that
 * genuinely differ — the row to exclude from the overlap probe, and whether the
 * date must still be in the future.
 */
class StoreSlotRequest extends FormRequest
{
    /**
     * Access is already decided by the `permission:slots.create` middleware on
     * the route; duplicating it here would let the two drift apart.
     */
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
            'slot_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'status' => [
                'sometimes',
                Rule::in([CourtSlot::STATUS_AVAILABLE, CourtSlot::STATUS_BLOCKED]),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'court_id' => 'court',
            'slot_date' => 'date',
            'start_time' => 'start time',
            'end_time' => 'end time',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'start_time.date_format' => 'Enter the start time in 24-hour HH:MM format.',
            'end_time.date_format' => 'Enter the end time in 24-hour HH:MM format.',
            'court_id.exists' => 'That court no longer exists.',
        ];
    }

    /**
     * Browser time inputs happily emit "08:00:00" when a `step` is set, and
     * "8:00" when typed. Normalise before the format rules run.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'start_time' => $this->normaliseTime($this->input('start_time')),
            'end_time' => $this->normaliseTime($this->input('end_time')),
        ], static fn (?string $value): bool => $value !== null));
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateWindow($validator),
            fn (Validator $validator) => $this->validateNotInPast($validator),
            fn (Validator $validator) => $this->validateNoOverlap($validator),
        ];
    }

    /* --------------------------------------------------------------------- */
    /* Hooks the update request overrides                                     */
    /* --------------------------------------------------------------------- */

    /** Row to ignore when probing for overlaps — the record being edited. */
    protected function ignoreSlotId(): ?int
    {
        return null;
    }

    /** Only creation is barred from the past; an existing slot may be corrected. */
    protected function enforcesFutureDate(): bool
    {
        return true;
    }

    /* --------------------------------------------------------------------- */
    /* Rules that need more than a rule string                                */
    /* --------------------------------------------------------------------- */

    private function validateWindow(Validator $validator): void
    {
        if ($this->alreadyFailed($validator, ['start_time', 'end_time'])) {
            return;
        }

        if ($this->minutes('end_time') <= $this->minutes('start_time')) {
            $validator->errors()->add(
                'end_time',
                'The end time must be later than the start time.',
            );
        }
    }

    private function validateNotInPast(Validator $validator): void
    {
        if (! $this->enforcesFutureDate() || $this->alreadyFailed($validator, ['slot_date', 'end_time'])) {
            return;
        }

        $date = Carbon::parse((string) $this->input('slot_date'))->startOfDay();

        if ($date->isBefore(Carbon::today())) {
            $validator->errors()->add(
                'slot_date',
                'You cannot create a slot on a date that has already passed.',
            );

            return;
        }

        $ends = $date->copy()->addMinutes($this->minutes('end_time'));

        if ($ends->isPast()) {
            $validator->errors()->add(
                'end_time',
                'That time has already passed today. Pick a later time or a future date.',
            );
        }
    }

    /**
     * Two half-open intervals [aStart, aEnd) and [bStart, bEnd) overlap when
     * aStart < bEnd AND aEnd > bStart. Back-to-back slots (10:00–11:00 then
     * 11:00–12:00) therefore pass, which is exactly what the generator emits.
     *
     * The `end_time <= start_time` branch catches slots the generator wrapped
     * past midnight: their stored end is numerically smaller than their start,
     * so their effective end is beyond any time on the same date.
     */
    private function validateNoOverlap(Validator $validator): void
    {
        if ($this->alreadyFailed($validator, ['court_id', 'slot_date', 'start_time', 'end_time'])) {
            return;
        }

        if ($validator->errors()->has('end_time')) {
            return;
        }

        $start = $this->sqlTime('start_time');
        $end = $this->sqlTime('end_time');
        $ignore = $this->ignoreSlotId();

        $conflict = CourtSlot::query()
            ->where('court_id', (int) $this->input('court_id'))
            ->whereDate('slot_date', (string) $this->input('slot_date'))
            ->when($ignore !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignore))
            ->where('start_time', '<', $end)
            ->where(function (Builder $query) use ($start): void {
                $query->where('end_time', '>', $start)
                    ->orWhereColumn('end_time', '<=', 'start_time');
            })
            ->orderBy('start_time')
            ->first(['id', 'slot_date', 'start_time', 'end_time', 'status']);

        if ($conflict === null) {
            return;
        }

        $validator->errors()->add(
            'start_time',
            sprintf(
                'This overlaps an existing %s slot on that court (%s). Pick a different window.',
                $conflict->status,
                $conflict->time_range,
            ),
        );
    }

    /* --------------------------------------------------------------------- */
    /* Small helpers                                                          */
    /* --------------------------------------------------------------------- */

    /** @param  list<string>  $fields */
    private function alreadyFailed(Validator $validator, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($validator->errors()->has($field) || blank($this->input($field))) {
                return true;
            }
        }

        return false;
    }

    private function minutes(string $field): int
    {
        [$hours, $minutes] = array_pad(explode(':', (string) $this->input($field)), 2, '0');

        return ((int) $hours * 60) + (int) $minutes;
    }

    private function sqlTime(string $field): string
    {
        return (string) $this->input($field).':00';
    }

    private function normaliseTime(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $matches)) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }
}
