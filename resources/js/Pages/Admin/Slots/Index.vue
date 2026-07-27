<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    Ban,
    CalendarClock,
    CalendarDays,
    CalendarOff,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    Lock,
    Plus,
    RefreshCw,
    RotateCcw,
    Search,
    Timer,
    Trash2,
    WandSparkles,
    X,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormDatePicker from '@/Components/FormDatePicker.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import Pagination from '@/Components/Pagination.vue';
import Skeleton from '@/Components/Skeleton.vue';
import StatCard from '@/Components/StatCard.vue';
import GenerateSlotsModal from './GenerateSlotsModal.vue';
import SlotChip from './SlotChip.vue';
import SlotFormModal from './SlotFormModal.vue';
import { usePermissions } from '@/Composables/usePermissions';
import { useSwal } from '@/Composables/useSwal';
import { SLOT_STATUSES, statusLabel } from '@/Composables/useStatus';

/*
| Admin · Court slots.
|
| A schedule is a two-dimensional thing, so this screen refuses to be a table.
| It is a court plus a date window, rendered as day columns of time chips, with
| every mutation available from the chip itself. Filtering, paging and range
| navigation are all server round trips carrying the same query string the
| controller reads, so a refresh or a back button restores the exact view.
|
| Flash messages and validation errors are AppLayout's job — nothing here
| re-handles them. SweetAlert appears only for the two destructive/irreversible
| moments: bulk delete and generate.
*/

const props = defineProps({
    courts: { type: Array, default: () => [] },
    slots: { type: Object, required: true },
    days: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    filters: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    /** The club-wide rate table: { non_peak: { rate, start, end }, peak: { rate, start, end } }. */
    pricing: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const { confirmAction, confirmDelete } = useSwal();

const canCreate = computed(() => can('slots.create'));
const canUpdate = computed(() => can('slots.update'));
const canDelete = computed(() => can('slots.delete'));
const canGenerate = computed(() => can('slots.generate'));

/** The single court the schedule is currently filtered to, if any — reprice needs one. */
const selectedCourt = computed(() =>
    props.courts.find((court) => String(court.id) === String(query.court)) ?? null,
);

/* --------------------------------------------------------------------- */
/* Filter state                                                           */
/* --------------------------------------------------------------------- */

const query = reactive({
    court: props.filters.court ?? '',
    from: props.filters.from,
    to: props.filters.to,
    status: props.filters.status ?? '',
    search: props.filters.search ?? '',
    per_page: props.filters.per_page ?? 100,
});

const loading = ref(false);

const statusOptions = [
    { value: '', label: 'All statuses' },
    ...SLOT_STATUSES.map((value) => ({ value, label: statusLabel(value) })),
];

const courtOptions = computed(() =>
    props.courts.map((court) => ({
        value: court.id,
        label: court.is_active ? court.name : `${court.name} (inactive)`,
    })),
);

const perPageOptions = computed(() =>
    (props.options.per_page ?? [50, 100, 200, 400]).map((value) => ({
        value,
        label: `${value} per page`,
    })),
);

const currentCourt = computed(
    () => props.courts.find((court) => String(court.id) === String(query.court)) ?? null,
);

/** Blank values are stripped so the URL stays readable and defaults round-trip. */
function reload(patch = {}) {
    Object.assign(query, patch);

    const payload = Object.fromEntries(
        Object.entries({ ...query }).filter(
            ([, value]) => value !== '' && value !== null && value !== undefined,
        ),
    );

    router.get(route('admin.slots.index'), payload, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => {
            loading.value = true;
        },
        onFinish: () => {
            loading.value = false;
        },
    });
}

let searchTimer = null;

watch(
    () => query.search,
    (value) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            if (value !== (props.filters.search ?? '')) {
                reload();
            }
        }, 300);
    },
);

onBeforeUnmount(() => clearTimeout(searchTimer));

/* --------------------------------------------------------------------- */
/* Date-range navigation                                                  */
/* --------------------------------------------------------------------- */

const MS_PER_DAY = 86_400_000;

function toDate(value) {
    return new Date(`${value}T00:00:00`);
}

function toIso(date) {
    const offset = date.getTimezoneOffset() * 60_000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 10);
}

function addDays(value, days) {
    const date = toDate(value);
    date.setDate(date.getDate() + days);

    return toIso(date);
}

const spanDays = computed(
    () => Math.round((toDate(query.to) - toDate(query.from)) / MS_PER_DAY) + 1,
);

/** Page the window by exactly its own width, so nothing is skipped or repeated. */
function shiftRange(direction) {
    const step = spanDays.value * direction;

    reload({ from: addDays(query.from, step), to: addDays(query.to, step) });
}

function jumpToToday() {
    const today = toIso(new Date());

    reload({ from: today, to: addDays(today, spanDays.value - 1) });
}

function setSpan(days) {
    reload({ to: addDays(query.from, days - 1) });
}

const rangeLabel = computed(() => {
    const from = toDate(query.from);
    const to = toDate(query.to);
    const sameMonth = from.getMonth() === to.getMonth() && from.getFullYear() === to.getFullYear();

    const day = { day: 'numeric' };
    const dayMonth = { day: 'numeric', month: 'short' };
    const full = { day: 'numeric', month: 'short', year: 'numeric' };

    return sameMonth
        ? `${from.toLocaleDateString('en-PH', day)} – ${to.toLocaleDateString('en-PH', full)}`
        : `${from.toLocaleDateString('en-PH', dayMonth)} – ${to.toLocaleDateString('en-PH', full)}`;
});

const filtersAreNarrowed = computed(
    () => query.status !== '' || query.search !== '',
);

function clearFilters() {
    reload({ status: '', search: '' });
}

/* --------------------------------------------------------------------- */
/* Grouping                                                               */
/* --------------------------------------------------------------------- */

const slotsByDate = computed(() => {
    const map = new Map();

    for (const slot of props.slots.data ?? []) {
        const bucket = map.get(slot.slot_date);

        if (bucket) {
            bucket.push(slot);
        } else {
            map.set(slot.slot_date, [slot]);
        }
    }

    return map;
});

/**
 * Only days that carry slots are rendered once a page of results is partial —
 * showing 31 mostly-empty columns for page 2 of 4 would be a lie about the
 * schedule. On a complete single page every day in the window is shown, empty
 * ones included, so gaps in the schedule are visible.
 */
const visibleDays = computed(() => {
    const complete = (props.slots.last_page ?? 1) <= 1;

    return props.days.filter((day) => complete || slotsByDate.value.has(day.date));
});

const hasResults = computed(() => (props.slots.data ?? []).length > 0);

/* --------------------------------------------------------------------- */
/* Selection                                                              */
/* --------------------------------------------------------------------- */

const selected = ref([]);

const selectableIds = computed(() =>
    (props.slots.data ?? []).filter((slot) => !slot.is_locked).map((slot) => slot.id),
);

const selectedCount = computed(() => selected.value.length);

const allSelected = computed(
    () =>
        selectableIds.value.length > 0 &&
        selectableIds.value.every((id) => selected.value.includes(id)),
);

// A slot that paged out of view must not stay silently queued for deletion.
watch(
    () => props.slots.data,
    () => {
        const present = new Set(selectableIds.value);
        selected.value = selected.value.filter((id) => present.has(id));
    },
);

function toggleSlot(slot) {
    const index = selected.value.indexOf(slot.id);

    if (index === -1) {
        selected.value.push(slot.id);
    } else {
        selected.value.splice(index, 1);
    }
}

function toggleAll() {
    selected.value = allSelected.value ? [] : [...selectableIds.value];
}

function toggleDay(date) {
    const ids = (slotsByDate.value.get(date) ?? [])
        .filter((slot) => !slot.is_locked)
        .map((slot) => slot.id);

    if (ids.length === 0) {
        return;
    }

    const everySelected = ids.every((id) => selected.value.includes(id));

    selected.value = everySelected
        ? selected.value.filter((id) => !ids.includes(id))
        : [...new Set([...selected.value, ...ids])];
}

function dayIsSelected(date) {
    const ids = (slotsByDate.value.get(date) ?? [])
        .filter((slot) => !slot.is_locked)
        .map((slot) => slot.id);

    return ids.length > 0 && ids.every((id) => selected.value.includes(id));
}

function clearSelection() {
    selected.value = [];
}

/* --------------------------------------------------------------------- */
/* Mutations                                                              */
/* --------------------------------------------------------------------- */

const formOpen = ref(false);
const editingSlot = ref(null);
const formDate = ref(null);
const generateOpen = ref(false);

function openCreate(date = null) {
    editingSlot.value = null;
    formDate.value = date ?? query.from;
    formOpen.value = true;
}

function openEdit(slot) {
    editingSlot.value = slot;
    formDate.value = slot.slot_date;
    formOpen.value = true;
}

function toggleBlock(slot) {
    router.patch(
        route('admin.slots.block', { slot: slot.id }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

async function removeSlot(slot) {
    const label = `${slot.time_range} on ${slot.slot_date}`;

    if (!(await confirmDelete(label))) {
        return;
    }

    router.delete(route('admin.slots.destroy', { slot: slot.id }), {
        preserveScroll: true,
        preserveState: true,
    });
}

async function bulkDelete() {
    if (selectedCount.value === 0) {
        return;
    }

    const count = selectedCount.value;

    const confirmed = await confirmAction({
        title: `Delete ${count} slot${count === 1 ? '' : 's'}?`,
        html:
            `<p style="margin:0">The selected slot${count === 1 ? '' : 's'} will be removed from the schedule.</p>` +
            '<p style="margin:.5rem 0 0;font-size:.875rem">Held and booked slots are never selectable, so no live booking can be affected.</p>',
        confirmText: `Yes, delete ${count}`,
        cancelText: 'Keep them',
        variant: 'danger',
        icon: 'warning',
    });

    if (!confirmed) {
        return;
    }

    router.delete(route('admin.slots.bulk.destroy'), {
        data: { ids: [...selected.value] },
        preserveScroll: true,
        preserveState: true,
        onSuccess: clearSelection,
    });
}

const reprising = ref(false);

/**
 * Push the club-wide CURRENT rates onto the selected court's already-generated
 * `available` schedule, from today onward. Never touches held/booked/blocked
 * slots — see SlotGeneratorService::reprice().
 *
 * Saving the rate table in Settings already reprices every court automatically,
 * so this is the targeted top-up rather than the only way to sync: re-snap a
 * single court after hand-editing individual slot prices, or after generating
 * new slots you want re-checked against the current rates.
 */
async function reprice() {
    const court = selectedCourt.value;

    if (!court) {
        return;
    }

    // The money comes from THIS court's type, not a club-wide table — quoting
    // the wrong type's figures in a confirmation is how an operator approves a
    // reprice they did not mean.
    const rates = court.rates ?? {};
    const typeLabel = court.category_label ?? 'court type';
    const money = (value) => `₱${Number(value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;

    const confirmed = await confirmAction({
        title: `Reprice ${court.name} to current rates?`,
        html:
            `<p style="margin:0">Every <strong>available</strong> slot on ${court.name} from today onward will be updated to the <strong>${typeLabel}</strong> rates:</p>` +
            '<ul style="text-align:left;margin:.6rem 0 0;padding-left:1.1rem">' +
            `<li>Non-peak — ${money(rates.non_peak)}</li>` +
            `<li>Peak — ${money(rates.peak)}</li>` +
            '</ul>' +
            '<p style="margin:.6rem 0 0;font-size:.875rem">Held and booked slots are never touched, so no live booking can be affected. This cannot be undone from this screen.</p>',
        confirmText: 'Yes, reprice it',
        cancelText: 'Cancel',
        variant: 'danger',
        icon: 'warning',
    });

    if (!confirmed) {
        return;
    }

    reprising.value = true;

    router.post(
        route('admin.slots.reprice'),
        {
            court_id: court.id,
            // Reprice the whole future schedule, matching the dialog's "from
            // today onward" — NOT just the visible grid window. The server
            // clamps from_date to today and treats a null to_date as no ceiling;
            // sending query.from/query.to here would silently cap it to the 7-day
            // view and leave later slots on the old price.
            from_date: null,
            to_date: null,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                reprising.value = false;
            },
        },
    );
}

/* --------------------------------------------------------------------- */
/* Sync to operating hours                                                */
/* --------------------------------------------------------------------- */

const syncingHours = ref(false);

const operatingHours = computed(() => ({
    opening: props.options?.operating_opening_time ?? '07:00',
    closing: props.options?.operating_closing_time ?? '02:00',
}));

/** "16:00" → "4:00 PM" for the confirm dialog. */
function to12h(hhmm) {
    const [h, m] = String(hhmm ?? '').split(':').map((n) => Number(n));

    if (!Number.isFinite(h) || !Number.isFinite(m)) {
        return String(hhmm ?? '');
    }

    const suffix = h < 12 ? 'AM' : 'PM';
    const hour = h % 12 === 0 ? 12 : h % 12;

    return `${hour}:${String(m).padStart(2, '0')} ${suffix}`;
}

/**
 * Reshape every court's future `available` schedule to the operating hours set
 * in Settings > Company: add hourly slots for hours that are now open, drop the
 * available slots for hours that are now closed. Held, booked and blocked slots
 * are never touched — see SlotGeneratorService::syncToOperatingHours().
 */
async function syncHours() {
    const oh = operatingHours.value;

    const confirmed = await confirmAction({
        title: 'Sync all courts to operating hours?',
        html:
            `<p style="margin:0">Every court's future <strong>available</strong> slots will be reshaped to your operating hours <strong>${to12h(oh.opening)} – ${to12h(oh.closing)}</strong>:</p>` +
            '<ul style="text-align:left;margin:.6rem 0 0;padding-left:1.1rem">' +
            '<li>Hours now open get their hourly slots added</li>' +
            '<li>Hours now closed have their available slots removed</li>' +
            '</ul>' +
            '<p style="margin:.6rem 0 0;font-size:.875rem">Held, booked and blocked slots are never touched, and only dates that already have slots are affected. This cannot be undone from this screen.</p>',
        confirmText: 'Yes, sync now',
        cancelText: 'Cancel',
        variant: 'danger',
        icon: 'warning',
    });

    if (!confirmed) {
        return;
    }

    syncingHours.value = true;

    router.post(
        route('admin.slots.sync-hours'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                syncingHours.value = false;
            },
        },
    );
}

/* --------------------------------------------------------------------- */
/* Presentation helpers                                                   */
/* --------------------------------------------------------------------- */

const number = new Intl.NumberFormat('en-PH');

const TILES = [
    { key: 'available', label: 'Available', icon: CircleCheck, tone: 'success' },
    { key: 'held', label: 'On hold', icon: Timer, tone: 'warn' },
    { key: 'booked', label: 'Booked', icon: CalendarDays, tone: 'brand' },
    { key: 'blocked', label: 'Blocked', icon: Ban, tone: 'ink' },
];
</script>

<template>
    <AppLayout title="Court slots">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-xl font-semibold tracking-tight text-ink-900 sm:text-2xl">
                    Court slots
                </h1>
                <p class="mt-1 text-sm leading-relaxed text-ink-500">
                    Slots are entirely yours to define — any length, any hours. Place them one at a
                    time, or lay down a repeating pattern across weeks in a single action.
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <Button
                    v-if="canGenerate && selectedCourt"
                    variant="secondary"
                    :loading="reprising"
                    :title="`Push ${selectedCourt.name}'s current rates onto its existing schedule`"
                    @click="reprice"
                >
                    <RefreshCw :size="16" />
                    Reprice to current rates
                </Button>

                <Button
                    v-if="canGenerate && canDelete && courts.length"
                    variant="secondary"
                    :loading="syncingHours"
                    title="Reshape every court's future slots to the operating hours set in Settings"
                    @click="syncHours"
                >
                    <CalendarClock :size="16" />
                    Sync to operating hours
                </Button>

                <Button
                    v-if="canGenerate && courts.length"
                    variant="secondary"
                    @click="generateOpen = true"
                >
                    <WandSparkles :size="16" />
                    Generate slots
                </Button>

                <Button v-if="canCreate && courts.length" @click="openCreate()">
                    <Plus :size="16" />
                    Add slot
                </Button>
            </div>
        </div>

        <!-- No courts at all: nothing on this screen can work yet. -->
        <Card v-if="!courts.length" padding="none">
            <EmptyState
                :icon="CalendarOff"
                title="No courts to schedule yet"
                description="Slots hang off a court, so add your first court and its schedule can be built here."
                size="lg"
            >
                <template #action>
                    <Button
                        v-if="can('courts.create')"
                        :href="route('admin.courts.create')"
                        as="link"
                    >
                        <Plus :size="16" />
                        Add a court
                    </Button>
                </template>
            </EmptyState>
        </Card>

        <template v-else>
            <!-- Summary of the whole window, not just this page -->
            <div class="mb-5 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <StatCard
                    v-for="tile in TILES"
                    :key="tile.key"
                    :label="tile.label"
                    :value="number.format(stats[tile.key] ?? 0)"
                    :icon="tile.icon"
                    :tone="tile.tone"
                    :loading="loading"
                    :hint="`of ${number.format(stats.total ?? 0)} in this range`"
                />
            </div>

            <!-- Controls -->
            <Card padding="sm" class="mb-5">
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                        <div class="w-full lg:max-w-xs">
                            <FormSelect
                                :model-value="query.court"
                                label="Court"
                                :options="courtOptions"
                                @update:model-value="reload({ court: $event })"
                            />
                        </div>

                        <div class="grid flex-1 grid-cols-2 gap-3 sm:max-w-md">
                            <FormDatePicker
                                :model-value="query.from"
                                label="From"
                                @update:model-value="reload({ from: $event })"
                            />
                            <FormDatePicker
                                :model-value="query.to"
                                label="To"
                                :min="query.from"
                                @update:model-value="reload({ to: $event })"
                            />
                        </div>

                        <div class="flex items-center gap-1 pb-0.5">
                            <Button
                                variant="secondary"
                                size="sm"
                                label="Previous period"
                                @click="shiftRange(-1)"
                            >
                                <ChevronLeft :size="16" />
                            </Button>
                            <Button variant="secondary" size="sm" @click="jumpToToday">Today</Button>
                            <Button
                                variant="secondary"
                                size="sm"
                                label="Next period"
                                @click="shiftRange(1)"
                            >
                                <ChevronRight :size="16" />
                            </Button>
                        </div>
                    </div>

                    <div
                        class="flex flex-col gap-3 border-t border-ink-200/70 pt-3 lg:flex-row lg:items-center lg:justify-between"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-700">
                                <CalendarRange :size="15" class="text-ink-400" aria-hidden="true" />
                                {{ rangeLabel }}
                            </span>

                            <span class="text-ink-300" aria-hidden="true">·</span>

                            <button
                                v-for="span in [1, 7, 14, 31]"
                                :key="span"
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-md px-2 py-1 text-[11px] font-medium transition-colors duration-150 ease-[var(--ease-out-soft)]',
                                    spanDays === span
                                        ? 'bg-brand-50 text-brand-700'
                                        : 'text-ink-500 hover:bg-ink-100 hover:text-ink-800',
                                ]"
                                @click="setSpan(span)"
                            >
                                {{ span === 1 ? 'Day' : span === 7 ? 'Week' : `${span} days` }}
                            </button>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <div class="w-full sm:w-56">
                                <FormInput
                                    v-model="query.search"
                                    size="sm"
                                    label="Search slots"
                                    sr-only-label
                                    placeholder="Search booking or customer…"
                                    :icon="Search"
                                />
                            </div>

                            <div class="w-full sm:w-44">
                                <FormSelect
                                    :model-value="query.status"
                                    size="sm"
                                    label="Status"
                                    sr-only-label
                                    :options="statusOptions"
                                    @update:model-value="reload({ status: $event })"
                                />
                            </div>

                            <div class="w-full sm:w-36">
                                <FormSelect
                                    :model-value="query.per_page"
                                    size="sm"
                                    label="Rows per page"
                                    sr-only-label
                                    :options="perPageOptions"
                                    @update:model-value="reload({ per_page: $event })"
                                />
                            </div>

                            <Button
                                v-if="filtersAreNarrowed"
                                variant="ghost"
                                size="sm"
                                @click="clearFilters"
                            >
                                <RotateCcw :size="15" />
                                Clear
                            </Button>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Bulk selection bar -->
            <Transition
                enter-active-class="transition duration-200 ease-[var(--ease-out-soft)]"
                enter-from-class="opacity-0 -translate-y-2"
                leave-active-class="transition duration-150 ease-[var(--ease-out-soft)]"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="selectedCount > 0"
                    class="sticky top-2 z-20 mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 shadow-card"
                >
                    <p class="text-sm font-medium text-brand-700">
                        {{ number.format(selectedCount) }} slot{{ selectedCount === 1 ? '' : 's' }}
                        selected
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button variant="ghost" size="sm" @click="toggleAll">
                            {{ allSelected ? 'Deselect page' : 'Select whole page' }}
                        </Button>
                        <Button variant="ghost" size="sm" @click="clearSelection">
                            <X :size="15" />
                            Clear
                        </Button>
                        <Button variant="danger" size="sm" @click="bulkDelete">
                            <Trash2 :size="15" />
                            Delete selected
                        </Button>
                    </div>
                </div>
            </Transition>

            <!-- Loading skeleton -->
            <div v-if="loading && !hasResults" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Card v-for="n in 6" :key="n" padding="sm">
                    <Skeleton class="h-4 w-24" />
                    <div class="mt-4 space-y-2">
                        <Skeleton v-for="i in 4" :key="i" class="h-12 w-full" rounded="lg" />
                    </div>
                </Card>
            </div>

            <!-- Empty -->
            <Card v-else-if="!hasResults" padding="none">
                <EmptyState
                    :icon="filtersAreNarrowed ? Search : CalendarOff"
                    :title="filtersAreNarrowed ? 'No slots match those filters' : 'Nothing scheduled in this range'"
                    :description="
                        filtersAreNarrowed
                            ? 'Try a different status, clear the search, or widen the date range.'
                            : `${currentCourt?.name ?? 'This court'} has no slots between ${query.from} and ${query.to}. Generate a pattern to fill the window in one go.`
                    "
                    size="lg"
                >
                    <template #action>
                        <Button v-if="filtersAreNarrowed" variant="secondary" @click="clearFilters">
                            <RotateCcw :size="16" />
                            Clear filters
                        </Button>
                        <Button v-if="canGenerate" @click="generateOpen = true">
                            <WandSparkles :size="16" />
                            Generate slots
                        </Button>
                        <Button v-if="canCreate" variant="secondary" @click="openCreate()">
                            <Plus :size="16" />
                            Add one slot
                        </Button>
                    </template>
                </EmptyState>
            </Card>

            <!-- The schedule -->
            <div v-else :class="['grid gap-4 sm:grid-cols-2 xl:grid-cols-3', loading ? 'opacity-60 transition-opacity' : '']">
                <section
                    v-for="day in visibleDays"
                    :key="day.date"
                    :class="[
                        'flex flex-col overflow-hidden rounded-xl border bg-white shadow-card',
                        day.is_today ? 'border-brand-300 ring-1 ring-brand-200' : 'border-ink-200/70',
                    ]"
                >
                    <header
                        :class="[
                            'flex items-center justify-between gap-2 border-b border-ink-200/70 px-3 py-2.5',
                            day.is_today ? 'bg-brand-50' : day.is_weekend ? 'bg-ink-50' : '',
                        ]"
                    >
                        <div class="flex min-w-0 items-baseline gap-2">
                            <span
                                :class="[
                                    'text-sm font-semibold',
                                    day.is_today ? 'text-brand-700' : 'text-ink-900',
                                ]"
                            >
                                {{ day.weekday }}
                            </span>
                            <span class="truncate text-xs text-ink-500">
                                {{ day.day }} {{ day.month }}
                            </span>
                            <span
                                v-if="day.is_today"
                                class="rounded bg-brand-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                            >
                                Today
                            </span>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <span class="text-[11px] tabular-nums text-ink-400">
                                {{ (slotsByDate.get(day.date) ?? []).length }}
                            </span>

                            <button
                                v-if="canDelete && (slotsByDate.get(day.date) ?? []).some((s) => !s.is_locked)"
                                type="button"
                                :class="[
                                    'cursor-pointer rounded px-1.5 py-0.5 text-[11px] font-medium transition-colors',
                                    dayIsSelected(day.date)
                                        ? 'bg-brand-100 text-brand-700'
                                        : 'text-ink-400 hover:bg-ink-100 hover:text-ink-700',
                                ]"
                                @click="toggleDay(day.date)"
                            >
                                {{ dayIsSelected(day.date) ? 'Deselect' : 'Select' }}
                            </button>

                            <button
                                v-if="canCreate"
                                type="button"
                                class="cursor-pointer rounded p-1 text-ink-400 transition-colors hover:bg-ink-100 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                                :aria-label="`Add a slot on ${day.label}`"
                                :title="`Add a slot on ${day.label}`"
                                @click="openCreate(day.date)"
                            >
                                <Plus :size="14" />
                            </button>
                        </div>
                    </header>

                    <div class="flex-1 space-y-1.5 p-2.5">
                        <SlotChip
                            v-for="slot in slotsByDate.get(day.date) ?? []"
                            :key="slot.id"
                            :slot="slot"
                            :selected="selected.includes(slot.id)"
                            :can-update="canUpdate"
                            :can-delete="canDelete"
                            @toggle="toggleSlot"
                            @edit="openEdit"
                            @block="toggleBlock"
                            @remove="removeSlot"
                        />

                        <p
                            v-if="(slotsByDate.get(day.date) ?? []).length === 0"
                            class="flex items-center justify-center gap-1.5 rounded-lg border border-dashed border-ink-200 py-5 text-xs text-ink-400"
                        >
                            <Lock v-if="day.is_past" :size="13" aria-hidden="true" />
                            {{ day.is_past ? 'Past — nothing scheduled' : 'No slots' }}
                        </p>
                    </div>
                </section>
            </div>

            <div v-if="hasResults" class="mt-6">
                <Pagination :paginator="slots" label="slots" />
            </div>
        </template>

        <SlotFormModal
            v-model="formOpen"
            :slot="editingSlot"
            :courts="courts"
            :court-id="query.court"
            :date="formDate"
            :pricing="pricing"
        />

        <GenerateSlotsModal
            v-model="generateOpen"
            :courts="courts"
            :pricing="pricing"
            :court-id="query.court"
            :from="query.from"
            :limit="options.max_generate_slots ?? 2000"
            :operating-hours="{
                opening: options.operating_opening_time,
                closing: options.operating_closing_time,
            }"
        />
    </AppLayout>
</template>
