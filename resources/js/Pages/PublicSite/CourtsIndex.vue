<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    ArrowRight,
    CalendarX,
    ChevronLeft,
    ChevronRight,
    LoaderCircle,
    Moon,
    Phone,
    Search,
    Sun,
    Sunset,
    Ticket,
    Timer,
    TriangleAlert,
    X,
} from '@lucide/vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Alert from '@/Components/Alert.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormInput from '@/Components/FormInput.vue';
import GridSlotCell from '@/Components/GridSlotCell.vue';
import Modal from '@/Components/Modal.vue';
import Skeleton from '@/Components/Skeleton.vue';

/*
| PublicSite/CourtsIndex — the merged booking widget.
|
| A spreadsheet-style grid: one TIME row per distinct (start, end) pair that
| exists on ANY active court that day, one COLUMN per active court, so every
| court's availability is visible side by side at a glance. Switching the
| date is a single Inertia partial reload against this same route (`only:
| ['court', 'days', 'selectedDate', 'grid', 'courts']`), so the payload stays
| a few kilobytes however many courts or rows exist. A direct link to
| `public.courts.show` renders this exact component with that court's column
| led to the front — every other court still renders alongside it.
|
| The grid itself carries EVERY status (available/pending/booked/unavailable)
| so the table reads as a real schedule, not just a picker — only clicking an
| "available" cell does anything.
*/

const props = defineProps({
    /** [{ id, name, slug, description, photo_url, from_price, available_slots_count, available_today_count }] */
    courts: { type: Array, default: () => [] },
    /** { id, name, slug, description, photo_url, from_price } | null — leads the column order + hero copy. */
    court: { type: Object, default: null },
    /** [{ date, weekday, day, month, long_label, is_today, is_tomorrow, slots_count, from_price }] */
    days: { type: Array, default: () => [] },
    selectedDate: { type: String, default: null },
    /**
     * { sections: [{ period, rows: [{ key, start, end, time_range,
     *   duration_minutes, period, price, cells: { [courtId]: { status, id,
     *   price } } }] }], rates: [{ price, start, end }] } | null
     */
    grid: { type: Object, default: null },
    holdMinutes: { type: Number, default: 30 },
    horizonDays: { type: Number, default: 60 },
    company: { type: Object, default: () => ({}) },
    codePrefix: { type: String, default: 'AH' },
});

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

const money = (value) => peso.format(Number(value ?? 0));

/* ── Hero stats ─────────────────────────────────────────────────────── */

const openCourtsCount = computed(
    () => props.courts.filter((court) => (court.available_slots_count ?? 0) > 0).length,
);

const totalOpenSlots = computed(() =>
    props.courts.reduce((sum, court) => sum + (court.available_slots_count ?? 0), 0),
);

/** Availability copy for a grid column header, so it never overstates what is left today. */
function availability(count) {
    const n = count ?? 0;

    if (n === 0) {
        return { label: 'Fully booked', class: 'text-noir-400' };
    }

    if (n <= 3) {
        return { label: `${n} left today`, class: 'text-ash-700' };
    }

    return { label: `${n} open today`, class: 'text-graphite-600' };
}

/* ── Date switching ────────────────────────────────────────────────── */

const loadingSchedule = ref(false);
const strip = ref(null);

const activeDay = computed(
    () => props.days.find((day) => day.date === props.selectedDate) ?? null,
);

function navigate(params) {
    if (loadingSchedule.value) {
        return;
    }

    slotError.value = null;

    router.get(route('public.courts.index'), params, {
        only: ['court', 'days', 'selectedDate', 'grid', 'courts'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => {
            loadingSchedule.value = true;
        },
        onFinish: () => {
            loadingSchedule.value = false;
        },
    });
}

function selectDate(date) {
    if (date === props.selectedDate) {
        return;
    }

    // Keep passing the leading court's slug so the column order (and hero)
    // stays put across a date change — otherwise the server would fall back
    // to "first court with availability", which can shuffle the columns.
    navigate({ court: props.court?.slug, date });
}

function scrollStrip(direction) {
    strip.value?.scrollBy({ left: direction * 240, behavior: 'smooth' });
}

/* ── Grid presentation ──────────────────────────────────────────────── */

const PERIOD_ICONS = { Morning: Sun, Afternoon: Sunset, Evening: Moon };

function cellFor(row, courtId) {
    return row.cells[courtId] ?? { status: 'unavailable', id: null, price: null };
}

function cellLabel(status) {
    switch (status) {
        case 'available':
            return 'Available';
        case 'pending':
            return 'On hold';
        case 'booked':
            return 'Booked';
        default:
            return 'Not available';
    }
}

function cellAriaLabel(courtName, row, cell) {
    if (cell.status === 'available') {
        return `Book ${courtName}, ${row.time_range}, ${money(cell.price)}`;
    }

    return `${courtName}, ${row.time_range}, ${cellLabel(cell.status)}`;
}

/* ── Your-session state + reservation ──────────────────────────────── */

/**
 * One entry per selected time. A booking may now span several courts — the
 * guest can mix times across different courts and check out together, so
 * pickCell() below appends every pick instead of wiping the set when the court
 * changes. The distinct courts are surfaced by isMultiCourt / slotsByCourt.
 * [{ id, start, end, time_range, duration_minutes, price, court_id, court_name }]
 */
const chosenSlots = ref([]);
const checkoutOpen = ref(false);

/** Set when one or more chosen slots were claimed by someone else mid-checkout. */
const slotError = ref(null);
const slotErrorTitle = ref('That time just went');

function setSlotError(title, message) {
    slotErrorTitle.value = title;
    slotError.value = message;
}

const sessionTotal = computed(() =>
    chosenSlots.value.reduce((sum, slot) => sum + Number(slot.price ?? 0), 0),
);

/** True once the selection touches more than one court — every "session" and
 *  modal summary below switches from naming the one court to a per-court split. */
const isMultiCourt = computed(
    () => new Set(chosenSlots.value.map((slot) => slot.court_id)).size > 1,
);

/** Minutes-since-midnight for a "g:i A" label ("9:00 AM") — lets the grouped
 *  summary read chronologically without threading a sort key through the
 *  chosenSlots shape (which the payload doesn't carry). */
function startMinutes(label) {
    const match = /^(\d{1,2}):(\d{2})\s*(AM|PM)$/i.exec(label ?? '');

    if (!match) {
        return 0;
    }

    const hour = (Number(match[1]) % 12) + (match[3].toUpperCase() === 'PM' ? 12 : 0);

    return hour * 60 + Number(match[2]);
}

/**
 * The selection grouped by court for the multi-court summaries —
 * [{ court_id, court_name, slots }], courts in first-picked order, slots
 * chronological within each group. A single-court selection never reads this.
 */
const slotsByCourt = computed(() => {
    const groups = new Map();

    for (const slot of chosenSlots.value) {
        if (!groups.has(slot.court_id)) {
            groups.set(slot.court_id, { court_id: slot.court_id, court_name: slot.court_name, slots: [] });
        }

        groups.get(slot.court_id).slots.push(slot);
    }

    return [...groups.values()].map((group) => ({
        ...group,
        slots: [...group.slots].sort((a, b) => startMinutes(a.start) - startMinutes(b.start)),
    }));
});

const form = useForm({
    slot_ids: [],
    customer_name: '',
    customer_phone: '',
    customer_email: '',
});

/**
 * The ids actually posted on the last submit, in order — kept separately from
 * chosenSlots so a validation error keyed `slot_ids.N` (N being the index in
 * the array that was posted) can still be traced back to the slot it
 * describes.
 */
let lastSubmittedIds = [];

function pickCell(row, court, cell) {
    if (cell.status !== 'available') {
        return;
    }

    slotError.value = null;

    const existingIndex = chosenSlots.value.findIndex((slot) => slot.id === cell.id);

    if (existingIndex !== -1) {
        // Toggle off — clicking an already-selected cell removes just that one.
        chosenSlots.value.splice(existingIndex, 1);
        form.clearErrors('slot_ids');

        return;
    }

    const picked = {
        id: cell.id,
        start: row.start,
        end: row.end,
        time_range: row.time_range,
        duration_minutes: row.duration_minutes,
        price: cell.price,
        court_id: court.id,
        court_name: court.name,
    };

    // A booking may span several courts, so every pick is appended regardless
    // of the court it sits on — clicking a cell on a different court adds to the
    // selection instead of wiping it. (Toggle-off above still removes just one.)
    chosenSlots.value = [...chosenSlots.value, picked];

    form.clearErrors('slot_ids');
}

/** Removes a single pick — the small "x" next to each row in "Your session". */
function removeSlot(id) {
    chosenSlots.value = chosenSlots.value.filter((slot) => slot.id !== id);
}

function openDetails() {
    if (chosenSlots.value.length === 0) {
        return;
    }

    form.slot_ids = chosenSlots.value.map((slot) => slot.id);
    checkoutOpen.value = true;
}

function submit() {
    lastSubmittedIds = [...form.slot_ids];

    form.post(route('public.booking.reserve'), {
        preserveScroll: true,
        onSuccess: () => {
            checkoutOpen.value = false;
            chosenSlots.value = [];
            lastSubmittedIds = [];
            form.reset();
        },
    });
}

/** Drops exactly the slots named in `lost` and tells the customer which ones,
 *  making clear whatever is left of their selection is still held. */
function announceLost(lost) {
    const labels = lost.map((slot) => slot.time_range).join(', ');
    const remainder = chosenSlots.value.length > 0 ? ' Your other picks are still held.' : '';

    setSlotError(
        lost.length > 1 ? 'Some of your times just went' : 'That time just went',
        (lost.length === 1
            ? `${labels} was just taken by someone else and has been removed from your selection.`
            : `${labels} were just taken by someone else and have been removed from your selection.`) +
            remainder,
    );
}

// A slot that vanished between render and submit comes back as a validation
// error keyed `slot_ids` (the whole set — e.g. "pick at least one time") or
// `slot_ids.N` (one specific pick, N being its position in the array posted
// above). Only the picks that actually failed are dropped — a customer who
// chose 3 times and loses 1 to a race keeps the other 2, with an inline
// notice naming what was lost, rather than starting over from zero.
watch(
    () => form.errors,
    async (errors) => {
        const keys = Object.keys(errors ?? {}).filter(
            (key) => key === 'slot_ids' || key.startsWith('slot_ids.'),
        );

        if (keys.length === 0) {
            return;
        }

        const perSlotKeys = keys.filter((key) => key !== 'slot_ids');

        if (perSlotKeys.length === 0) {
            // A blanket error ("please choose at least one time") — nothing in
            // the sheet can fix that alone.
            setSlotError('Your selection changed', errors.slot_ids);
            chosenSlots.value = [];
        } else {
            const lostIds = new Set(
                perSlotKeys
                    .map((key) => lastSubmittedIds[Number(key.slice('slot_ids.'.length))])
                    .filter((id) => id !== undefined),
            );

            const lost = chosenSlots.value.filter((slot) => lostIds.has(slot.id));

            if (lost.length > 0) {
                chosenSlots.value = chosenSlots.value.filter((slot) => !lostIds.has(slot.id));
                announceLost(lost);
            } else {
                setSlotError(
                    'Your selection changed',
                    'One of the selected times is no longer available. Please check your selection.',
                );
            }
        }

        form.clearErrors(...keys);
        checkoutOpen.value = false;

        await nextTick();

        router.reload({ only: ['grid', 'days', 'courts'], preserveState: true, preserveScroll: true });
    },
    { deep: true },
);

// A partial reload can drop a cell the visitor had highlighted (someone else
// took it, or the day changed under them) — only the picks that no longer
// check out are dropped, never the whole selection.
watch(
    () => [props.grid, props.selectedDate],
    ([grid], [, previousDate]) => {
        if (chosenSlots.value.length === 0) {
            return;
        }

        const isStillAvailable = (slot) =>
            (grid?.sections ?? []).some((section) =>
                section.rows.some((row) => {
                    const cell = row.cells[slot.court_id];

                    return cell && cell.id === slot.id && cell.status === 'available';
                }),
            );

        const lost = chosenSlots.value.filter((slot) => !isStillAvailable(slot));

        if (lost.length === 0) {
            return;
        }

        chosenSlots.value = chosenSlots.value.filter((slot) => isStillAvailable(slot));

        // Switching the date is a deliberate reset — a slot picked on another
        // day was never coming with you, so that stays silent exactly like it
        // always has. Anything lost while the date stayed put is a genuine
        // race with someone else and earns the same notice as above.
        if (previousDate === props.selectedDate) {
            announceLost(lost);
        }

        if (chosenSlots.value.length === 0) {
            checkoutOpen.value = false;
        }
    },
);

/* ── "Already booked?" band ────────────────────────────────────────── */

const lookupExample = computed(() => `${props.codePrefix}-K7M4XQ9B`);

const lookupForm = useForm({ code: '' });

function submitLookup() {
    lookupForm.post(route('public.booking.lookup.submit'), { preserveScroll: true });
}
</script>

<template>
    <PublicLayout :title="court ? `Book ${court.name}` : 'Book a pickleball court'" :contained="false">
        <!-- ── Hero ───────────────────────────────────────────────────── -->
        <section class="relative overflow-hidden bg-brand-600">
            <img
                v-if="court?.photo_url"
                :src="court.photo_url"
                :alt="court.name"
                class="absolute inset-0 h-full w-full object-cover"
            />

            <!--
              No court photo is the common case, so the fallback has to stand on
              its own: crimson stays the dominant colour (client didn't want the
              earlier full black->red diagonal back), but a flat fill read as
              too "2D" — this is a light touch of black in one corner only,
              fading out fast so most of the field is still pure brand-600.
              Two soft light sources and an abstract court diagram in the
              corner add the rest of the depth.
            -->
            <div v-else class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute inset-0 bg-gradient-to-br from-noir-900/35 via-brand-600 to-brand-600" />
                <div
                    class="absolute -top-40 -left-32 h-[34rem] w-[34rem] rounded-full bg-graphite-500/30 blur-3xl"
                />
                <div
                    class="absolute -right-24 -bottom-40 h-[26rem] w-[26rem] rounded-full bg-ash-500/10 blur-3xl"
                />

                <!-- Court motif: outer line, service boxes, centre net. -->
                <div
                    class="absolute -top-8 -right-16 hidden h-[26rem] w-[26rem] rotate-[18deg] rounded-lg ring-1 ring-ash-500/20 md:block"
                >
                    <div class="absolute inset-6 rounded-sm ring-1 ring-ash-500/15" />
                    <div class="absolute inset-x-6 top-1/2 h-px -translate-y-1/2 bg-ash-500/25" />
                    <div class="absolute inset-y-6 left-1/2 w-px -translate-x-1/2 bg-ash-500/15" />
                </div>
            </div>

            <!--
              Legibility scrim for the text column: darkest right behind the
              copy/buttons, easing off toward the top. Tinted brand-900 (a
              near-black RED) rather than noir, so any darkening it adds stays
              inside the red field instead of reintroducing black.
            -->
            <div
                class="pointer-events-none absolute inset-0 bg-gradient-to-t from-brand-900/60 via-brand-900/25 to-transparent"
                aria-hidden="true"
            />

            <div class="relative mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
                <!-- Dark hero band: the light artwork, placed bare — no plate. -->
                <img
                    src="/images/brand/logo-mark-light.png"
                    alt="After Hours"
                    width="960"
                    height="463"
                    class="mb-8 h-12 w-auto sm:h-14"
                />

                <span
                    class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-[11px] font-semibold tracking-[0.2em] text-ash-400 uppercase ring-1 ring-inset ring-white/15"
                >
                    <span class="relative flex h-2 w-2" aria-hidden="true">
                        <span
                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-ash-400 opacity-75"
                        />
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-ash-400" />
                    </span>
                    {{ totalOpenSlots }} open slot{{ totalOpenSlots === 1 ? '' : 's' }} right now
                </span>

                <h1 class="font-display-heading mt-5 max-w-2xl text-4xl text-white sm:text-5xl md:text-6xl">
                    <span class="block">Play beyond</span>
                    <span class="block text-ash-400">the 9-to-5.</span>
                </h1>

                <p class="mt-5 max-w-xl text-sm leading-relaxed text-bone-200/90 sm:text-base">
                    {{
                        company.tagline ??
                        'Pick a time, pick your court, pay by QR. No sign-up, no phone tag — just show up and play.'
                    }}
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <!-- Deliberate exception: buttons are brand-600 crimson everywhere else
                         in the system, but the hero band itself is crimson — a red pill
                         here would disappear into its own background, so this one stays
                         noir purely for contrast. -->
                    <a
                        href="#book"
                        class="inline-flex h-12 items-center gap-2 rounded-xl bg-noir-900 px-5 text-sm font-semibold text-white shadow-card transition-colors duration-200 ease-[var(--ease-out-soft)] hover:bg-noir-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white/40"
                    >
                        Reserve now
                        <ArrowRight :size="16" aria-hidden="true" />
                    </a>

                    <a
                        href="#lookup"
                        class="inline-flex h-12 items-center gap-2 rounded-xl px-5 text-sm font-medium text-white/90 ring-1 ring-inset ring-white/25 transition-colors duration-200 ease-[var(--ease-out-soft)] hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    >
                        <Ticket :size="16" aria-hidden="true" />
                        Check my booking
                    </a>
                </div>

                <dl
                    class="mt-10 grid max-w-lg grid-cols-2 gap-x-8 gap-y-4 border-t border-white/15 pt-6 sm:grid-cols-3"
                >
                    <div>
                        <dt class="text-xs text-white/55">Courts</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-white">{{ courts.length }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-white/55">Taking bookings</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-white">{{ openCourtsCount }}</dd>
                    </div>
                    <div v-if="company.phone" class="col-span-2 sm:col-span-1">
                        <dt class="text-xs text-white/55">Need help?</dt>
                        <dd class="mt-0.5 flex items-center gap-1.5 text-sm font-medium text-white">
                            <Phone :size="14" aria-hidden="true" />
                            {{ company.phone }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <!-- ── Reservation widget ─────────────────────────────────────── -->
        <section id="book" class="bg-bone-100">
            <div class="mx-auto w-full max-w-6xl px-4 py-12 sm:px-6 sm:py-16">
                <!-- No courts at all -->
                <div
                    v-if="courts.length === 0"
                    class="rounded-2xl border border-bone-300/70 bg-white shadow-card"
                >
                    <EmptyState
                        :icon="CalendarX"
                        tone="warn"
                        size="lg"
                        title="No courts are open just yet"
                        description="Our schedule is being set up. Please check back shortly, or get in touch and we will let you know the moment slots go live."
                    >
                        <template v-if="company.phone" #action>
                            <a
                                :href="`tel:${company.phone}`"
                                class="inline-flex h-10 items-center gap-2 rounded-xl border border-bone-300 bg-white px-4 text-sm font-medium text-noir-700 shadow-card transition-colors hover:bg-bone-50"
                            >
                                <Phone :size="16" aria-hidden="true" />
                                Call {{ company.phone }}
                            </a>
                        </template>
                    </EmptyState>
                </div>

                <!-- The widget -->
                <div v-else class="flex flex-col gap-8 lg:flex-row lg:items-start">
                    <!-- Numbered card -->
                    <div
                        class="min-w-0 flex-1 overflow-hidden rounded-2xl border border-bone-300/70 bg-white shadow-card"
                    >
                        <!-- No open days on ANY court -->
                        <div v-if="days.length === 0" class="p-5 sm:p-8">
                            <EmptyState
                                :icon="CalendarX"
                                tone="warn"
                                size="lg"
                                title="Nothing open just yet"
                                :description="`There are no free slots across any of our courts in the next ${horizonDays} days. New times are added regularly — please check back soon.`"
                            />
                        </div>

                        <template v-else>
                            <!-- 1. Choose date -->
                            <div class="border-b border-bone-200 p-5 sm:p-6">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p
                                            class="text-[11px] font-semibold tracking-[0.2em] text-ash-700 uppercase"
                                        >
                                            1. Choose date
                                        </p>
                                        <p class="mt-1 truncate text-xs text-noir-500">
                                            {{ activeDay?.long_label ?? 'Choose a day to see open times' }}
                                        </p>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-1">
                                        <button
                                            type="button"
                                            class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg border border-bone-300 bg-white text-noir-600 transition-colors hover:bg-bone-50 hover:text-noir-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
                                            aria-label="Scroll dates backwards"
                                            @click="scrollStrip(-1)"
                                        >
                                            <ChevronLeft :size="17" aria-hidden="true" />
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg border border-bone-300 bg-white text-noir-600 transition-colors hover:bg-bone-50 hover:text-noir-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
                                            aria-label="Scroll dates forwards"
                                            @click="scrollStrip(1)"
                                        >
                                            <ChevronRight :size="17" aria-hidden="true" />
                                        </button>
                                    </div>
                                </div>

                                <!-- Skeleton while switching date -->
                                <div v-if="loadingSchedule" class="mt-4 flex gap-2">
                                    <Skeleton
                                        v-for="cell in 6"
                                        :key="cell"
                                        rounded="xl"
                                        class="h-19 w-19 shrink-0"
                                    />
                                </div>

                                <div
                                    v-else
                                    ref="strip"
                                    class="-mx-1 mt-4 flex snap-x snap-mandatory gap-2 overflow-x-auto scroll-smooth px-1 pb-1"
                                    role="tablist"
                                    aria-label="Available dates"
                                >
                                    <button
                                        v-for="day in days"
                                        :key="day.date"
                                        type="button"
                                        role="tab"
                                        :aria-selected="day.date === selectedDate"
                                        :class="[
                                            'group relative flex min-w-19 shrink-0 snap-start cursor-pointer flex-col items-center gap-0.5 rounded-xl border px-3 py-2.5',
                                            'transition-[background-color,border-color,box-shadow,transform] duration-200 ease-[var(--ease-out-soft)]',
                                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900',
                                            day.date === selectedDate
                                                ? 'border-brand-600 bg-brand-600 text-white shadow-card'
                                                : 'border-bone-300 bg-white text-noir-700 hover:-translate-y-0.5 hover:border-noir-300 hover:shadow-card',
                                        ]"
                                        @click="selectDate(day.date)"
                                    >
                                        <span
                                            :class="[
                                                'text-[10px] font-medium tracking-wide uppercase',
                                                day.date === selectedDate ? 'text-white/75' : 'text-noir-400',
                                            ]"
                                        >
                                            {{ day.is_today ? 'Today' : day.is_tomorrow ? 'Tmrw' : day.weekday }}
                                        </span>
                                        <span class="text-lg leading-tight font-semibold">{{ day.day }}</span>
                                        <span
                                            :class="[
                                                'text-[10px]',
                                                day.date === selectedDate ? 'text-white/75' : 'text-noir-400',
                                            ]"
                                        >
                                            {{ day.month }}
                                        </span>
                                        <span
                                            :class="[
                                                'mt-1 rounded-full px-1.5 py-0.5 text-[10px] leading-none font-medium',
                                                day.date === selectedDate
                                                    ? 'bg-white/20 text-white'
                                                    : 'bg-ash-100 text-ash-800',
                                            ]"
                                        >
                                            {{ day.slots_count }} open
                                        </span>
                                    </button>
                                </div>
                            </div>

                            <!-- 2. Pick your time -->
                            <div class="p-5 sm:p-6">
                                <p class="text-[11px] font-semibold tracking-[0.2em] text-ash-700 uppercase">
                                    2. Pick your time
                                </p>

                                <Alert
                                    v-if="slotError"
                                    class="mt-4"
                                    variant="warning"
                                    :title="slotErrorTitle"
                                    :message="slotError"
                                    dismissible
                                    @dismiss="slotError = null"
                                />

                                <!-- Rates summary banner -->
                                <div
                                    v-if="!loadingSchedule && grid?.rates?.length"
                                    class="mt-4 flex flex-wrap items-center gap-2 rounded-xl bg-bone-100 px-3 py-2.5 ring-1 ring-inset ring-bone-300/70"
                                >
                                    <span class="text-[11px] font-semibold tracking-wide text-noir-500 uppercase">
                                        Rates
                                    </span>
                                    <span
                                        v-for="(band, index) in grid.rates"
                                        :key="index"
                                        class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-noir-700 ring-1 ring-inset ring-bone-300"
                                    >
                                        <span class="font-semibold text-ash-700">{{ money(band.price) }}</span>
                                        <span class="text-noir-400">{{ band.start }} – {{ band.end }}</span>
                                    </span>
                                </div>

                                <!-- Skeleton while the partial reload is in flight -->
                                <div
                                    v-if="loadingSchedule"
                                    class="mt-4 space-y-2"
                                    role="status"
                                    aria-live="polite"
                                >
                                    <span class="sr-only">Loading the schedule…</span>
                                    <Skeleton rounded="lg" class="h-9 w-full" />
                                    <Skeleton v-for="row in 6" :key="row" rounded="lg" class="h-14 w-full" />
                                </div>

                                <!-- Nothing left on this specific day (defensive — the day
                                     picker only ever offers days that have inventory) -->
                                <EmptyState
                                    v-else-if="!grid || grid.sections.length === 0"
                                    class="mt-2"
                                    :icon="CalendarX"
                                    title="Every court is fully booked for this day"
                                    description="Pick another date from the strip above."
                                />

                                <!-- The grid: one row per time-of-day, one column per court -->
                                <template v-else>
                                    <div class="mt-4 overflow-x-auto rounded-xl border border-bone-300/70">
                                        <table class="w-full min-w-max border-separate border-spacing-0 text-sm">
                                            <thead>
                                                <tr>
                                                    <th
                                                        scope="col"
                                                        class="sticky left-0 z-10 min-w-28 border-b border-bone-300/70 bg-bone-100 px-3 py-2.5 text-left text-[11px] font-semibold tracking-wide text-noir-500 uppercase"
                                                    >
                                                        Time
                                                    </th>
                                                    <th
                                                        v-for="col in courts"
                                                        :key="col.id"
                                                        scope="col"
                                                        class="min-w-[7.5rem] border-b border-l border-bone-300/70 bg-bone-100 px-3 py-2.5 text-left align-bottom"
                                                    >
                                                        <span class="block truncate text-xs font-semibold text-noir-900">
                                                            {{ col.name }}
                                                        </span>
                                                        <span
                                                            :class="[
                                                                'mt-0.5 block text-[10px]',
                                                                availability(col.available_today_count).class,
                                                            ]"
                                                        >
                                                            {{ availability(col.available_today_count).label }}
                                                        </span>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template v-for="section in grid.sections" :key="section.period">
                                                    <tr>
                                                        <th
                                                            scope="colgroup"
                                                            :colspan="courts.length + 1"
                                                            class="sticky left-0 z-[1] border-b border-bone-200 bg-bone-50 px-3 py-1.5 text-left text-[11px] font-semibold tracking-wide text-ash-700 uppercase"
                                                        >
                                                            <span class="inline-flex items-center gap-1.5">
                                                                <component
                                                                    :is="PERIOD_ICONS[section.period]"
                                                                    :size="13"
                                                                    aria-hidden="true"
                                                                />
                                                                {{ section.period }}
                                                            </span>
                                                        </th>
                                                    </tr>
                                                    <tr v-for="row in section.rows" :key="row.key" class="group">
                                                        <th
                                                            scope="row"
                                                            class="sticky left-0 z-[1] border-b border-bone-200 bg-white px-3 py-2 text-left text-xs font-medium whitespace-nowrap text-noir-700 group-hover:bg-bone-50"
                                                        >
                                                            {{ row.start }} – {{ row.end }}
                                                        </th>
                                                        <td
                                                            v-for="col in courts"
                                                            :key="col.id"
                                                            class="border-b border-l border-bone-200 p-1.5"
                                                        >
                                                            <GridSlotCell
                                                                :status="cellFor(row, col.id).status"
                                                                :price-label="
                                                                    cellFor(row, col.id).price != null
                                                                        ? money(cellFor(row, col.id).price)
                                                                        : null
                                                                "
                                                                :selected="
                                                                    chosenSlots.some(
                                                                        (slot) => slot.id === cellFor(row, col.id).id,
                                                                    ) && cellFor(row, col.id).status === 'available'
                                                                "
                                                                :aria-label="
                                                                    cellAriaLabel(col.name, row, cellFor(row, col.id))
                                                                "
                                                                @pick="pickCell(row, col, cellFor(row, col.id))"
                                                            />
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Legend -->
                                    <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-noir-500">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span
                                                class="h-3 w-3 rounded-sm border border-graphite-500/30 bg-graphite-50"
                                                aria-hidden="true"
                                            />
                                            Available
                                        </span>
                                        <span class="inline-flex items-center gap-1.5">
                                            <span
                                                class="h-3 w-3 rounded-sm border border-brand-600 bg-brand-600 ring-1 ring-brand-500/25"
                                                aria-hidden="true"
                                            />
                                            Selected
                                        </span>
                                        <span class="inline-flex items-center gap-1.5">
                                            <span
                                                class="h-3 w-3 rounded-sm border border-warn-500/30 bg-warn-50"
                                                aria-hidden="true"
                                            />
                                            Pending
                                        </span>
                                        <span class="inline-flex items-center gap-1.5">
                                            <span
                                                class="h-3 w-3 rounded-sm border border-brand-500/20 bg-brand-50"
                                                aria-hidden="true"
                                            />
                                            Booked
                                        </span>
                                        <span class="inline-flex items-center gap-1.5">
                                            <span
                                                class="h-3 w-3 rounded-sm border border-ink-200 bg-ink-50"
                                                aria-hidden="true"
                                            />
                                            Unavailable
                                        </span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <!-- Your session -->
                    <aside
                        class="w-full shrink-0 rounded-2xl border-2 border-ash-500 bg-white p-6 shadow-card lg:sticky lg:top-24 lg:w-80"
                    >
                        <p class="text-[11px] font-semibold tracking-[0.25em] text-ash-700 uppercase">
                            Your session
                        </p>

                        <dl class="mt-4 space-y-4">
                            <div class="flex items-start justify-between gap-3 border-b border-bone-200 pb-3">
                                <dt class="text-xs text-noir-500">{{ isMultiCourt ? 'Courts' : 'Court' }}</dt>
                                <dd class="text-right text-sm font-semibold text-noir-900">
                                    <span v-if="isMultiCourt">{{
                                        slotsByCourt.map((group) => group.court_name).join(', ')
                                    }}</span>
                                    <span v-else-if="chosenSlots.length">{{ chosenSlots[0].court_name }}</span>
                                    <span v-else class="font-normal text-noir-400">Pick a time</span>
                                </dd>
                            </div>
                            <div class="flex items-start justify-between gap-3 border-b border-bone-200 pb-3">
                                <dt class="text-xs text-noir-500">Date</dt>
                                <dd class="text-right text-sm font-semibold text-noir-900">
                                    {{ activeDay?.long_label ?? '—' }}
                                </dd>
                            </div>
                            <div class="flex items-start justify-between gap-3 border-b border-bone-200 pb-3">
                                <dt class="pt-0.5 text-xs text-noir-500">Time</dt>
                                <dd class="flex-1 text-right text-sm font-semibold text-noir-900">
                                    <!-- Mixed courts: each line names its court so the guest can
                                         read which time belongs to which court at a glance. -->
                                    <ul v-if="isMultiCourt" class="space-y-2.5">
                                        <li
                                            v-for="slot in chosenSlots"
                                            :key="slot.id"
                                            class="flex items-start justify-end gap-1.5"
                                        >
                                            <span class="flex flex-col items-end leading-tight">
                                                <span class="text-[11px] font-medium text-noir-400">{{
                                                    slot.court_name
                                                }}</span>
                                                <span>{{ slot.start }} – {{ slot.end }}</span>
                                            </span>
                                            <button
                                                type="button"
                                                class="mt-0.5 inline-flex h-4 w-4 shrink-0 cursor-pointer items-center justify-center rounded-full text-noir-400 transition-colors hover:bg-bone-100 hover:text-noir-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
                                                :aria-label="`Remove ${slot.court_name}, ${slot.start} – ${slot.end}`"
                                                @click="removeSlot(slot.id)"
                                            >
                                                <X :size="11" aria-hidden="true" />
                                            </button>
                                        </li>
                                    </ul>
                                    <ul v-else-if="chosenSlots.length" class="space-y-1.5">
                                        <li
                                            v-for="slot in chosenSlots"
                                            :key="slot.id"
                                            class="flex items-center justify-end gap-1.5"
                                        >
                                            <span>{{ slot.start }} – {{ slot.end }}</span>
                                            <button
                                                type="button"
                                                class="inline-flex h-4 w-4 shrink-0 cursor-pointer items-center justify-center rounded-full text-noir-400 transition-colors hover:bg-bone-100 hover:text-noir-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
                                                :aria-label="`Remove ${slot.start} – ${slot.end}`"
                                                @click="removeSlot(slot.id)"
                                            >
                                                <X :size="11" aria-hidden="true" />
                                            </button>
                                        </li>
                                    </ul>
                                    <span v-else class="font-normal text-noir-400">Pick a slot</span>
                                </dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-xs text-noir-500">Total</dt>
                                <dd class="text-right text-base font-semibold text-ash-700">
                                    <span v-if="chosenSlots.length">{{ money(sessionTotal) }}</span>
                                    <span v-else-if="court" class="text-sm font-normal text-noir-400">
                                        From {{ money(court.from_price) }}
                                    </span>
                                    <span v-else class="text-sm font-normal text-noir-400">—</span>
                                </dd>
                            </div>
                        </dl>

                        <button
                            type="button"
                            :disabled="chosenSlots.length === 0"
                            :class="[
                                'mt-6 flex h-12 w-full items-center justify-center gap-2 rounded-xl text-sm font-semibold',
                                'transition-colors duration-200 ease-[var(--ease-out-soft)]',
                                chosenSlots.length > 0
                                    ? 'cursor-pointer bg-brand-600 text-white shadow-card hover:bg-brand-500'
                                    : 'cursor-not-allowed bg-bone-200 text-noir-400',
                            ]"
                            @click="openDetails"
                        >
                            Continue to details
                            <ArrowRight :size="16" aria-hidden="true" />
                        </button>

                        <p class="mt-3 flex items-start gap-2 text-xs leading-relaxed text-noir-500">
                            <Timer :size="14" class="mt-0.5 shrink-0 text-noir-400" aria-hidden="true" />
                            Choosing a time holds it for {{ holdMinutes }} minutes while you pay.
                        </p>
                    </aside>
                </div>
            </div>
        </section>

        <!-- ── Already booked? ────────────────────────────────────────── -->
        <!-- Same subtle noir-corner-into-crimson wash as the hero, so the two
             dark bands on this page read as one consistent theme. bg-brand-600
             is the solid backdrop the semi-transparent noir-900/35 stop blends
             against — without it, that transparency shows the page's light
             background through instead of red, and the corner reads as grey. -->
        <section id="lookup" class="bg-brand-600 bg-gradient-to-br from-noir-900/35 via-brand-600 to-brand-600">
            <div class="mx-auto w-full max-w-2xl px-4 py-14 text-center sm:px-6 sm:py-20">
                <p class="text-[11px] font-semibold tracking-[0.25em] text-ash-400 uppercase">
                    Already booked?
                </p>
                <h2 class="font-display-heading mt-2 text-2xl text-white sm:text-3xl">
                    Find your reservation
                </h2>
                <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-bone-300">
                    Enter the booking code we sent you. No account, no password — the code is all you need.
                </p>

                <form
                    class="mx-auto mt-6 flex max-w-md flex-col gap-3 sm:flex-row sm:items-start"
                    @submit.prevent="submitLookup"
                >
                    <div class="flex-1">
                        <FormInput
                            v-model="lookupForm.code"
                            label="Booking code"
                            sr-only-label
                            :placeholder="lookupExample"
                            :icon="Search"
                            size="lg"
                            required
                            autocomplete="off"
                            autocapitalize="characters"
                            spellcheck="false"
                            class="font-mono tracking-wider uppercase"
                            :error="lookupForm.errors.code"
                        />
                    </div>

                    <!-- Deliberate exception, same reasoning as the hero: this band is
                         crimson, so the button stays noir purely so it doesn't disappear
                         into its own background. -->
                    <button
                        type="submit"
                        :disabled="lookupForm.processing"
                        class="inline-flex h-12 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-xl bg-noir-900 px-5 text-sm font-semibold text-white shadow-card transition-colors duration-200 ease-[var(--ease-out-soft)] hover:bg-noir-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:pointer-events-none disabled:opacity-60"
                    >
                        <LoaderCircle v-if="lookupForm.processing" :size="16" class="animate-spin" aria-hidden="true" />
                        <span>Find booking</span>
                    </button>
                </form>
            </div>
        </section>

        <!-- ── Details modal ──────────────────────────────────────────── -->
        <Modal v-model="checkoutOpen" size="lg" :closeable="!form.processing">
            <template #header>
                <p class="text-[11px] font-semibold tracking-[0.25em] text-ash-700 uppercase">
                    Almost there
                </p>
                <h2 class="font-display-heading mt-1 text-xl text-noir-900">Your details</h2>
                <p class="mt-1 text-sm text-noir-500">
                    Tell us who is playing and we will hold this slot for you.
                </p>
            </template>

            <form id="reserve-form" class="space-y-5" @submit.prevent="submit">
                <!-- Slot summary -->
                <div v-if="chosenSlots.length" class="rounded-xl bg-ash-50 p-4 ring-1 ring-inset ring-ash-300">
                    <p class="text-xs font-medium tracking-wide text-ash-700 uppercase">
                        {{ chosenSlots.length === 1 ? 'Your slot' : `Your ${chosenSlots.length} slots` }}
                    </p>
                    <!-- Mixed courts: group each court with its own times so the
                         guest confirms exactly which court holds which slot. -->
                    <div v-if="isMultiCourt" class="mt-1.5 space-y-2.5">
                        <div v-for="group in slotsByCourt" :key="group.court_id">
                            <p class="text-sm font-semibold text-noir-900">{{ group.court_name }}</p>
                            <ul class="mt-0.5 space-y-0.5 text-sm text-noir-600">
                                <li v-for="slot in group.slots" :key="slot.id">
                                    {{ slot.start }} – {{ slot.end }}
                                </li>
                            </ul>
                        </div>
                    </div>
                    <template v-else>
                        <p class="mt-1.5 text-base font-semibold text-noir-900">
                            {{ chosenSlots[0].court_name }}
                        </p>
                        <ul class="mt-0.5 space-y-0.5 text-sm text-noir-600">
                            <li v-for="slot in chosenSlots" :key="slot.id">
                                {{ slot.start }} – {{ slot.end }}
                                <span v-if="chosenSlots.length === 1">· {{ slot.duration_minutes }} minutes</span>
                            </li>
                        </ul>
                    </template>
                    <p class="mt-1 text-sm text-noir-600">{{ activeDay?.long_label }}</p>

                    <div class="mt-3.5 flex items-end justify-between border-t border-ash-500/40 pt-3">
                        <span class="text-sm text-noir-600">Total to pay</span>
                        <span class="text-xl leading-none font-semibold text-ash-700">
                            {{ money(sessionTotal) }}
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormInput
                        v-model="form.customer_name"
                        label="Full name"
                        placeholder="Juan dela Cruz"
                        autocomplete="name"
                        size="lg"
                        required
                        :error="form.errors.customer_name"
                    />

                    <FormInput
                        v-model="form.customer_phone"
                        label="Mobile number"
                        placeholder="0917 123 4567"
                        inputmode="tel"
                        autocomplete="tel"
                        size="lg"
                        required
                        hint="We text your confirmation here."
                        :error="form.errors.customer_phone"
                    />
                </div>

                <FormInput
                    v-model="form.customer_email"
                    label="Email address"
                    type="email"
                    placeholder="you@example.com"
                    autocomplete="email"
                    size="lg"
                    required
                    hint="We will email your confirmation and receipt here."
                    :error="form.errors.customer_email"
                />

                <p
                    class="flex items-start gap-2 rounded-lg bg-warn-50 p-3 text-xs leading-relaxed text-warn-700 ring-1 ring-inset ring-warn-500/20"
                >
                    <TriangleAlert :size="14" class="mt-0.5 shrink-0" aria-hidden="true" />
                    We will hold this slot for {{ holdMinutes }} minutes. You pay by QR on the next
                    screen.
                </p>
            </form>

            <template #footer>
                <button
                    type="button"
                    :disabled="form.processing"
                    class="inline-flex h-11 cursor-pointer items-center justify-center rounded-xl border border-bone-300 bg-white px-4 text-sm font-medium text-noir-700 shadow-card transition-colors hover:bg-bone-50 disabled:pointer-events-none disabled:opacity-60"
                    @click="checkoutOpen = false"
                >
                    Back to times
                </button>
                <button
                    type="submit"
                    form="reserve-form"
                    :disabled="form.processing"
                    class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-xl bg-brand-600 px-5 text-sm font-semibold text-white shadow-card transition-colors hover:bg-graphite-500 disabled:pointer-events-none disabled:opacity-60"
                >
                    <LoaderCircle v-if="form.processing" :size="16" class="animate-spin" aria-hidden="true" />
                    Confirm reservation
                </button>
            </template>
        </Modal>
    </PublicLayout>
</template>
