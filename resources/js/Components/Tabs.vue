<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/*
| Tabs — works two ways:
|
| 1. State driven (panels on one page):
|      <Tabs v-model="tab" :tabs="[{ key:'details', label:'Details' }, …]" />
|
| 2. Route driven (settings sections, report types) — give each tab an `href`
|    and an `active` flag and it renders Inertia links instead of buttons:
|      <Tabs :tabs="[{ key:'payment', label:'Payment', href: route('admin.settings.payment'),
|                      active: route().current('admin.settings.payment') }]" />
*/

const props = defineProps({
    /** [{ key, label, icon?, badge?, href?, active?, disabled? }] */
    tabs: { type: Array, required: true },
    /** v-model — the active tab key in state-driven mode. */
    modelValue: { type: [String, Number], default: null },
    /** underline | pills */
    variant: { type: String, default: 'underline' },
    /** Stretch tabs to fill the row. */
    fill: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change']);

const isActive = (tab) =>
    tab.href ? Boolean(tab.active) : tab.key === props.modelValue;

function select(tab) {
    if (tab.disabled || tab.href) {
        return;
    }

    emit('update:modelValue', tab.key);
    emit('change', tab.key);
}

const isPills = computed(() => props.variant === 'pills');

const base =
    'group relative inline-flex items-center gap-2 whitespace-nowrap text-sm font-medium transition-all duration-200 ease-[var(--ease-out-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500';

function tabClass(tab) {
    const active = isActive(tab);

    if (isPills.value) {
        return [
            base,
            'rounded-lg px-3 py-1.5',
            active
                ? 'bg-white text-ink-900 shadow-card'
                : 'text-ink-500 hover:text-ink-800',
            tab.disabled ? 'pointer-events-none opacity-45' : 'cursor-pointer',
            props.fill ? 'flex-1 justify-center' : '',
        ];
    }

    return [
        base,
        '-mb-px border-b-2 px-1 py-3',
        active
            ? 'border-brand-600 text-brand-700'
            : 'border-transparent text-ink-500 hover:border-ink-300 hover:text-ink-800',
        tab.disabled ? 'pointer-events-none opacity-45' : 'cursor-pointer',
        props.fill ? 'flex-1 justify-center' : '',
    ];
}
</script>

<template>
    <div
        role="tablist"
        :class="[
            'flex items-center overflow-x-auto',
            isPills
                ? 'gap-1 rounded-xl bg-ink-100 p-1'
                : 'gap-6 border-b border-ink-200',
        ]"
    >
        <component
            :is="tab.href ? Link : 'button'"
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href ?? undefined"
            :type="tab.href ? undefined : 'button'"
            role="tab"
            :aria-selected="isActive(tab)"
            :aria-disabled="tab.disabled || undefined"
            :class="tabClass(tab)"
            @click="select(tab)"
        >
            <component
                :is="tab.icon"
                v-if="tab.icon"
                :size="16"
                class="shrink-0"
                aria-hidden="true"
            />
            {{ tab.label }}
            <span
                v-if="tab.badge !== undefined && tab.badge !== null"
                :class="[
                    'ml-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold tabular-nums',
                    isActive(tab) ? 'bg-brand-100 text-brand-700' : 'bg-ink-100 text-ink-600',
                ]"
            >
                {{ tab.badge }}
            </span>
        </component>
    </div>
</template>
