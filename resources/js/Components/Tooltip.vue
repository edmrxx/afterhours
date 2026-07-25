<script setup>
/*
| Tooltip — CSS-only, works on hover and keyboard focus.
|
|   <Tooltip text="Release the hold">
|       <IconButton variant="warn" label="Release">…</IconButton>
|   </Tooltip>
|
| Purely decorative: always keep a real accessible name on the trigger itself.
*/

defineProps({
    text: { type: String, required: true },
    /** top | bottom | left | right */
    placement: { type: String, default: 'top' },
    /** Let the tooltip wrap instead of staying on one line. */
    wrap: { type: Boolean, default: false },
});

const POSITIONS = {
    top: 'bottom-full left-1/2 -translate-x-1/2 mb-2',
    bottom: 'top-full left-1/2 -translate-x-1/2 mt-2',
    left: 'right-full top-1/2 -translate-y-1/2 mr-2',
    right: 'left-full top-1/2 -translate-y-1/2 ml-2',
};
</script>

<template>
    <span class="group/tooltip relative inline-flex">
        <slot />

        <span
            role="tooltip"
            :class="[
                'pointer-events-none absolute z-40 rounded-md bg-ink-900 px-2 py-1 text-[11px] leading-snug font-medium text-white',
                'opacity-0 shadow-float transition-opacity duration-150 ease-[var(--ease-out-soft)]',
                'group-hover/tooltip:opacity-100 group-focus-within/tooltip:opacity-100',
                wrap ? 'max-w-56 text-center' : 'whitespace-nowrap',
                POSITIONS[placement] ?? POSITIONS.top,
            ]"
        >
            {{ text }}
        </span>
    </span>
</template>
