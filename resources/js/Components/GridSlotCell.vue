<script setup>
/*
| GridSlotCell — one cell of the public schedule grid (time row x court
| column). Purely presentational: the parent decides what "available" means
| and formats the price, this just renders the four/five visual states with
| the app's existing slot-status colour convention (Composables/useStatus.js):
|
|   available    -> graphite tone, clickable, shows the price
|   selected     -> the customer's current pick — solid noir-900 black,
|                   white text (21:1), the grid's only solid-dark fill
|   pending      -> warn tone, "Held" by someone mid-checkout, not clickable
|   booked       -> pale brand wash, already taken, not clickable
|   unavailable  -> muted ink tone, nothing was ever scheduled here, not clickable
|
| Buttons are black system-wide, red is reserved for fields/surfaces — so
| "selected" (this cell IS the thing you're about to book, same intent as a
| primary button) is noir, not brand. Every neutral ramp (graphite/ash/ink)
| lands on a near-white grey at these tints, so solid black still reads as
| unmistakable next to its neighbours — booked stays a pale brand-50 wash and
| never competes with it.
*/

defineProps({
    /** available | pending | booked | unavailable */
    status: { type: String, required: true },
    /** Pre-formatted currency string, only meaningful when status === 'available'. */
    priceLabel: { type: String, default: null },
    /** This is the slot the customer just picked. */
    selected: { type: Boolean, default: false },
    ariaLabel: { type: String, default: null },
});

defineEmits(['pick']);

const LABEL = {
    pending: 'Pending',
    booked: 'Booked',
    unavailable: '—',
};

const TONE = {
    pending: 'border-warn-500/30 bg-warn-50 text-warn-700',
    booked: 'border-brand-500/20 bg-brand-50 text-brand-700',
    unavailable: 'border-ink-200 bg-ink-50 text-ink-300',
};
</script>

<template>
    <button
        v-if="status === 'available'"
        type="button"
        :aria-pressed="selected"
        :aria-label="ariaLabel"
        :class="[
            'flex h-14 w-full flex-col items-center justify-center gap-0.5 rounded-lg border text-xs font-semibold',
            'transition-[background-color,border-color,box-shadow,transform] duration-150 ease-[var(--ease-out-soft)]',
            'cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900',
            selected
                ? 'border-noir-900 bg-noir-900 text-white shadow-card ring-2 ring-noir-900/25'
                : 'border-graphite-500/30 bg-graphite-50 text-graphite-700 hover:-translate-y-0.5 hover:border-graphite-500 hover:bg-graphite-100 hover:shadow-card-hover',
        ]"
        @click="$emit('pick')"
    >
        <span>{{ priceLabel }}</span>
    </button>

    <div
        v-else
        :class="[
            'flex h-14 w-full items-center justify-center rounded-lg border text-[11px] font-medium select-none',
            TONE[status] ?? TONE.unavailable,
        ]"
        :aria-label="ariaLabel"
    >
        {{ LABEL[status] ?? '—' }}
    </div>
</template>
