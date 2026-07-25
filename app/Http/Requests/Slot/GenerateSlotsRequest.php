<?php

declare(strict_types=1);

namespace App\Http\Requests\Slot;

use App\Services\SlotGeneratorService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Shape validation for the bulk generator, shared by `generate` and its
 * dry-run twin `preview` so the two can never disagree about what is legal.
 *
 * The *volume* ceiling deliberately lives in SlotGeneratorService, not here:
 * preview has to be able to report "this is too big" as data the form can
 * render, while generate has to refuse outright. One check, two behaviours.
 */
class GenerateSlotsRequest extends FormRequest
{
    /** Gated by `permission:slots.generate` on the route. */
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
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'days_of_week' => ['required', 'array', 'min:1', 'max:7'],
            'days_of_week.*' => ['integer', 'between:0,6', 'distinct'],
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            // Optional now: left blank, the court's own morning/afternoon/evening
            // rates price each slot. A value here is a flat override for this run.
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'court_id' => 'court',
            'days_of_week' => 'days of the week',
            'duration_minutes' => 'slot duration',
            'price' => 'price override',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'days_of_week.required' => 'Select at least one day of the week.',
            'days_of_week.min' => 'Select at least one day of the week.',
            'opening_time.date_format' => 'Enter the opening time in 24-hour HH:MM format.',
            'closing_time.date_format' => 'Enter the closing time in 24-hour HH:MM format.',
            'duration_minutes.min' => 'A slot must be at least 5 minutes long.',
            'court_id.exists' => 'That court no longer exists.',
            'price.numeric' => 'The price override must be a number, in pesos. Leave it blank to use the court rates.',
            'price.min' => 'The price override cannot be negative.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        foreach (['opening_time', 'closing_time'] as $field) {
            $normalised = $this->normaliseTime($this->input($field));

            if ($normalised !== null) {
                $payload[$field] = $normalised;
            }
        }

        // Checkbox rows serialise the mask as strings; cast so `distinct` and
        // `between` behave and the service receives real integers.
        if (is_array($this->input('days_of_week'))) {
            $payload['days_of_week'] = array_values(array_unique(array_map(
                static fn (mixed $day): int => (int) $day,
                array_filter($this->input('days_of_week'), static fn (mixed $day): bool => is_scalar($day)),
            )));
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $start = Carbon::parse((string) $this->input('start_date'))->startOfDay();
                $end = Carbon::parse((string) $this->input('end_date'))->startOfDay();

                if ($start->diffInDays($end) + 1 > SlotGeneratorService::MAX_RANGE_DAYS) {
                    $validator->errors()->add('end_date', sprintf(
                        'A single generator run may cover at most %d days. Split the schedule into shorter runs.',
                        SlotGeneratorService::MAX_RANGE_DAYS,
                    ));
                }

                if ($end->isBefore(Carbon::today())) {
                    $validator->errors()->add(
                        'end_date',
                        'That whole range is in the past. Generated slots would never be bookable.',
                    );
                }
            },
        ];
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
