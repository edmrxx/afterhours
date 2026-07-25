<?php

declare(strict_types=1);

namespace App\Http\Requests\Slot;

use App\Models\CourtSlot;

/**
 * Editing one existing slot.
 *
 * Identical to creation except for two things, both expressed as overrides so
 * the overlap algorithm itself is written once:
 *
 *  · the row being edited must not be treated as its own conflict;
 *  · a slot already in the past may be corrected (fixing a typo on yesterday's
 *    price is legitimate), so the future-date guard is lifted.
 *
 * Refusing to edit a held or booked slot is a state rule, not a shape rule, and
 * lives in the controller alongside the other lifecycle guards.
 */
class UpdateSlotRequest extends StoreSlotRequest
{
    protected function ignoreSlotId(): ?int
    {
        $slot = $this->route('slot');

        if ($slot instanceof CourtSlot) {
            return (int) $slot->getKey();
        }

        return is_numeric($slot) ? (int) $slot : null;
    }

    protected function enforcesFutureDate(): bool
    {
        return false;
    }
}
