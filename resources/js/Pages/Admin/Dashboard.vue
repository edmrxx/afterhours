<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import axios from 'axios';
import {
    Activity,
    ArrowRight,
    CalendarCheck,
    CalendarClock,
    ClipboardCheck,
    Clock,
    Flame,
    Gauge,
    History,
    TrendingUp,
    Wallet,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import ChartCard from '@/Components/ChartCard.vue';
import StatCard from '@/Components/StatCard.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Skeleton from '@/Components/Skeleton.vue';
import { usePermissions } from '@/Composables/usePermissions';
import { useSwal } from '@/Composables/useSwal';
import { statusTone } from '@/Composables/useStatus';

/*
| Admin dashboard.
|
| Stat cards, feeds and the initial chart window arrive as Inertia props. The
| 7/30/90 switcher then swaps only the chart payload over XHR, so changing the
| range never re-renders the page or refetches the feeds.
*/

const props = defineProps({
    stats: { type: Object, required: true },
    activity: { type: Array, default: () => [] },
    upcoming: { type: Array, default: () => [] },
    charts: { type: Object, required: true },
    range: { type: Number, default: 30 },
    ranges: { type: Array, default: () => [7, 30, 90] },
    generatedAt: { type: String, default: null },
});

const page = usePage();
const { can } = usePermissions();
const { toastError } = useSwal();

const PESO = '₱';

/* ------------------------------------------------------------------ */
/* Range switch                                                        */
/* ------------------------------------------------------------------ */

const chartData = ref(props.charts);
const activeRange = ref(props.range);
const loadingCharts = ref(false);

// Keep local state honest if the page is revisited or partially reloaded.
watch(
    () => props.charts,
    (value) => {
        chartData.value = value;
        activeRange.value = value?.days ?? props.range;
    },
);

async function selectRange(days) {
    if (days === activeRange.value || loadingCharts.value) {
        return;
    }

    const previous = activeRange.value;

    activeRange.value = days;
    loadingCharts.value = true;

    try {
        const { data } = await axios.get(route('admin.dashboard.chart', { days }));

        chartData.value = data;
        activeRange.value = data?.days ?? days;
    } catch {
        activeRange.value = previous;
        toastError('Could not load that range. Please try again.');
    } finally {
        loadingCharts.value = false;
    }
}

/* ------------------------------------------------------------------ */
/* Theme-token colours for the charts                                  */
/* ------------------------------------------------------------------ */

const TONE_VARS = {
    brand: '--color-brand-500',
    success: '--color-success-500',
    warn: '--color-warn-500',
    danger: '--color-danger-500',
    info: '--color-info-500',
    ink: '--color-ink-400',
};

/** Resolve a design token to a real colour — never a hardcoded hex. */
function token(name) {
    if (typeof window === 'undefined') {
        return undefined;
    }

    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || undefined;
}

/* ------------------------------------------------------------------ */
/* Derived chart inputs                                                */
/* ------------------------------------------------------------------ */

const trend = computed(() => chartData.value?.revenue_trend ?? {});
const statusMix = computed(() => chartData.value?.status_mix ?? {});
const perCourt = computed(() => chartData.value?.per_court ?? {});
const peakHours = computed(() => chartData.value?.peak_hours ?? {});

const rangeLabel = computed(() => chartData.value?.range_label ?? `Last ${activeRange.value} days`);

/** Empty arrays make ChartCard fall through to its designed empty state. */
const revenueSeries = computed(() =>
    trend.value.has_data ? [{ name: 'Revenue', data: trend.value.revenue ?? [] }] : [],
);

const revenueOptions = computed(() => ({
    stroke: { curve: 'smooth', width: 3 },
    fill: {
        type: 'gradient',
        gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.02, stops: [0, 90, 100] },
    },
    markers: { size: 0, hover: { size: 5 } },
    xaxis: { tickAmount: activeRange.value > 14 ? 6 : 7 },
    chart: { animations: { enabled: true, speed: 600, easing: 'easeout' } },
}));

const statusSeries = computed(() => (statusMix.value.has_data ? (statusMix.value.data ?? []) : []));

const statusOptions = computed(() => ({
    colors: (statusMix.value.statuses ?? []).map(
        (status) => token(TONE_VARS[statusTone(status)] ?? TONE_VARS.ink),
    ),
    stroke: { width: 0 },
    plotOptions: {
        pie: {
            donut: {
                size: '70%',
                labels: {
                    show: true,
                    value: {
                        fontSize: '22px',
                        fontWeight: 600,
                        color: token('--color-ink-900'),
                    },
                    total: {
                        show: true,
                        label: 'Bookings',
                        fontSize: '12px',
                        color: token('--color-ink-500'),
                        formatter: () => String(statusMix.value.total ?? 0),
                    },
                },
            },
        },
    },
    tooltip: { y: { formatter: (value) => `${Number(value).toLocaleString()} bookings` } },
}));

const courtSeries = computed(() =>
    perCourt.value.has_data ? [{ name: 'Bookings', data: perCourt.value.data ?? [] }] : [],
);

const courtOptions = computed(() => ({
    plotOptions: {
        bar: { horizontal: true, borderRadius: 6, barHeight: '58%', borderRadiusApplication: 'end' },
    },
    colors: [token('--color-brand-500')],
    grid: { xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
    xaxis: { tickAmount: 5 },
    tooltip: { y: { formatter: (value) => `${Number(value).toLocaleString()} bookings` } },
}));

/* ------------------------------------------------------------------ */
/* Peak hours heat strip                                               */
/* ------------------------------------------------------------------ */

const hours = computed(() => peakHours.value.hours ?? []);

/** Every third hour gets an axis tick — 24 labels would collide on mobile. */
const tickHours = computed(() => hours.value.filter((hour) => hour.hour % 3 === 0));

const activeHourCount = computed(() => hours.value.filter((hour) => hour.count > 0).length);

function heatClass(hour) {
    if (hour.count === 0) {
        return 'bg-transparent';
    }

    if (hour.intensity >= 0.75) return 'bg-brand-600';
    if (hour.intensity >= 0.5) return 'bg-brand-500';
    if (hour.intensity >= 0.25) return 'bg-brand-400';

    return 'bg-brand-300';
}

function heatHeight(hour) {
    if (hour.count === 0) {
        return '0%';
    }

    return `${Math.max(Math.round(hour.intensity * 100), 12)}%`;
}

/* ------------------------------------------------------------------ */
/* Feeds                                                               */
/* ------------------------------------------------------------------ */

const ACTION_TONES = {
    create: 'success',
    update: 'info',
    delete: 'danger',
    login: 'brand',
    logout: 'ink',
    view: 'ink',
    activate: 'success',
    deactivate: 'warn',
};

const actionTone = (action) => ACTION_TONES[action] ?? 'ink';

const canViewBookings = computed(() => can('bookings.view'));
const canViewAudit = computed(() => can('audit.view'));

/* ------------------------------------------------------------------ */
/* Header copy                                                         */
/* ------------------------------------------------------------------ */

const firstName = computed(() => (page.props.auth?.user?.name ?? '').split(' ')[0] || 'there');

const greeting = computed(() => {
    const hour = new Date().getHours();

    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';

    return 'Good evening';
});

const updatedAt = computed(() => {
    if (!props.generatedAt) {
        return null;
    }

    const date = new Date(props.generatedAt);

    return Number.isNaN(date.getTime())
        ? null
        : date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
});
</script>

<template>
    <AppLayout title="Dashboard">
        <PageHeader
            title="Dashboard"
            :subtitle="`${greeting}, ${firstName}. Here is how the courts are performing.`"
        >
            <template #actions>
                <span v-if="updatedAt" class="hidden items-center gap-1.5 text-xs text-ink-500 sm:inline-flex">
                    <Clock :size="14" aria-hidden="true" />
                    Updated {{ updatedAt }}
                </span>

                <div
                    class="inline-flex items-center rounded-xl border border-ink-200 bg-white p-1 shadow-card"
                    role="group"
                    aria-label="Chart range"
                >
                    <button
                        v-for="option in ranges"
                        :key="option"
                        type="button"
                        :aria-pressed="activeRange === option"
                        :disabled="loadingCharts"
                        :class="[
                            'cursor-pointer rounded-lg px-3 py-1.5 text-xs font-semibold',
                            'transition-colors duration-200 ease-[var(--ease-out-soft)]',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                            loadingCharts ? 'opacity-70' : '',
                            activeRange === option
                                ? 'bg-brand-600 text-white shadow-card'
                                : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900',
                        ]"
                        @click="selectRange(option)"
                    >
                        {{ option }}d
                    </button>
                </div>
            </template>
        </PageHeader>

        <!-- ------------------------------------------------------------ -->
        <!-- Stat cards                                                    -->
        <!-- ------------------------------------------------------------ -->
        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Key metrics">
            <StatCard
                label="Bookings today"
                :value="stats.bookings_today.display"
                :delta="stats.bookings_today.delta"
                :trend="stats.bookings_today.trend"
                :hint="stats.bookings_today.hint"
                :icon="CalendarCheck"
                tone="brand"
            />

            <StatCard
                label="Pending verification"
                :value="stats.pending_verification.display"
                :delta="stats.pending_verification.delta"
                :trend="stats.pending_verification.trend"
                :hint="stats.pending_verification.hint"
                :positive-is-good="false"
                :icon="ClipboardCheck"
                tone="warn"
                :href="canViewBookings ? route('admin.bookings.index', { status: 'pending_verification' }) : null"
            />

            <StatCard
                label="Revenue this month"
                :value="stats.revenue_month.display"
                :delta="stats.revenue_month.delta"
                :trend="stats.revenue_month.trend"
                :hint="stats.revenue_month.hint"
                :icon="Wallet"
                tone="success"
            />

            <StatCard
                label="Court utilisation"
                :value="stats.utilisation_week.display"
                :delta="stats.utilisation_week.delta"
                :trend="stats.utilisation_week.trend"
                :hint="stats.utilisation_week.hint"
                :icon="Gauge"
                tone="info"
            />
        </section>

        <!-- ------------------------------------------------------------ -->
        <!-- Revenue trend + status mix                                    -->
        <!-- ------------------------------------------------------------ -->
        <section class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
            <div class="xl:col-span-2">
                <ChartCard
                    title="Revenue trend"
                    :subtitle="`Confirmed and completed bookings · ${rangeLabel}`"
                    type="area"
                    :height="320"
                    :series="revenueSeries"
                    :categories="trend.labels ?? []"
                    :options="revenueOptions"
                    :loading="loadingCharts"
                    :value-prefix="PESO"
                    empty-title="No revenue yet"
                    empty-description="Confirmed bookings will plot here as soon as your first payment is verified."
                >
                    <template #footer>
                        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <div>
                                <dt class="text-[11px] font-medium tracking-wide text-ink-500 uppercase">
                                    Total
                                </dt>
                                <dd class="mt-0.5 text-sm font-semibold text-ink-900 tabular-nums">
                                    {{ trend.total_display ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-medium tracking-wide text-ink-500 uppercase">
                                    Daily average
                                </dt>
                                <dd class="mt-0.5 text-sm font-semibold text-ink-900 tabular-nums">
                                    {{ trend.average_display ?? '—' }}
                                </dd>
                            </div>
                            <div class="col-span-2 sm:col-span-1">
                                <dt class="text-[11px] font-medium tracking-wide text-ink-500 uppercase">
                                    Best day
                                </dt>
                                <dd class="mt-0.5 truncate text-sm font-semibold text-ink-900">
                                    <template v-if="trend.best_day">
                                        {{ trend.best_day }}
                                        <span class="font-normal text-ink-500">
                                            · {{ trend.best_day_display }}
                                        </span>
                                    </template>
                                    <template v-else>—</template>
                                </dd>
                            </div>
                        </dl>
                    </template>
                </ChartCard>
            </div>

            <ChartCard
                title="Booking mix"
                :subtitle="`By status · ${rangeLabel}`"
                type="donut"
                :height="320"
                :series="statusSeries"
                :labels="statusMix.labels ?? []"
                :options="statusOptions"
                :loading="loadingCharts"
                empty-title="No bookings yet"
                empty-description="The status breakdown appears once reservations start coming in."
            />
        </section>

        <!-- ------------------------------------------------------------ -->
        <!-- Per court + peak hours                                        -->
        <!-- ------------------------------------------------------------ -->
        <section class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <ChartCard
                title="Bookings per court"
                :subtitle="rangeLabel"
                type="bar"
                :height="320"
                :series="courtSeries"
                :categories="perCourt.labels ?? []"
                :options="courtOptions"
                :loading="loadingCharts"
                empty-title="No court activity"
                empty-description="Once a court is booked, its share of demand shows up here."
            />

            <Card padding="none">
                <template #header>
                    <div class="min-w-0">
                        <h3 class="truncate text-sm font-semibold text-ink-900">Peak hours</h3>
                        <p class="mt-0.5 text-xs text-ink-500">
                            When play actually happens · {{ rangeLabel }}
                        </p>
                    </div>
                </template>

                <template v-if="peakHours.busiest" #actions>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-semibold text-brand-700 ring-1 ring-inset ring-brand-200"
                    >
                        <Flame :size="13" aria-hidden="true" />
                        Busiest {{ peakHours.busiest.label }}
                    </span>
                </template>

                <!-- Loading -->
                <div v-if="loadingCharts" class="px-5 py-5 sm:px-6">
                    <div class="flex h-[13.5rem] items-end gap-1.5">
                        <Skeleton
                            v-for="n in 24"
                            :key="n"
                            rounded="sm"
                            class="flex-1"
                            :style="{ height: `${20 + ((n * 29) % 70)}%` }"
                        />
                    </div>
                    <Skeleton class="mt-4 h-3 w-full" />
                </div>

                <!-- Empty -->
                <EmptyState
                    v-else-if="!peakHours.has_data"
                    :icon="Clock"
                    title="No demand data yet"
                    description="Booked time slots build this profile so you can price and staff the busy hours."
                    size="md"
                />

                <!-- Heat strip -->
                <div v-else class="px-5 py-5 sm:px-6">
                    <div class="flex h-[13.5rem] items-end gap-1 sm:gap-1.5">
                        <div
                            v-for="hour in hours"
                            :key="hour.hour"
                            class="group/hour relative flex h-full flex-1 items-end rounded-md bg-ink-100"
                            :title="`${hour.label} · ${hour.count} booking${hour.count === 1 ? '' : 's'}`"
                        >
                            <div
                                :class="[
                                    'w-full rounded-md transition-[height] duration-500 ease-[var(--ease-out-soft)]',
                                    heatClass(hour),
                                ]"
                                :style="{ height: heatHeight(hour) }"
                            />

                            <span
                                class="pointer-events-none absolute -top-8 left-1/2 z-10 hidden -translate-x-1/2 rounded-md bg-ink-900 px-2 py-1 text-[11px] font-medium whitespace-nowrap text-white opacity-0 shadow-float transition-opacity duration-150 group-hover/hour:opacity-100 sm:block"
                            >
                                {{ hour.label }} · {{ hour.count }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-[10px] font-medium text-ink-400">
                        <span v-for="hour in tickHours" :key="`tick-${hour.hour}`">
                            {{ hour.label }}
                        </span>
                    </div>

                    <p class="mt-3 flex items-center gap-1.5 text-xs text-ink-500">
                        <TrendingUp :size="14" aria-hidden="true" />
                        {{ (peakHours.total ?? 0).toLocaleString() }} bookings across
                        {{ activeHourCount }} active hours
                    </p>
                </div>
            </Card>
        </section>

        <!-- ------------------------------------------------------------ -->
        <!-- Upcoming bookings + recent activity                           -->
        <!-- ------------------------------------------------------------ -->
        <section class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <Card padding="none">
                <template #header>
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"
                            aria-hidden="true"
                        >
                            <CalendarClock :size="16" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-semibold text-ink-900">Up next</h3>
                            <p class="mt-0.5 text-xs text-ink-500">Confirmed bookings on the way</p>
                        </div>
                    </div>
                </template>

                <template v-if="canViewBookings" #actions>
                    <Button
                        variant="ghost"
                        size="sm"
                        :href="route('admin.bookings.index', { status: 'confirmed' })"
                    >
                        View all
                        <template #iconRight>
                            <ArrowRight :size="14" aria-hidden="true" />
                        </template>
                    </Button>
                </template>

                <EmptyState
                    v-if="upcoming.length === 0"
                    :icon="CalendarClock"
                    title="Nothing on the schedule"
                    description="Confirmed bookings appear here in the order they will be played."
                    size="md"
                />

                <ul v-else class="divide-y divide-ink-200/70">
                    <li v-for="booking in upcoming" :key="booking.id">
                        <component
                            :is="canViewBookings ? Link : 'div'"
                            :href="canViewBookings ? route('admin.bookings.show', booking.code) : undefined"
                            :class="[
                                'flex items-center gap-3 px-5 py-3.5 sm:px-6',
                                canViewBookings
                                    ? 'transition-colors duration-150 ease-[var(--ease-out-soft)] hover:bg-ink-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500'
                                    : '',
                            ]"
                        >
                            <div
                                :class="[
                                    'flex h-11 w-12 shrink-0 flex-col items-center justify-center rounded-lg ring-1 ring-inset',
                                    booking.is_today
                                        ? 'bg-brand-50 text-brand-700 ring-brand-200'
                                        : 'bg-ink-100 text-ink-600 ring-ink-200',
                                ]"
                                aria-hidden="true"
                            >
                                <span class="text-[10px] font-semibold tracking-wide uppercase">
                                    {{ booking.is_today ? 'Today' : (booking.date_display ?? '').split(',')[0] }}
                                </span>
                                <span class="text-[11px] font-medium">
                                    {{ (booking.date_display ?? '').split(', ')[1] ?? '' }}
                                </span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-ink-900">
                                    {{ booking.customer_name }}
                                </p>
                                <p class="mt-0.5 truncate text-xs text-ink-500">
                                    {{ booking.court_name }} · {{ booking.time_range ?? '—' }}
                                    <span v-if="booking.more_slots > 0" class="text-ink-400">+{{ booking.more_slots }} more</span>
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold text-ink-900 tabular-nums">
                                    {{ booking.amount_display }}
                                </p>
                                <p class="mt-0.5 text-[11px] text-ink-500">
                                    in {{ booking.starts_in }}
                                </p>
                            </div>
                        </component>
                    </li>
                </ul>
            </Card>

            <Card padding="none">
                <template #header>
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-info-50 text-info-600"
                            aria-hidden="true"
                        >
                            <Activity :size="16" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-semibold text-ink-900">Recent activity</h3>
                            <p class="mt-0.5 text-xs text-ink-500">The latest changes across the system</p>
                        </div>
                    </div>
                </template>

                <template v-if="canViewAudit" #actions>
                    <Button variant="ghost" size="sm" :href="route('admin.audit.index')">
                        Audit trail
                        <template #iconRight>
                            <ArrowRight :size="14" aria-hidden="true" />
                        </template>
                    </Button>
                </template>

                <EmptyState
                    v-if="activity.length === 0"
                    :icon="History"
                    title="No activity recorded"
                    description="Every login, edit and booking decision will be listed here as it happens."
                    size="md"
                />

                <ol v-else class="divide-y divide-ink-200/70">
                    <li
                        v-for="entry in activity"
                        :key="entry.id"
                        class="flex gap-3 px-5 py-3.5 sm:px-6"
                    >
                        <span
                            class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink-100 text-[11px] font-semibold text-ink-600"
                            aria-hidden="true"
                        >
                            {{ entry.user_initials }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="truncate text-sm font-semibold text-ink-900">
                                    {{ entry.user_name }}
                                </span>
                                <Badge
                                    :tone="actionTone(entry.action)"
                                    :label="entry.action_label"
                                    size="xs"
                                    :dot="false"
                                />
                                <span v-if="entry.module" class="text-[11px] text-ink-400">
                                    {{ entry.module }}
                                </span>
                            </div>

                            <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-ink-600">
                                {{ entry.description }}
                            </p>

                            <p class="mt-1 text-[11px] text-ink-400">
                                <time :datetime="entry.created_at">{{ entry.created_at_human }}</time>
                                <span v-if="entry.ip_address"> · {{ entry.ip_address }}</span>
                            </p>
                        </div>
                    </li>
                </ol>
            </Card>
        </section>
    </AppLayout>
</template>
