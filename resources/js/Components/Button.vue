<script setup>
import { computed, useAttrs } from 'vue';
import { Link } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    /** primary | secondary | ghost | danger | success */
    variant: { type: String, default: 'primary' },
    /** sm | md | lg */
    size: { type: String, default: 'md' },
    /** Native button type — ignored when rendering a link. */
    type: { type: String, default: 'button' },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** Stretch to the full width of the parent. */
    block: { type: Boolean, default: false },
    /** 'button' | 'link' (Inertia <Link>) | 'a' (plain anchor). */
    as: { type: String, default: 'button' },
    href: { type: String, default: null },
    /** Inertia <Link> passthroughs. */
    method: { type: String, default: 'get' },
    preserveScroll: { type: Boolean, default: false },
    preserveState: { type: Boolean, default: false },
    only: { type: Array, default: () => [] },
    /** Accessible name when the button has no text (icon-only). */
    label: { type: String, default: null },
});

const attrs = useAttrs();

const VARIANTS = {
    primary:
        'bg-brand-600 text-white shadow-card hover:bg-brand-700 hover:shadow-card-hover active:bg-brand-800',
    secondary:
        'bg-white text-ink-700 border border-ink-200 shadow-card hover:bg-ink-50 hover:text-ink-900 hover:border-ink-300 active:bg-ink-100',
    ghost: 'bg-transparent text-ink-600 hover:bg-ink-100 hover:text-ink-900 active:bg-ink-200',
    danger: 'bg-danger-600 text-white shadow-card hover:bg-danger-700 hover:shadow-card-hover active:bg-danger-700',
    success:
        'bg-success-600 text-white shadow-card hover:bg-success-700 hover:shadow-card-hover active:bg-success-700',
};

const SIZES = {
    sm: 'h-8 gap-1.5 px-3 text-xs rounded-lg',
    md: 'h-10 gap-2 px-4 text-sm rounded-xl',
    lg: 'h-12 gap-2.5 px-5 text-sm rounded-xl',
};

const SPINNER_SIZES = { sm: 14, md: 16, lg: 18 };

const isDisabled = computed(() => props.disabled || props.loading);

const component = computed(() => {
    if (props.as === 'link' || (props.as === 'button' && props.href)) {
        return Link;
    }

    if (props.as === 'a') {
        return 'a';
    }

    return 'button';
});

const classes = computed(() => [
    'relative inline-flex select-none items-center justify-center whitespace-nowrap font-medium',
    'transition-[background-color,box-shadow,color,opacity] duration-200 ease-[var(--ease-out-soft)]',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
    SIZES[props.size] ?? SIZES.md,
    VARIANTS[props.variant] ?? VARIANTS.primary,
    props.block ? 'w-full' : '',
    isDisabled.value ? 'pointer-events-none opacity-55' : 'cursor-pointer',
    attrs.class,
]);

const bindings = computed(() => {
    const { class: _class, ...rest } = attrs;

    if (component.value === 'button') {
        return { ...rest, type: props.type, disabled: isDisabled.value };
    }

    if (component.value === 'a') {
        return { ...rest, href: props.href, 'aria-disabled': isDisabled.value || undefined };
    }

    return {
        ...rest,
        href: props.href,
        method: props.method,
        as: props.method === 'get' ? undefined : 'button',
        preserveScroll: props.preserveScroll,
        preserveState: props.preserveState,
        ...(props.only.length ? { only: props.only } : {}),
        'aria-disabled': isDisabled.value || undefined,
    };
});
</script>

<template>
    <component
        :is="component"
        v-bind="bindings"
        :class="classes"
        :aria-busy="loading || undefined"
        :aria-label="label ?? undefined"
    >
        <LoaderCircle
            v-if="loading"
            class="animate-spin"
            :size="SPINNER_SIZES[size] ?? 16"
            aria-hidden="true"
        />
        <slot v-else name="icon" />

        <span v-if="$slots.default" class="truncate"><slot /></span>

        <slot name="iconRight" />
    </component>
</template>
