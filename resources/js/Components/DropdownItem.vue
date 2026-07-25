<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/*
| DropdownItem — a single row inside <Dropdown>. Renders a button, an Inertia
| <Link> (when `href` is set) or a plain anchor (`external`).
*/

const props = defineProps({
    /** default | danger | success | warn — text tone. */
    variant: { type: String, default: 'default' },
    /** A Lucide component rendered ahead of the label. */
    icon: { type: [Object, Function], default: null },
    href: { type: String, default: null },
    method: { type: String, default: 'get' },
    external: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** Right-aligned hint, e.g. a shortcut or a count. */
    hint: { type: String, default: null },
    /** Show a hairline above this item. */
    divided: { type: Boolean, default: false },
});

// The optional divider below makes this template render TWO sibling root
// nodes whenever `divided` is true. Vue only auto-forwards attrs/listeners
// (like a caller's @click) to a single root element — with two, it drops
// them silently rather than warning loudly, so `divided` items would never
// fire their handler. Take manual control of attrs instead, and apply them
// to the interactive element specifically, never the decorative hairline.
defineOptions({ inheritAttrs: false });

const VARIANTS = {
    default: 'text-ink-700 hover:bg-ink-100 hover:text-ink-900',
    danger: 'text-danger-600 hover:bg-danger-50 hover:text-danger-700',
    success: 'text-success-600 hover:bg-success-50 hover:text-success-700',
    warn: 'text-warn-600 hover:bg-warn-50 hover:text-warn-700',
};

const component = computed(() => {
    if (props.disabled || !props.href) {
        return 'button';
    }

    return props.external ? 'a' : Link;
});

const bindings = computed(() => {
    if (component.value === 'button') {
        return { type: 'button', disabled: props.disabled };
    }

    if (component.value === 'a') {
        return { href: props.href, target: '_blank', rel: 'noopener noreferrer' };
    }

    return {
        href: props.href,
        method: props.method,
        as: props.method === 'get' ? undefined : 'button',
    };
});
</script>

<template>
    <div v-if="divided" class="my-1.5 h-px bg-ink-200/70" role="separator" />

    <component
        :is="component"
        v-bind="{ ...$attrs, ...bindings }"
        role="menuitem"
        tabindex="-1"
        :aria-disabled="disabled || undefined"
        :class="[
            'flex w-full cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm font-medium',
            'transition-colors duration-150 ease-[var(--ease-out-soft)]',
            'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500',
            disabled ? 'pointer-events-none opacity-45' : '',
            VARIANTS[variant] ?? VARIANTS.default,
        ]"
    >
        <component :is="icon" v-if="icon" :size="16" class="shrink-0" aria-hidden="true" />
        <span class="min-w-0 flex-1 truncate"><slot /></span>
        <span v-if="hint" class="shrink-0 text-xs text-ink-400">{{ hint }}</span>
    </component>
</template>
