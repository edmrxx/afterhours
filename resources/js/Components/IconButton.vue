<script setup>
import { computed, useAttrs } from 'vue';
import { Link } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';

/*
| IconButton — the icon-only action button used in every table row.
|
| Colour semantics are fixed by the contract:
|   view = info blue · edit = success green · delete = danger red · warn = orange
|
| `label` is mandatory: it becomes the aria-label and the hover tooltip.
*/

defineOptions({ inheritAttrs: false });

const props = defineProps({
    /** view | edit | delete | warn | neutral | brand */
    variant: { type: String, default: 'neutral' },
    /** sm | md */
    size: { type: String, default: 'md' },
    /** Accessible name + tooltip text. Required. */
    label: { type: String, required: true },
    /** top | bottom */
    tooltipPlacement: { type: String, default: 'top' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    type: { type: String, default: 'button' },
    /** Render as an Inertia <Link> when an href is supplied. */
    href: { type: String, default: null },
    method: { type: String, default: 'get' },
    preserveScroll: { type: Boolean, default: true },
});

const attrs = useAttrs();

const VARIANTS = {
    view: 'text-info-600 hover:bg-info-50 hover:text-info-600',
    edit: 'text-success-600 hover:bg-success-50 hover:text-success-700',
    delete: 'text-danger-600 hover:bg-danger-50 hover:text-danger-700',
    warn: 'text-warn-600 hover:bg-warn-50 hover:text-warn-700',
    brand: 'text-brand-600 hover:bg-brand-50 hover:text-brand-700',
    neutral: 'text-ink-500 hover:bg-ink-100 hover:text-ink-800',
};

const SIZES = {
    sm: 'h-7 w-7 rounded-lg',
    md: 'h-9 w-9 rounded-lg',
};

const ICON_SIZES = { sm: 14, md: 16 };

const isDisabled = computed(() => props.disabled || props.loading);

const component = computed(() => (props.href ? Link : 'button'));

const bindings = computed(() => {
    const { class: _class, ...rest } = attrs;

    if (props.href) {
        return {
            ...rest,
            href: props.href,
            method: props.method,
            as: props.method === 'get' ? undefined : 'button',
            preserveScroll: props.preserveScroll,
        };
    }

    return { ...rest, type: props.type, disabled: isDisabled.value };
});
</script>

<template>
    <span class="group/iconbtn relative inline-flex">
        <component
            :is="component"
            v-bind="bindings"
            :aria-label="label"
            :class="[
                'inline-flex items-center justify-center transition-colors duration-150 ease-[var(--ease-out-soft)]',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                SIZES[size] ?? SIZES.md,
                VARIANTS[variant] ?? VARIANTS.neutral,
                isDisabled ? 'pointer-events-none opacity-40' : 'cursor-pointer',
                attrs.class,
            ]"
        >
            <LoaderCircle
                v-if="loading"
                class="animate-spin"
                :size="ICON_SIZES[size] ?? 16"
                aria-hidden="true"
            />
            <slot v-else :size="ICON_SIZES[size] ?? 16" />
        </component>

        <span
            role="tooltip"
            :class="[
                'pointer-events-none absolute left-1/2 z-30 -translate-x-1/2 whitespace-nowrap rounded-md bg-ink-900 px-2 py-1',
                'text-[11px] font-medium leading-none text-white opacity-0 shadow-float',
                'transition-opacity duration-150 ease-[var(--ease-out-soft)]',
                'group-hover/iconbtn:opacity-100 group-focus-within/iconbtn:opacity-100',
                tooltipPlacement === 'bottom' ? 'top-full mt-1.5' : 'bottom-full mb-1.5',
            ]"
        >
            {{ label }}
        </span>
    </span>
</template>
