<script setup>
import { computed } from 'vue';

/*
| Card — the surface every panel sits on.
|
|   <Card title="Court details" subtitle="Basic information">
|       <template #actions><Button size="sm">Edit</Button></template>
|       …
|       <template #footer>…</template>
|   </Card>
*/

const props = defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    /** none | sm | md | lg — inner padding of the body. */
    padding: { type: String, default: 'md' },
    /** Lift the shadow on hover (use for clickable cards). */
    hover: { type: Boolean, default: false },
    /** Drop the border + shadow, e.g. when nested inside another card. */
    flat: { type: Boolean, default: false },
});

const PADDING = {
    none: '',
    sm: 'p-4',
    md: 'p-5 sm:p-6',
    lg: 'p-6 sm:p-8',
};

const bodyPadding = computed(() => PADDING[props.padding] ?? PADDING.md);
</script>

<template>
    <section
        :class="[
            'rounded-xl bg-white',
            flat ? 'border border-ink-200' : 'border border-ink-200/70 shadow-card',
            hover
                ? 'transition-shadow duration-200 ease-[var(--ease-out-soft)] hover:shadow-card-hover'
                : '',
        ]"
    >
        <header
            v-if="title || subtitle || $slots.header || $slots.actions"
            class="flex flex-wrap items-start justify-between gap-3 border-b border-ink-200/70 px-5 py-4 sm:px-6"
        >
            <slot name="header">
                <div class="min-w-0">
                    <h3 v-if="title" class="truncate text-sm font-semibold text-ink-900">
                        {{ title }}
                    </h3>
                    <p v-if="subtitle" class="mt-0.5 text-xs text-ink-500">
                        {{ subtitle }}
                    </p>
                </div>
            </slot>

            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div :class="bodyPadding">
            <slot />
        </div>

        <footer
            v-if="$slots.footer"
            class="border-t border-ink-200/70 bg-ink-50/60 px-5 py-3 sm:px-6"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
