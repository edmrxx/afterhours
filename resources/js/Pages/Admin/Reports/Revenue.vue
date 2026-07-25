<script setup>
import { computed } from 'vue';
import { route } from 'ziggy-js';
import {
    Banknote,
    Building2,
    ClipboardList,
    Coins,
    FileDown,
    Filter,
    RotateCcw,
    Ticket,
    Trophy,
    Wallet,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import ChartCard from '@/Components/ChartCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormDatePicker from '@/Components/FormDatePicker.vue';
import FormSelect from '@/Components/FormSelect.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import { useDataTable } from '@/Composables/useDataTable';
import { usePermissions } from '@/Composables/usePermissions';

/*
| Revenue report.
|
| Only confirmed and completed bookings count as revenue (ARCHITECTURE.md §4) —
| a held or rejected booking has taken no money. Every figure is a server-side
| aggregate over that subset.
*/

const props = defineProps({
    summary: { type: Object, required: true },
    byPeriod: { type: Array, default: () => [] },
    byCourt: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    rangeLabel: { type: String, default: '' },
});

const { can } = usePermissions();

const RELOAD_KEYS = ['summary', 'byPeriod', 'byCourt', 'filters', 'rangeLabel'];

const table = useDataTable(
    'admin.reports.revenue',
    {
        date_from: props.filters.date_from,
        date_to: props.filters.date_to,
        court_id: '',
        basis: props.filters.basis,
        granularity: props.filters.granularity,
    },
    {
        only: RELOAD_KEYS,
    },
);

/* Formatting ------------------------------------------------------------- */

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 2,
});

const compactPeso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    notation: 'compact',
    maximumFractionDigits: 1,
});

const count = new Intl.NumberFormat('en-PH');

const money = (value) => peso.format(Number(value ?? 0));

/* Charts ----------------------------------------------------------------- */

const periodLabels = computed(() => props.byPeriod.map((bucket) => bucket.label));

const revenueSeries = computed(() => [
    { name: 'Revenue', data: props.byPeriod.map((bucket) => bucket.revenue) },
]);

const courtSeries = computed(() => [
    { name: 'Revenue', data: props.byCourt.map((row) => row.revenue) },
]);

const courtLabels = computed(() => props.byCourt.map((row) => row.court));

/* The y-axis is money, so format it as money rather than as a bare count. */
const moneyAxis = {
    yaxis: {
        labels: {
            formatter: (value) => compactPeso.format(Number(value ?? 0)),
        },
    },
    tooltip: {
        y: { formatter: (value) => peso.format(Number(value ?? 0)) },
    },
};

const courtChartOptions = {
    ...moneyAxis,
    plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: '58%' } },
};

/* Summary ---------------------------------------------------------------- */

const trend = computed(() => {
    if (props.summary.change_percent === null || props.summary.change_percent === 0) {
        return 'flat';
    }

    return props.summary.change_percent > 0 ? 'up' : 'down';
});

const delta = computed(() => {
    if (props.summary.change_percent === null) {
        return null;
    }

    const value = props.summary.change_percent;

    return `${value > 0 ? '+' : ''}${value}%`;
});

const totalBookings = computed(() =>
    props.byCourt.reduce((carry, row) => carry + row.bookings, 0),
);

/* Export ----------------------------------------------------------------- */

const exportUrl = computed(() =>
    route('admin.reports.export', { type: 'revenue', ...table.params }),
);
</script>

<template>
    <AppLayout title="Revenue report">
        <PageHeader
            title="Revenue report"
            :subtitle="`Confirmed and completed bookings for ${rangeLabel}.`"
            :breadcrumbs="[{ label: 'Reports' }, { label: 'Revenue' }]"
        >
            <template #actions>
                <Button
                    v-if="can('reports.export')"
                    as="a"
                    variant="secondary"
                    :href="exportUrl"
                    download
                >
                    <template #icon><FileDown :size="16" /></template>
                    Export CSV
                </Button>
                <Button :href="route('admin.reports.bookings')" variant="secondary">
                    <template #icon><ClipboardList :size="16" /></template>
                    Bookings report
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <section
            class="mb-5 rounded-xl border border-ink-200/70 bg-white p-4 shadow-card sm:p-5"
            aria-label="Report filters"
        >
            <div class="mb-3 flex items-center gap-2 text-xs font-semibold tracking-wide text-ink-500 uppercase">
                <Filter :size="14" aria-hidden="true" />
                Filters
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <FormDatePicker
                    v-model="table.filters.date_from"
                    label="From"
                    size="sm"
                    :max="table.filters.date_to || undefined"
                />
                <FormDatePicker
                    v-model="table.filters.date_to"
                    label="To"
                    size="sm"
                    :min="table.filters.date_from || undefined"
                />
                <FormSelect
                    v-model="table.filters.court_id"
                    label="Court"
                    size="sm"
                    placeholder="All courts"
                    :options="options.courts"
                />
                <FormSelect
                    v-model="table.filters.basis"
                    label="Date basis"
                    size="sm"
                    :options="options.bases"
                    hint="Booked or played"
                />
                <FormSelect
                    v-model="table.filters.granularity"
                    label="Group by"
                    size="sm"
                    :options="options.granularities"
                />
            </div>

            <div v-if="table.hasActiveFilters" class="mt-4 flex justify-end">
                <Button variant="secondary" size="sm" @click="table.reset()">
                    <template #icon><RotateCcw :size="14" /></template>
                    Reset
                </Button>
            </div>
        </section>

        <!-- Headline numbers -->
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Revenue"
                :value="money(summary.revenue)"
                :icon="Wallet"
                tone="success"
                :delta="delta"
                :trend="trend"
                :hint="`vs. ${money(summary.previous_revenue)} previous period`"
                :loading="table.loading"
            />
            <StatCard
                label="Paid bookings"
                :value="count.format(summary.bookings)"
                :icon="Ticket"
                tone="brand"
                hint="Confirmed or completed"
                :loading="table.loading"
            />
            <StatCard
                label="Average booking"
                :value="money(summary.average_value)"
                :icon="Banknote"
                tone="info"
                :hint="`Across ${count.format(summary.courts)} court${summary.courts === 1 ? '' : 's'}`"
                :loading="table.loading"
            />
            <StatCard
                label="Best period"
                :value="summary.peak_label ?? '—'"
                :icon="Trophy"
                tone="warn"
                :hint="summary.peak_label ? money(summary.peak_revenue) : 'No revenue recorded yet'"
                :loading="table.loading"
            />
        </div>

        <!-- Trend -->
        <ChartCard
            class="mb-5"
            title="Revenue over time"
            :subtitle="rangeLabel"
            type="area"
            :series="revenueSeries"
            :categories="periodLabels"
            :options="moneyAxis"
            :loading="table.loading"
            :height="320"
            empty-title="No revenue in this period"
            empty-description="Only confirmed and completed bookings are counted — widen the range to see more."
        />

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <!-- By court chart -->
            <ChartCard
                title="Revenue by court"
                subtitle="Highest earner first"
                type="bar"
                :series="courtSeries"
                :categories="courtLabels"
                :options="courtChartOptions"
                :loading="table.loading"
                :height="320"
                empty-title="No court revenue yet"
                empty-description="Each court's contribution appears here once bookings are confirmed."
            />

            <!-- By court table -->
            <Card title="Court breakdown" subtitle="Totals behind the chart." padding="none">
                <EmptyState
                    v-if="!byCourt.length"
                    :icon="Building2"
                    size="sm"
                    title="Nothing to break down"
                    description="No confirmed or completed bookings fall inside this range."
                />

                <div v-else class="w-full overflow-x-auto">
                    <table class="w-full min-w-[34rem] border-collapse">
                        <thead class="bg-ink-50 text-ink-500">
                            <tr class="border-b border-ink-200/70">
                                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold tracking-wide uppercase">
                                    Court
                                </th>
                                <th scope="col" class="px-5 py-3 text-right text-xs font-semibold tracking-wide uppercase">
                                    Bookings
                                </th>
                                <th scope="col" class="px-5 py-3 text-right text-xs font-semibold tracking-wide uppercase">
                                    Average
                                </th>
                                <th scope="col" class="px-5 py-3 text-right text-xs font-semibold tracking-wide uppercase">
                                    Revenue
                                </th>
                                <th scope="col" class="px-5 py-3 text-right text-xs font-semibold tracking-wide uppercase">
                                    Share
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-ink-200/70 text-sm text-ink-700">
                            <tr
                                v-for="row in byCourt"
                                :key="row.court_id"
                                class="transition-colors duration-150 ease-[var(--ease-out-soft)] hover:bg-ink-50"
                            >
                                <td class="px-5 py-3 font-medium text-ink-900">{{ row.court }}</td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ count.format(row.bookings) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ money(row.average) }}
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-ink-900 tabular-nums">
                                    {{ money(row.revenue) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">{{ row.share }}%</td>
                            </tr>
                        </tbody>

                        <tfoot class="border-t border-ink-200 bg-ink-50/60 text-sm">
                            <tr>
                                <th scope="row" class="px-5 py-3 text-left font-semibold text-ink-900">
                                    Total
                                </th>
                                <td class="px-5 py-3 text-right font-semibold text-ink-900 tabular-nums">
                                    {{ count.format(totalBookings) }}
                                </td>
                                <td class="px-5 py-3" />
                                <td class="px-5 py-3 text-right font-semibold text-ink-900 tabular-nums">
                                    {{ money(summary.revenue) }}
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-ink-900 tabular-nums">
                                    100%
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </Card>
        </div>

        <!-- Period breakdown -->
        <Card
            class="mt-5"
            title="Period breakdown"
            :subtitle="`Revenue grouped ${filters.granularity === 'month' ? 'by month' : filters.granularity === 'week' ? 'by week' : 'by day'}.`"
            padding="none"
        >
            <EmptyState
                v-if="!byPeriod.length"
                :icon="Coins"
                size="sm"
                title="No periods to show"
                description="Choose a date range to break revenue down over time."
            />

            <div v-else class="max-h-96 w-full overflow-auto">
                <table class="w-full min-w-[30rem] border-collapse">
                    <thead class="sticky top-0 z-10 bg-ink-50 text-ink-500">
                        <tr class="border-b border-ink-200/70">
                            <th scope="col" class="px-5 py-3 text-left text-xs font-semibold tracking-wide uppercase">
                                Period
                            </th>
                            <th scope="col" class="px-5 py-3 text-right text-xs font-semibold tracking-wide uppercase">
                                Bookings
                            </th>
                            <th scope="col" class="px-5 py-3 text-right text-xs font-semibold tracking-wide uppercase">
                                Revenue
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-ink-200/70 text-sm text-ink-700">
                        <tr
                            v-for="bucket in byPeriod"
                            :key="bucket.period"
                            :class="[
                                'transition-colors duration-150 ease-[var(--ease-out-soft)] hover:bg-ink-50',
                                bucket.revenue === 0 ? 'text-ink-400' : '',
                            ]"
                        >
                            <td class="px-5 py-2.5 whitespace-nowrap">
                                {{ bucket.label }}
                                <span class="ml-1.5 text-[11px] text-ink-400 tabular-nums">
                                    {{ bucket.period }}
                                </span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums">
                                {{ count.format(bucket.bookings) }}
                            </td>
                            <td class="px-5 py-2.5 text-right font-medium tabular-nums">
                                {{ money(bucket.revenue) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </AppLayout>
</template>
