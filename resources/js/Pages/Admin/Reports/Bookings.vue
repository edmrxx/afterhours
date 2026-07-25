<script setup>
import { computed } from 'vue';
import { route } from 'ziggy-js';
import {
    CalendarRange,
    ClipboardList,
    Eye,
    FileDown,
    Filter,
    Percent,
    RotateCcw,
    Ticket,
    Wallet,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import ChartCard from '@/Components/ChartCard.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormDatePicker from '@/Components/FormDatePicker.vue';
import FormSelect from '@/Components/FormSelect.vue';
import IconButton from '@/Components/IconButton.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import { useDataTable } from '@/Composables/useDataTable';
import { usePermissions } from '@/Composables/usePermissions';

/*
| Bookings report.
|
| Every figure on this page is a SQL aggregate computed server-side — the
| paginated table below is a detail view, never the source of the totals.
*/

const props = defineProps({
    summary: { type: Object, required: true },
    byStatus: { type: Array, default: () => [] },
    byPeriod: { type: Array, default: () => [] },
    byCourt: { type: Array, default: () => [] },
    bookings: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    rangeLabel: { type: String, default: '' },
});

const { can } = usePermissions();

const RELOAD_KEYS = [
    'summary',
    'byStatus',
    'byPeriod',
    'byCourt',
    'bookings',
    'filters',
    'rangeLabel',
];

const table = useDataTable(
    'admin.reports.bookings',
    {
        date_from: props.filters.date_from,
        date_to: props.filters.date_to,
        court_id: '',
        status: '',
        basis: props.filters.basis,
        granularity: props.filters.granularity,
    },
    {
        sort: 'created_at',
        direction: 'desc',
        only: RELOAD_KEYS,
    },
);

/* Formatting ------------------------------------------------------------- */

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 2,
});

const count = new Intl.NumberFormat('en-PH');

const money = (value) => peso.format(Number(value ?? 0));

/* Charts ----------------------------------------------------------------- */

const periodLabels = computed(() => props.byPeriod.map((bucket) => bucket.label));

const bookingsSeries = computed(() => [
    { name: 'Bookings', data: props.byPeriod.map((bucket) => bucket.bookings) },
]);

/* Zero slices only clutter a donut — drop them, keep the order. */
const statusSlices = computed(() => props.byStatus.filter((row) => row.total > 0));

const statusSeries = computed(() => statusSlices.value.map((row) => row.total));

const statusLabels = computed(() => statusSlices.value.map((row) => row.label));

/* Export ----------------------------------------------------------------- */

const exportUrl = computed(() =>
    route('admin.reports.export', { type: 'bookings', ...table.params }),
);

/* Detail table ------------------------------------------------------------ */

const columns = [
    { key: 'code', label: 'Code', sortable: true },
    { key: 'court', label: 'Court' },
    { key: 'customer_name', label: 'Customer', sortable: true },
    { key: 'slot_label', label: 'Play window' },
    { key: 'amount', label: 'Amount', sortable: true, align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Booked', sortable: true, align: 'right' },
];
</script>

<template>
    <AppLayout title="Bookings report">
        <PageHeader
            title="Bookings report"
            :subtitle="`Booking volume, mix and value for ${rangeLabel}.`"
            :breadcrumbs="[{ label: 'Reports' }, { label: 'Bookings' }]"
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
                <Button :href="route('admin.reports.revenue')" variant="secondary">
                    <template #icon><Wallet :size="16" /></template>
                    Revenue report
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

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
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
                    v-model="table.filters.status"
                    label="Status"
                    size="sm"
                    placeholder="All statuses"
                    :options="options.statuses"
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
                label="Total bookings"
                :value="count.format(summary.total_bookings)"
                :icon="ClipboardList"
                tone="brand"
                :hint="rangeLabel"
                :loading="table.loading"
            />
            <StatCard
                label="Revenue"
                :value="money(summary.revenue)"
                :icon="Wallet"
                tone="success"
                :hint="`${count.format(summary.revenue_bookings)} confirmed or completed`"
                :loading="table.loading"
            />
            <StatCard
                label="Average booking"
                :value="money(summary.average_value)"
                :icon="Ticket"
                tone="info"
                hint="Across revenue-bearing bookings"
                :loading="table.loading"
            />
            <StatCard
                label="Conversion"
                :value="`${summary.conversion_rate}%`"
                :icon="Percent"
                tone="warn"
                :hint="`${count.format(summary.pending)} still awaiting payment or checks`"
                :loading="table.loading"
            />
        </div>

        <!-- Charts -->
        <div class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="min-w-0 xl:col-span-2">
                <ChartCard
                    title="Bookings over time"
                    :subtitle="rangeLabel"
                    type="bar"
                    :series="bookingsSeries"
                    :categories="periodLabels"
                    :loading="table.loading"
                    :height="300"
                    empty-title="No bookings in this period"
                    empty-description="Widen the date range or clear the filters to see activity."
                />
            </div>

            <ChartCard
                title="Status mix"
                subtitle="Share of bookings by state"
                type="donut"
                :series="statusSeries"
                :labels="statusLabels"
                :loading="table.loading"
                :height="300"
                empty-title="Nothing to break down yet"
                empty-description="Bookings will be grouped by their state here."
            />
        </div>

        <!-- Per court -->
        <Card
            title="By court"
            subtitle="Where the demand is concentrated."
            padding="none"
            class="mb-5"
        >
            <EmptyState
                v-if="!byCourt.length"
                :icon="CalendarRange"
                size="sm"
                title="No court activity in this period"
                description="Once bookings are taken, each court's share appears here."
            />

            <div v-else class="w-full overflow-x-auto">
                <table class="w-full min-w-[36rem] border-collapse">
                    <thead class="bg-ink-50 text-ink-500">
                        <tr class="border-b border-ink-200/70">
                            <th scope="col" class="px-5 py-3 text-left text-xs font-semibold tracking-wide uppercase">
                                Court
                            </th>
                            <th scope="col" class="px-5 py-3 text-right text-xs font-semibold tracking-wide uppercase">
                                Bookings
                            </th>
                            <th scope="col" class="px-5 py-3 text-right text-xs font-semibold tracking-wide uppercase">
                                Revenue
                            </th>
                            <th scope="col" class="w-56 px-5 py-3 text-left text-xs font-semibold tracking-wide uppercase">
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
                            <td class="px-5 py-3 text-right font-medium tabular-nums">
                                {{ money(row.revenue) }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2.5">
                                    <div
                                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-ink-100"
                                        role="presentation"
                                    >
                                        <div
                                            class="h-full rounded-full bg-brand-500 transition-[width] duration-500 ease-[var(--ease-out-soft)]"
                                            :style="{ width: `${Math.max(row.share, 1)}%` }"
                                        />
                                    </div>
                                    <span class="w-12 shrink-0 text-right text-xs text-ink-500 tabular-nums">
                                        {{ row.share }}%
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- Detail -->
        <DataTable
            :columns="columns"
            :rows="bookings.data"
            :loading="table.loading"
            :sort="table.sort"
            :direction="table.direction"
            v-model:search="table.search"
            v-model:per-page="table.perPage"
            :filtered="table.hasActiveFilters"
            search-placeholder="Search code, customer, phone or reference…"
            :empty-icon="ClipboardList"
            empty-title="No bookings match this report"
            empty-description="Adjust the date range, court or status above."
            min-width="min-w-[64rem]"
            @sort="table.sortBy"
        >
            <template #cell-code="{ row }">
                <span class="font-mono text-xs font-semibold text-ink-900">{{ row.code }}</span>
            </template>

            <template #cell-customer_name="{ row }">
                <div class="min-w-0">
                    <p class="truncate font-medium text-ink-900">{{ row.customer_name }}</p>
                    <p class="truncate text-[11px] text-ink-400">{{ row.customer_phone }}</p>
                </div>
            </template>

            <template #cell-slot_label="{ row }">
                <span class="whitespace-nowrap text-ink-600 tabular-nums">{{ row.slot_label }}</span>
            </template>

            <template #cell-amount="{ row }">
                <span class="font-medium text-ink-900 tabular-nums">{{ money(row.amount) }}</span>
            </template>

            <template #cell-status="{ row }">
                <Badge size="xs" :status="row.status" />
            </template>

            <template #cell-created_at="{ row }">
                <span class="whitespace-nowrap text-ink-600 tabular-nums">
                    {{ row.created_at_label }}
                </span>
            </template>

            <template #actions="{ row }">
                <IconButton variant="view" label="Open booking" :href="row.url">
                    <template #default="{ size }"><Eye :size="size" /></template>
                </IconButton>
            </template>

            <template #footer>
                <Pagination :paginator="bookings" :only="RELOAD_KEYS" label="bookings" />
            </template>
        </DataTable>
    </AppLayout>
</template>
