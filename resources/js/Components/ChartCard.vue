<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { ChartColumn } from '@lucide/vue';
import EmptyState from './EmptyState.vue';
import Skeleton from './Skeleton.vue';

/*
| ChartCard — a card that wraps ApexCharts with loading and empty states.
|
| ApexCharts is ~500kB, so it is pulled in with defineAsyncComponent: Vite
| code-splits it into its own chunk that only downloads on pages that actually
| render a chart.
|
|   <ChartCard title="Bookings this month" type="area" :height="300"
|              :series="[{ name: 'Bookings', data: chart.data }]"
|              :categories="chart.labels" :loading="loadingChart" />
|
| Pass `options` to override anything in the shared theme.
*/

const ApexChart = defineAsyncComponent({
    loader: () => import('vue3-apexcharts'),
    // The card renders its own skeleton, so the async wrapper stays invisible.
    loadingComponent: { render: () => null },
    delay: 0,
});

const props = defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    /** Any ApexCharts type: area | bar | line | donut | pie | radialBar … */
    type: { type: String, default: 'area' },
    /** ApexCharts series — [{ name, data }] or a bare number array for pies. */
    series: { type: Array, default: () => [] },
    /** X-axis categories; ignored when `options.xaxis.categories` is supplied. */
    categories: { type: Array, default: () => [] },
    /** Labels for pie/donut charts. */
    labels: { type: Array, default: () => [] },
    /** Deep-merged over the shared theme. */
    options: { type: Object, default: () => ({}) },
    height: { type: [Number, String], default: 320 },
    loading: { type: Boolean, default: false },
    emptyTitle: { type: String, default: 'No data for this period' },
    emptyDescription: {
        type: String,
        default: 'Once bookings come in, the trend will appear here.',
    },
    /** Money/percentage suffix shown next to the y-axis values. */
    valuePrefix: { type: String, default: '' },
    valueSuffix: { type: String, default: '' },
});

/** Palette pulled from the CSS design tokens — never hardcoded. */
function palette() {
    if (typeof window === 'undefined') {
        return undefined;
    }

    const style = getComputedStyle(document.documentElement);
    const read = (name) => style.getPropertyValue(name).trim();

    return {
        brand: read('--color-brand-600'),
        brandSoft: read('--color-brand-400'),
        success: read('--color-success-500'),
        warn: read('--color-warn-500'),
        danger: read('--color-danger-500'),
        info: read('--color-info-500'),
        grid: read('--color-ink-200'),
        text: read('--color-ink-500'),
        font: read('--font-sans'),
    };
}

/** Recursive merge so callers can override one nested key without losing the rest. */
function merge(base, override) {
    const output = { ...base };

    Object.entries(override ?? {}).forEach(([key, value]) => {
        if (
            value &&
            typeof value === 'object' &&
            !Array.isArray(value) &&
            base[key] &&
            typeof base[key] === 'object' &&
            !Array.isArray(base[key])
        ) {
            output[key] = merge(base[key], value);

            return;
        }

        output[key] = value;
    });

    return output;
}

const isEmpty = computed(() => {
    if (props.series.length === 0) {
        return true;
    }

    return props.series.every((entry) => {
        if (typeof entry === 'number') {
            return entry === 0;
        }

        const data = Array.isArray(entry) ? entry : (entry?.data ?? []);

        return data.length === 0;
    });
});

const chartOptions = computed(() => {
    const colors = palette();

    const base = {
        chart: {
            type: props.type,
            fontFamily: colors?.font,
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: {
                enabled: true,
                speed: 400,
                easing: 'easeout',
            },
            parentHeightOffset: 0,
        },
        colors: colors
            ? [colors.brand, colors.success, colors.warn, colors.info, colors.danger]
            : undefined,
        dataLabels: { enabled: false },
        stroke: {
            curve: 'smooth',
            width: props.type === 'bar' ? 0 : 2.5,
            lineCap: 'round',
        },
        fill: {
            type: props.type === 'area' ? 'gradient' : 'solid',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.35,
                opacityTo: 0.02,
                stops: [0, 90, 100],
            },
        },
        grid: {
            borderColor: colors?.grid,
            strokeDashArray: 4,
            padding: { left: 8, right: 8, top: 0, bottom: 0 },
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: true } },
        },
        plotOptions: {
            bar: { borderRadius: 6, columnWidth: '52%', borderRadiusApplication: 'end' },
            pie: { donut: { size: '68%' } },
        },
        legend: {
            position: 'bottom',
            horizontalAlign: 'center',
            fontSize: '12px',
            markers: { size: 6 },
            labels: { colors: colors?.text },
            itemMargin: { horizontal: 10, vertical: 4 },
        },
        tooltip: {
            theme: 'light',
            style: { fontSize: '12px', fontFamily: colors?.font },
            y: {
                formatter: (value) =>
                    value === null || value === undefined
                        ? '—'
                        : `${props.valuePrefix}${Number(value).toLocaleString()}${props.valueSuffix}`,
            },
        },
        xaxis: {
            categories: props.categories,
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: {
                style: { colors: colors?.text, fontSize: '11px' },
                rotate: 0,
                hideOverlappingLabels: true,
            },
            tooltip: { enabled: false },
        },
        yaxis: {
            labels: {
                style: { colors: colors?.text, fontSize: '11px' },
                formatter: (value) =>
                    `${props.valuePrefix}${Number(value ?? 0).toLocaleString()}${props.valueSuffix}`,
            },
        },
        states: {
            hover: { filter: { type: 'lighten', value: 0.04 } },
            active: { filter: { type: 'none' } },
        },
        noData: { text: '' },
    };

    if (props.labels.length > 0) {
        base.labels = props.labels;
    }

    return merge(base, props.options);
});
</script>

<template>
    <section class="rounded-xl border border-ink-200/70 bg-white shadow-card">
        <header
            v-if="title || subtitle || $slots.actions"
            class="flex flex-wrap items-start justify-between gap-3 border-b border-ink-200/70 px-5 py-4"
        >
            <div class="min-w-0">
                <h3 v-if="title" class="truncate text-sm font-semibold text-ink-900">
                    {{ title }}
                </h3>
                <p v-if="subtitle" class="mt-0.5 text-xs text-ink-500">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div class="p-4 sm:p-5">
            <!-- Loading -->
            <div
                v-if="loading"
                class="flex flex-col justify-end gap-3"
                :style="{ height: `${typeof height === 'number' ? height : parseInt(height, 10) || 320}px` }"
                role="status"
                aria-live="polite"
                aria-busy="true"
            >
                <span class="sr-only">Loading chart…</span>
                <div class="flex flex-1 items-end gap-2.5 pb-6">
                    <Skeleton
                        v-for="n in 12"
                        :key="n"
                        rounded="md"
                        class="flex-1"
                        :style="{ height: `${28 + ((n * 37) % 62)}%` }"
                    />
                </div>
                <Skeleton class="h-3 w-full" />
            </div>

            <!-- Empty -->
            <EmptyState
                v-else-if="isEmpty"
                :icon="ChartColumn"
                :title="emptyTitle"
                :description="emptyDescription"
                size="sm"
            >
                <template v-if="$slots.emptyAction" #action>
                    <slot name="emptyAction" />
                </template>
            </EmptyState>

            <!-- Chart -->
            <ApexChart
                v-else
                :type="type"
                :height="height"
                :series="series"
                :options="chartOptions"
                width="100%"
            />
        </div>

        <footer v-if="$slots.footer" class="border-t border-ink-200/70 px-5 py-3">
            <slot name="footer" />
        </footer>
    </section>
</template>
