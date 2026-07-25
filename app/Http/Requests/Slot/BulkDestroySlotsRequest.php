<?php

declare(strict_types=1);

namespace App\Http\Requests\Slot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The multi-select delete on the slot grid.
 *
 * Existence is validated but *deletability* is not: a slot that is held or
 * booked must produce a "skipped, and here is why" report rather than a
 * validation failure, so that decision belongs to the controller where the
 * counts can be gathered and flashed back.
 */
class BulkDestroySlotsRequest extends FormRequest
{
    /** Gated by `permission:slots.delete` on the route. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Bounded so one request can never try to delete the whole table.
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer', 'distinct', 'exists:court_slots,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one slot to delete.',
            'ids.max' => 'You can delete at most 1,000 slots in one go. Narrow the selection and try again.',
            'ids.*.exists' => 'One or more of the selected slots no longer exist. Refresh and try again.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('ids'))) {
            $this->merge([
                'ids' => array_values(array_unique(array_map(
                    static fn (mixed $id): int => (int) $id,
                    array_filter($this->input('ids'), static fn (mixed $id): bool => is_numeric($id)),
                ))),
            ]);
        }
    }

    /** @return list<int> */
    public function slotIds(): array
    {
        /** @var list<int> $ids */
        $ids = $this->validated('ids');

        return $ids;
    }
}
