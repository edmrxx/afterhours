<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Minus, TrendingDown, TrendingUp } from '@lucide/vue';
import Skeleton from './Skeleton.vue';

/*
| StatCard — the KPI tile used across the dashboard and reports.
|
|   <StatCard label="Confirmed bookings" :value="stats.confirmed"
|             delta="+12.4%" trend="up" :icon="CalendarCheck" />
*/

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: null },
    /** Secondary line under the value, e.g. "vs. last month". */
    hint: { type: String, default: null },
    /** Change indicator text, e.g. "+12.4%". */
    delta: { type: [String, Number], default: null },
    /** up | down | flat — controls the delta colour and arrow. */
    trend: { type: String, default: 'flat' },
    /** Whether "up" is good. Set false for metrics like cancellations. */
    positiveIsGood: { type: Boolean, default: true },
    /** A Lucide component. */
    icon: { type: [Object, Function], default: null },
    /** brand | success | warn | danger | info | ink — icon chip tone. */
    tone: { type: String, default: 'brand' },
    loading: { type: Boolean, default: false },
    /** Turn the whole tile into an Inertia link. */
    href: { type: String, default: null },
});

const ICON_TONES = {
    brand: 'bg-brand-50 text-brand-600',
    success: 'bg-success-50 text-success-600',
    warn: 'bg-warn-50 text-warn-600',
    danger: 'bg-danger-50 text-danger-600',
    info: 'bg-info-50 text-info-600',
    ink: 'bg-ink-100 text-ink-600',
};

const TREND_ICONS = { up: TrendingUp, down: TrendingDown, flat: Minus };

const trendClass = computed(() => {
    if (props.trend === 'flat' || props.delta === null) {
        return 'text-ink-500';
    }

    const good = props.trend === 'up' ? props.positiveIsGood : !props.positiveIsGood;

    return good ? 'text-success-600' : 'text-danger-600';
});

const component = computed(() => (props.href ? Link : 'div'));
</script>

<template>
    <component
        :is="component"
        :href="href ?? undefined"
        :class="[
            'group block rounded-xl border border-ink-200/70 bg-white p-5 shadow-card',
            'transition-shadow duration-200 ease-[var(--ease-out-soft)]',
            href
                ? 'hover:border-brand-200 hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500'
                : '',
        ]"
    >
        <div class="flex items-start justify-between gap-3">
            <p class="text-xs font-medium tracking-wide text-ink-500 uppercase">
                {{ label }}
            </p>

            <span
                v-if="icon"
                :class="[
                    'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                    ICON_TONES[tone] ?? ICON_TONES.brand,
                ]"
                aria-hidden="true"
            >
                <component :is="icon" :size="18" />
            </span>
        </div>

        <template v-if="loading">
            <Skeleton class="mt-4 h-8 w-24" />
            <Skeleton class="mt-3 h-3 w-32" />
        </template>

        <template v-else>
            <p class="mt-3 text-2xl font-semibold tracking-tight text-ink-900 tabular-nums">
                <slot name="value">{{ value ?? '—' }}</slot>
            </p>

            <div v-if="delta !== null || hint" class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1">
                <span
                    v-if="delta !== null"
                    :class="['inline-flex items-center gap-1 text-xs font-semibold', trendClass]"
                >
                    <component
                        :is="TREND_ICONS[trend] ?? TREND_ICONS.flat"
                        :size="14"
                        aria-hidden="true"
                    />
                    {{ delta }}
                </span>
                <span v-if="hint" class="text-xs text-ink-500">{{ hint }}</span>
            </div>
        </template>
    </component>
</template>
