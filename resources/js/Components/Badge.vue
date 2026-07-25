<script setup>
import { computed } from 'vue';
import { statusLabel, statusTone } from '@/Composables/useStatus';

/*
| Badge — the single source of truth for how a status looks.
|
|   <Badge :status="booking.status" />   awaiting_payment → amber pill
|   <Badge :status="slot.status" />      available        → green pill
|   <Badge :status="court.is_active" />  boolean          → Active / Inactive
|   <Badge tone="brand">Custom</Badge>
*/

const props = defineProps({
    /** A booking status, slot status or boolean flag. Drives tone + label. */
    status: { type: [String, Boolean], default: null },
    /** Explicit tone override: brand | success | warn | danger | info | ink. */
    tone: { type: String, default: null },
    /** Explicit label override; falls back to a humanised `status`. */
    label: { type: String, default: null },
    /** xs | sm | md */
    size: { type: String, default: 'sm' },
    /** Leading status dot. */
    dot: { type: Boolean, default: true },
});

const TONES = {
    brand: 'bg-brand-50 text-brand-700 ring-brand-200',
    success: 'bg-success-50 text-success-700 ring-success-500/25',
    warn: 'bg-warn-50 text-warn-700 ring-warn-500/25',
    danger: 'bg-danger-50 text-danger-700 ring-danger-500/25',
    info: 'bg-info-50 text-info-600 ring-info-500/25',
    ink: 'bg-ink-100 text-ink-600 ring-ink-200',
};

const DOTS = {
    brand: 'bg-brand-500',
    success: 'bg-success-500',
    warn: 'bg-warn-500',
    danger: 'bg-danger-500',
    info: 'bg-info-500',
    ink: 'bg-ink-400',
};

const SIZES = {
    xs: 'h-5 gap-1 px-1.5 text-[10px]',
    sm: 'h-6 gap-1.5 px-2 text-[11px]',
    md: 'h-7 gap-1.5 px-2.5 text-xs',
};

const resolvedTone = computed(() => props.tone ?? statusTone(props.status));

const text = computed(() => props.label ?? statusLabel(props.status));
</script>

<template>
    <span
        :class="[
            'inline-flex items-center rounded-full font-medium leading-none whitespace-nowrap ring-1 ring-inset',
            SIZES[size] ?? SIZES.sm,
            TONES[resolvedTone] ?? TONES.ink,
        ]"
    >
        <span
            v-if="dot"
            :class="['h-1.5 w-1.5 shrink-0 rounded-full', DOTS[resolvedTone] ?? DOTS.ink]"
            aria-hidden="true"
        />
        <slot>{{ text }}</slot>
    </span>
</template>
