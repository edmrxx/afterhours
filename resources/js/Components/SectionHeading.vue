<script setup>
/*
| SectionHeading — divides a long page or form into labelled sections.
|
|   <SectionHeading title="GCash details" description="Shown to customers on the payment step">
|       <template #actions><Button size="sm" variant="secondary">Preview</Button></template>
|   </SectionHeading>
*/

defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    /** sm | md | lg */
    size: { type: String, default: 'md' },
    /** Hairline under the heading. */
    divider: { type: Boolean, default: false },
    /** A Lucide component shown before the title. */
    icon: { type: [Object, Function], default: null },
});

const SIZES = {
    sm: 'text-xs font-semibold uppercase tracking-wide text-ink-500',
    md: 'text-sm font-semibold text-ink-900',
    lg: 'text-base font-semibold text-ink-900',
};
</script>

<template>
    <div
        :class="[
            'flex flex-wrap items-start justify-between gap-3',
            divider ? 'border-b border-ink-200 pb-3' : '',
        ]"
    >
        <div class="flex min-w-0 items-start gap-2.5">
            <component
                :is="icon"
                v-if="icon"
                :size="18"
                class="mt-px shrink-0 text-ink-400"
                aria-hidden="true"
            />
            <div class="min-w-0">
                <h2 :class="SIZES[size] ?? SIZES.md">
                    <slot name="title">{{ title }}</slot>
                </h2>
                <p
                    v-if="description || $slots.description"
                    class="mt-1 text-sm leading-relaxed text-ink-500"
                >
                    <slot name="description">{{ description }}</slot>
                </p>
            </div>
        </div>

        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
