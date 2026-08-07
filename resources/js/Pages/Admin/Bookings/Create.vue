<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { CalendarPlus, CalendarX, Check, Clock, Info, Wallet } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormDatePicker from '@/Components/FormDatePicker.vue';
import FormInput from '@/Components/FormInput.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Skeleton from '@/Components/Skeleton.vue';

/*
| Manual booking — the desk keying in a reservation a customer arranged
| directly with the club, over chat or across the counter.
|
| The public checkout and this screen solve different problems and deliberately
| look different. A guest is being sold something and needs persuading; a staff
| member is recording a decision somebody already made and needs speed. So this
| is one page, no steps, no hero: pick a day, click the hours, type a name,
| choose how it lands.
|
| Two things here have no equivalent on the public site, and both are the point
| of the feature:
|
|   - Any date is reachable, including past ones. Keying in last night's
|     walk-in is a first-class use, not an edge case, and the server records a
|     finished session as `completed` rather than as a reservation.
|   - "Reserved" holds the court with no expiry clock. A guest's hold dies in
|     30 minutes because nobody is watching it; this one was promised by a
|     person, so only a person takes it back.
*/

const props = defineProps({
    /** Active courts, in display order — the grid's columns. */
    courts: { type: Array, default: () => [] },
    /**
     * The chosen day's inventory:
     * `{ date, date_label, is_past, rows: [{ key, time_range, starts_at,
     *   has_started, cells: { [courtId]: { id, status, price, label,
     *   selectable } } }], available_count, slot_count }`
     */
    schedule: { type: Object, default: null },
    selectedDate: { type: String, default: null },
    /** Server's today, so the "past date" notice never trusts the laptop clock. */
    today: { type: String, default: null },
    /** `{ value, label, description }` for each way the booking can land. */
    modes: { type: Array, default: () => [] },
});

const form = useForm({
    slot_ids: [],
    mode: props.modes[0]?.value ?? 'confirmed',
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    notes: '',
});

/* ------------------------------------------------------------------ */
/* The day                                                             */
/* ------------------------------------------------------------------ */

const date = ref(props.selectedDate ?? props.today ?? '');
const loadingSchedule = ref(false);

/*
| Changing the day re-requests only the schedule. The selection is cleared
| first and on purpose: slot ids are day-specific, so carrying them across a
| date change would silently post yesterday's hours under today's heading.
*/
watch(date, (next) => {
    if (!next || next === props.schedule?.date) {
        return;
    }

    form.slot_ids = [];
    loadingSchedule.value = true;

    router.get(
        route('admin.bookings.create'),
        { date: next },
        {
            only: ['schedule', 'selectedDate'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                loadingSchedule.value = false;
            },
        },
    );
});

const rows = computed(() => props.schedule?.rows ?? []);

const hasInventory = computed(() => (props.schedule?.slot_count ?? 0) > 0);

/** True once the whole chosen day is behind us — drives the backfill notice. */
const isPastDay = computed(() => Boolean(props.schedule?.is_past));

/* ------------------------------------------------------------------ */
/* Selection                                                           */
/* ------------------------------------------------------------------ */

function cellFor(row, courtId) {
    return row.cells?.[String(courtId)] ?? null;
}

function isChosen(cell) {
    return cell !== null && form.slot_ids.includes(cell.id);
}

function toggle(cell) {
    if (!cell?.selectable) {
        return;
    }

    form.slot_ids = isChosen(cell)
        ? form.slot_ids.filter((id) => id !== cell.id)
        : [...form.slot_ids, cell.id];
}

/** Every chosen cell, flattened back out of the grid for the summary panel. */
const chosen = computed(() =>
    rows.value.flatMap((row) =>
        props.courts
            .map((court) => ({ row, court, cell: cellFor(row, court.id) }))
            .filter(({ cell }) => isChosen(cell)),
    ),
);

const total = computed(() =>
    chosen.value.reduce((sum, { cell }) => sum + Number(cell?.price ?? 0), 0),
);

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

function money(value) {
    return peso.format(Number(value ?? 0));
}

/* ------------------------------------------------------------------ */
/* Submit                                                              */
/* ------------------------------------------------------------------ */

/*
| A finished session is recorded as completed whatever mode is selected. The
| server decides that from the slot times, so this mirrors the same rule rather
| than reading the date: an evening that ended an hour ago is a backfill even
| though "today" is not a past date. Without this, the button would promise
| "Confirmed" and the flash message would come back saying "completed".
|
| Empty selection reads as not-a-backfill, so the panel opens on the ordinary
| case instead of announcing history before anything has been picked.
*/
const isBackfill = computed(
    () =>
        isPastDay.value ||
        (chosen.value.length > 0 && chosen.value.every(({ row }) => row.has_ended)),
);

const effectiveMode = computed(() => {
    if (isBackfill.value) {
        return { label: 'Completed', hint: 'This schedule has already finished.' };
    }

    const mode = props.modes.find((option) => option.value === form.mode);

    return {
        label: mode?.value === 'reserved' ? 'Reserved' : 'Confirmed',
        hint: null,
    };
});

const canSubmit = computed(() => form.slot_ids.length > 0 && !form.processing);

function submit() {
    if (!canSubmit.value) {
        return;
    }

    form.post(route('admin.bookings.store'), { preserveScroll: true });
}

/** The first slot error, whichever index it landed on. */
const slotError = computed(() => {
    const key = Object.keys(form.errors).find(
        (name) => name === 'slot_ids' || name.startsWith('slot_ids.'),
    );

    return key ? form.errors[key] : null;
});
</script>

<template>
    <AppLayout title="New booking">
        <PageHeader
            title="New booking"
            subtitle="Key in a booking a customer arranged directly with the club."
            :breadcrumbs="[
                { label: 'Bookings', href: route('admin.bookings.index') },
                { label: 'New booking' },
            ]"
        >
            <template #actions>
                <Button variant="secondary" size="sm" :href="route('admin.bookings.index')">
                    Cancel
                </Button>
            </template>
        </PageHeader>

        <form class="mt-6 grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]" @submit.prevent="submit">
            <!-- ---------------------------------------------------------- -->
            <!-- Left: the day and its grid                                  -->
            <!-- ---------------------------------------------------------- -->
            <div class="space-y-5">
                <Card title="1. Choose the date" padding="md">
                    <div class="sm:max-w-xs">
                        <FormDatePicker
                            v-model="date"
                            label="Play date"
                            hint="Past dates are allowed — use them to record a session that already happened."
                        />
                    </div>

                    <Alert
                        v-if="isBackfill"
                        class="mt-4"
                        variant="info"
                        title="Recording a past date"
                        message="This schedule has already finished, so the booking will be saved as completed rather than as a reservation. It still counts towards revenue."
                    />
                </Card>

                <Card padding="none">
                    <div class="flex flex-wrap items-end justify-between gap-3 px-5 pt-5 sm:px-6">
                        <div>
                            <h2 class="text-sm font-semibold text-ink-900">2. Pick the hours</h2>
                            <p class="mt-0.5 text-xs text-ink-500">
                                {{ schedule?.date_label ?? 'Choose a date first.' }}
                            </p>
                        </div>
                        <p v-if="hasInventory" class="text-xs text-ink-500">
                            {{ schedule.available_count }} of {{ schedule.slot_count }} open
                        </p>
                    </div>

                    <p v-if="slotError" class="px-5 pt-3 text-xs text-danger-600 sm:px-6">
                        {{ slotError }}
                    </p>

                    <!-- In-flight date change -->
                    <div v-if="loadingSchedule" class="space-y-2 p-5 sm:p-6" role="status" aria-live="polite">
                        <span class="sr-only">Loading the schedule…</span>
                        <Skeleton v-for="row in 6" :key="row" rounded="lg" class="h-12 w-full" />
                    </div>

                    <!-- The day exists but nobody has generated hours for it -->
                    <EmptyState
                        v-else-if="!hasInventory"
                        class="p-5 sm:p-6"
                        :icon="CalendarX"
                        title="No slots on this date"
                        description="Bookings can only be placed on hours that already exist. Generate them in Slots first, then come back."
                    >
                        <template #action>
                            <Button variant="secondary" size="sm" :href="route('admin.slots.index')">
                                Go to Slots
                            </Button>
                        </template>
                    </EmptyState>

                    <!-- Time down the side, courts across the top -->
                    <div v-else class="overflow-x-auto p-5 sm:p-6">
                        <table class="w-full min-w-max border-separate border-spacing-0 text-sm">
                            <thead>
                                <tr>
                                    <th
                                        scope="col"
                                        class="sticky left-0 z-10 min-w-32 border-b border-ink-200 bg-white px-3 py-2.5 text-left text-[11px] font-semibold tracking-wide text-ink-500 uppercase"
                                    >
                                        Time
                                    </th>
                                    <th
                                        v-for="court in courts"
                                        :key="court.id"
                                        scope="col"
                                        class="min-w-[8rem] border-b border-l border-ink-200 px-3 py-2.5 text-left align-bottom"
                                    >
                                        <span class="block truncate text-xs font-semibold text-ink-900">
                                            {{ court.name }}
                                        </span>
                                        <span class="mt-0.5 block text-[10px] text-ink-400">
                                            {{ court.category_label }}
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows" :key="row.key">
                                    <th
                                        scope="row"
                                        class="sticky left-0 z-10 border-b border-ink-200/70 bg-white px-3 py-2 text-left text-xs font-medium whitespace-nowrap text-ink-700"
                                    >
                                        {{ row.time_range }}
                                        <!-- An hour that has already started is still
                                             pickable; saying so beats letting staff
                                             wonder whether the row is a mistake. -->
                                        <span
                                            v-if="row.has_started && !schedule.is_past"
                                            class="mt-0.5 flex items-center gap-1 text-[10px] font-normal text-ink-400"
                                        >
                                            <Clock :size="11" aria-hidden="true" />
                                            Started
                                        </span>
                                    </th>

                                    <td
                                        v-for="court in courts"
                                        :key="court.id"
                                        class="border-b border-l border-ink-200/70 p-1.5 align-middle"
                                    >
                                        <button
                                            v-if="cellFor(row, court.id)?.selectable"
                                            type="button"
                                            :class="[
                                                'w-full rounded-lg px-2 py-2 text-xs font-semibold transition-colors',
                                                isChosen(cellFor(row, court.id))
                                                    ? 'bg-ink-900 text-white'
                                                    : 'bg-ink-50 text-ink-700 ring-1 ring-inset ring-ink-200 hover:bg-ink-100',
                                            ]"
                                            :aria-pressed="isChosen(cellFor(row, court.id))"
                                            @click="toggle(cellFor(row, court.id))"
                                        >
                                            <Check
                                                v-if="isChosen(cellFor(row, court.id))"
                                                :size="13"
                                                class="mr-1 inline"
                                                aria-hidden="true"
                                            />
                                            {{ cellFor(row, court.id).label }}
                                        </button>

                                        <!-- Taken, blocked, or no slot generated for
                                             this court at this hour. Shown rather
                                             than blanked so a full evening reads as
                                             full, not as empty. -->
                                        <span
                                            v-else
                                            class="block px-2 py-2 text-center text-[11px] text-ink-400"
                                        >
                                            {{ cellFor(row, court.id)?.label ?? '—' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card title="3. Who is it for" padding="md">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormInput
                            v-model="form.customer_name"
                            label="Customer name"
                            required
                            :error="form.errors.customer_name"
                        />
                        <FormInput
                            v-model="form.customer_phone"
                            label="Mobile number"
                            placeholder="0917 123 4567"
                            required
                            :error="form.errors.customer_phone"
                        />
                        <FormInput
                            v-model="form.customer_email"
                            label="Email address"
                            type="email"
                            label-hint="Optional"
                            hint="Kept for your records only — nothing is emailed for a manual booking."
                            :error="form.errors.customer_email"
                        />
                        <div class="sm:col-span-2">
                            <FormTextarea
                                v-model="form.notes"
                                label="Notes"
                                hint="Optional — how this booking was arranged, so the next person to open it knows."
                                :rows="3"
                                placeholder="e.g. arranged with the owner over chat"
                                :error="form.errors.notes"
                            />
                        </div>
                    </div>
                </Card>
            </div>

            <!-- ---------------------------------------------------------- -->
            <!-- Right: how it lands, and the total                          -->
            <!-- ---------------------------------------------------------- -->
            <aside class="space-y-5 lg:sticky lg:top-6 lg:self-start">
                <Card title="4. How it lands" padding="md">
                    <div class="space-y-2">
                        <label
                            v-for="option in modes"
                            :key="option.value"
                            :class="[
                                'flex cursor-pointer gap-3 rounded-xl p-3 ring-1 ring-inset transition-colors',
                                form.mode === option.value
                                    ? 'bg-brand-50/60 ring-brand-300'
                                    : 'ring-ink-200 hover:bg-ink-50',
                                isBackfill ? 'cursor-not-allowed opacity-50' : '',
                            ]"
                        >
                            <input
                                v-model="form.mode"
                                type="radio"
                                name="mode"
                                :value="option.value"
                                :disabled="isBackfill"
                                class="mt-0.5 h-4 w-4 shrink-0 accent-brand-600"
                            />
                            <span class="min-w-0">
                                <span class="block text-xs font-semibold text-ink-900">
                                    {{ option.label }}
                                </span>
                                <span class="mt-0.5 block text-[11px] leading-snug text-ink-500">
                                    {{ option.description }}
                                </span>
                            </span>
                        </label>
                    </div>

                    <p v-if="form.errors.mode" class="mt-2 text-xs text-danger-600">
                        {{ form.errors.mode }}
                    </p>

                    <p class="mt-3 flex gap-2 text-[11px] leading-snug text-ink-500">
                        <Wallet :size="14" class="mt-px shrink-0" aria-hidden="true" />
                        A settled manual booking is recorded as paid in cash. Nothing is emailed
                        to the customer either way.
                    </p>
                </Card>

                <Card title="Summary" padding="md">
                    <p v-if="!chosen.length" class="text-xs text-ink-500">
                        Pick at least one hour from the grid.
                    </p>

                    <ul v-else class="space-y-2">
                        <li
                            v-for="{ row, court, cell } in chosen"
                            :key="cell.id"
                            class="flex items-start justify-between gap-3 text-xs"
                        >
                            <span class="min-w-0">
                                <span class="block font-medium text-ink-900">{{ court.name }}</span>
                                <span class="block text-ink-500">{{ row.time_range }}</span>
                            </span>
                            <span class="shrink-0 font-semibold text-ink-900">
                                {{ money(cell.price) }}
                            </span>
                        </li>
                    </ul>

                    <div
                        v-if="chosen.length"
                        class="mt-4 flex items-center justify-between border-t border-ink-200 pt-3"
                    >
                        <span class="text-xs font-semibold tracking-wide text-ink-500 uppercase">
                            Total
                        </span>
                        <span class="text-base font-semibold text-ink-900">{{ money(total) }}</span>
                    </div>

                    <p class="mt-2 flex gap-2 text-[11px] leading-snug text-ink-400">
                        <Info :size="13" class="mt-px shrink-0" aria-hidden="true" />
                        The total comes from the slot prices and cannot be edited here.
                    </p>

                    <template #footer>
                        <div class="flex flex-col gap-2">
                            <Button type="submit" :disabled="!canSubmit" :loading="form.processing">
                                <template #icon><CalendarPlus :size="15" aria-hidden="true" /></template>
                                Save as {{ effectiveMode.label }}
                            </Button>
                            <p v-if="effectiveMode.hint" class="text-center text-[11px] text-ink-400">
                                {{ effectiveMode.hint }}
                            </p>
                        </div>
                    </template>
                </Card>
            </aside>
        </form>
    </AppLayout>
</template>
