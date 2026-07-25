/*
| useStatus — the canonical status → tone/label mapping.
|
| Badge.vue is the usual consumer, but charts, timelines and detail panels need
| the same vocabulary, so the maps live here rather than inside the component.
|
| Tones are semantic names, not colours: brand | success | warn | danger | info | ink.
*/

/** Booking state machine — docs/ARCHITECTURE.md §4. */
export const BOOKING_STATUS_TONES = Object.freeze({
    awaiting_payment: 'warn',
    pending_verification: 'info',
    confirmed: 'success',
    rejected: 'danger',
    cancelled: 'ink',
    expired: 'ink',
    completed: 'brand',
});

/** Court slot lifecycle — docs/ARCHITECTURE.md §3. */
export const SLOT_STATUS_TONES = Object.freeze({
    available: 'success',
    held: 'warn',
    booked: 'brand',
    blocked: 'ink',
});

/** Generic flags that recur across users, courts and settings. */
export const GENERAL_STATUS_TONES = Object.freeze({
    active: 'success',
    inactive: 'ink',
    enabled: 'success',
    disabled: 'ink',
    yes: 'success',
    no: 'ink',
});

export const STATUS_TONES = Object.freeze({
    ...GENERAL_STATUS_TONES,
    ...SLOT_STATUS_TONES,
    ...BOOKING_STATUS_TONES,
});

/** Labels naive humanisation would get wrong or that read better spelled out. */
export const STATUS_LABELS = Object.freeze({
    awaiting_payment: 'Awaiting Payment',
    pending_verification: 'Pending Verification',
});

/** Ordered booking statuses — use for filter dropdowns so every screen matches. */
export const BOOKING_STATUSES = Object.freeze([
    'awaiting_payment',
    'pending_verification',
    'confirmed',
    'completed',
    'rejected',
    'cancelled',
    'expired',
]);

export const SLOT_STATUSES = Object.freeze(['available', 'held', 'booked', 'blocked']);

export function normaliseStatus(status) {
    if (typeof status === 'boolean') {
        return status ? 'active' : 'inactive';
    }

    if (status === null || status === undefined || status === '') {
        return null;
    }

    return String(status).toLowerCase();
}

/** @returns {string} semantic tone name */
export function statusTone(status, fallback = 'ink') {
    return STATUS_TONES[normaliseStatus(status)] ?? fallback;
}

/** @returns {string} human readable label */
export function statusLabel(status, fallback = '—') {
    const key = normaliseStatus(status);

    if (!key) {
        return fallback;
    }

    return (
        STATUS_LABELS[key] ??
        key
            .split(/[_\-\s]+/)
            .filter(Boolean)
            .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
    );
}

/** `[{ value, label }]` ready to drop into a FormSelect. */
export function statusOptions(statuses) {
    return statuses.map((value) => ({ value, label: statusLabel(value) }));
}

export function useStatus() {
    return {
        BOOKING_STATUSES,
        SLOT_STATUSES,
        BOOKING_STATUS_TONES,
        SLOT_STATUS_TONES,
        statusTone,
        statusLabel,
        statusOptions,
    };
}

export default useStatus;
