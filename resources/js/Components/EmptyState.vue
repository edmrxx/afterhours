<script setup>
import { Inbox } from '@lucide/vue';

/*
| EmptyState — a designed nothing-here state, never an afterthought.
|
|   <EmptyState :icon="LayoutGrid" title="No courts yet"
|               description="Add your first court to start taking bookings.">
|       <template #action><Button :href="route('admin.courts.create')">Add court</Button></template>
|   </EmptyState>
*/

defineProps({
    /** A Lucide component. */
    icon: { type: [Object, Function], default: () => Inbox },
    title: { type: String, default: 'Nothing here yet' },
    description: { type: String, default: null },
    /** sm | md | lg — vertical breathing room. */
    size: { type: String, default: 'md' },
    /** brand | ink | success | warn | danger | info */
    tone: { type: String, default: 'ink' },
});

const PADDING = { sm: 'py-8', md: 'py-14', lg: 'py-20' };

const TONES = {
    brand: 'bg-brand-50 text-brand-500 ring-brand-100',
    ink: 'bg-ink-100 text-ink-400 ring-ink-200/70',
    success: 'bg-success-50 text-success-500 ring-success-500/15',
    warn: 'bg-warn-50 text-warn-500 ring-warn-500/15',
    danger: 'bg-danger-50 text-danger-500 ring-danger-500/15',
    info: 'bg-info-50 text-info-500 ring-info-500/15',
};
</script>

<template>
    <div
        :class="['flex flex-col items-center px-6 text-center', PADDING[size] ?? PADDING.md]"
    >
        <slot name="icon">
            <span
                :class="[
                    'inline-flex h-14 w-14 items-center justify-center rounded-2xl ring-1 ring-inset',
                    TONES[tone] ?? TONES.ink,
                ]"
                aria-hidden="true"
            >
                <component :is="icon" :size="26" :stroke-width="1.5" />
            </span>
        </slot>

        <h3 class="mt-4 text-sm font-semibold text-ink-900">
            <slot name="title">{{ title }}</slot>
        </h3>

        <p
            v-if="description || $slots.description"
            class="mt-1.5 max-w-sm text-sm leading-relaxed text-ink-500"
        >
            <slot name="description">{{ description }}</slot>
        </p>

        <div v-if="$slots.action" class="mt-5 flex flex-wrap items-center justify-center gap-2">
            <slot name="action" />
        </div>
    </div>
</template>
