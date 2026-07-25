<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import axios from 'axios';
import { CircleCheck, LoaderCircle, TriangleAlert } from '@lucide/vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import FormDatePicker from '@/Components/FormDatePicker.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormToggle from '@/Components/FormToggle.vue';
import Modal from '@/Components/Modal.vue';
import { useSwal } from '@/Composables/useSwal';

/*
| GenerateSlotsModal — the bulk generator's control surface.
|
| The design goal is that nobody ever presses "Generate" without knowing the
| number. Every change to the pattern re-costs it against the server (the same
| SlotGeneratorService the write path uses, in dry-run mode), so the preview is
| never an approximation drawn in JavaScript — it is the real answer, including
| how many of those slots already exist and would be skipped.
*/

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    courts: { type: Array, default: () => [] },
    /** The club-wide rate table: { non_peak: { rate, start, end }, peak: { rate, start, end } }. */
    pricing: { type: Object, default: () => ({}) },
    courtId: { type: [Number, String], default: null },
    from: { type: String, default: null },
    limit: { type: Number, default: 2000 },
    /** Club-wide operating window from Settings > Company: { opening, closing } in "HH:MM".
     *  Seeds this run's opening/closing time so a fresh generate already matches the
     *  hours the operator set — still overridable per run. */
    operatingHours: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

const { confirmAction } = useSwal();

const DAYS = [
    { value: 1, short: 'M', label: 'Monday' },
    { value: 2, short: 'T', label: 'Tuesday' },
    { value: 3, short: 'W', label: 'Wednesday' },
    { value: 4, short: 'T', label: 'Thursday' },
    { value: 5, short: 'F', label: 'Friday' },
    { value: 6, short: 'S', label: 'Saturday' },
    { value: 0, short: 'S', label: 'Sunday' },
];

const WEEKDAYS = [1, 2, 3, 4, 5];
const WEEKEND = [0, 6];
const EVERY_DAY = [0, 1, 2, 3, 4, 5, 6];

const DURATIONS = [30, 45, 60, 90, 120];

const form = reactive({
    court_id: '',
    start_date: '',
    end_date: '',
    days_of_week: [...WEEKDAYS],
    opening_time: '07:00',
    closing_time: '02:00',
    duration_minutes: 60,
    price: '',
});

// Off by default: the club-wide non-peak/peak rates price the run, and nobody
// has to type anything. Flip it on for a one-off flat price.
const overridePrice = ref(false);

const errors = ref({});
const preview = ref(null);
const previewing = ref(false);
const submitting = ref(false);

const today = () => new Date().toISOString().slice(0, 10);

function addDays(date, days) {
    const value = new Date(`${date}T00:00:00`);
    value.setDate(value.getDate() + days);

    return value.toISOString().slice(0, 10);
}

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
});

const TIER_LABELS = {
    non_peak: 'Non-peak',
    peak: 'Peak',
};

/** "16:00" → "4:00 PM" for the read-only rate windows. */
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
 * The club-wide rate table as a display list — one card per tier, with its rate
 * and the clock window it covers.
 */
const tierRates = computed(() =>
    ['non_peak', 'peak'].map((key) => {
        const tier = props.pricing?.[key] ?? {};

        return {
            key,
            label: TIER_LABELS[key],
            hours: tier.start && tier.end ? `${to12h(tier.start)}–${to12h(tier.end)}` : '',
            amount: Number(tier.rate ?? 0),
        };
    }),
);

const courtOptions = computed(() =>
    props.courts.map((court) => ({
        value: court.id,
        label: court.is_active ? court.name : `${court.name} (inactive)`,
    })),
);

/* --------------------------------------------------------------------- */
/* Opening                                                                */
/* --------------------------------------------------------------------- */

watch(
    () => props.modelValue,
    (open) => {
        if (!open) {
            return;
        }

        const start = props.from && props.from >= today() ? props.from : today();
        const courtId = props.courtId ?? props.courts[0]?.id ?? '';

        errors.value = {};
        preview.value = null;

        form.court_id = courtId;
        form.start_date = start;
        // Four weeks is the shape of a season schedule and stays well clear of
        // the ceiling at hourly slots — a sane place to start, not a rule.
        form.end_date = addDays(start, 27);
        form.days_of_week = [...WEEKDAYS];
        // Default to the club's operating window (Settings > Company); fall back to
        // the historical 7am–2am only if it has not been configured.
        form.opening_time = props.operatingHours?.opening || '07:00';
        form.closing_time = props.operatingHours?.closing || '02:00';
        form.duration_minutes = 60;
        // Default to the club-wide rates — no typed price, no override.
        overridePrice.value = false;
        form.price = '';

        schedulePreview();
    },
    { immediate: true },
);

// Turning the override off clears any figure so the run reverts cleanly to the
// global rates; turning it on seeds the field with the non-peak rate as a start.
watch(overridePrice, (on) => {
    form.price = on ? String(props.pricing?.non_peak?.rate ?? '') : '';
});

/* --------------------------------------------------------------------- */
/* Day mask                                                               */
/* --------------------------------------------------------------------- */

function toggleDay(day) {
    const index = form.days_of_week.indexOf(day);

    if (index === -1) {
        form.days_of_week.push(day);
    } else {
        form.days_of_week.splice(index, 1);
    }
}

const isSelected = (day) => form.days_of_week.includes(day);

function applyPreset(preset) {
    form.days_of_week = [...preset];
}

const activePreset = computed(() => {
    const sorted = [...form.days_of_week].sort();
    const same = (list) => JSON.stringify(sorted) === JSON.stringify([...list].sort());

    if (same(EVERY_DAY)) return 'all';
    if (same(WEEKDAYS)) return 'weekdays';
    if (same(WEEKEND)) return 'weekend';

    return null;
});

/* --------------------------------------------------------------------- */
/* Live preview                                                           */
/* --------------------------------------------------------------------- */

let timer = null;
let inFlight = null;

const payload = () => ({
    court_id: form.court_id,
    start_date: form.start_date,
    end_date: form.end_date,
    days_of_week: form.days_of_week,
    opening_time: form.opening_time,
    closing_time: form.closing_time,
    duration_minutes: Number(form.duration_minutes) || 0,
    // Null (not 0) when not overriding: that is the signal to price from the
    // court's rates. A real number here overrides every slot in the run.
    price: overridePrice.value && form.price !== '' ? form.price : null,
});

function schedulePreview() {
    clearTimeout(timer);
    timer = setTimeout(runPreview, 350);
}

async function runPreview() {
    if (!form.court_id || !form.start_date || !form.end_date) {
        preview.value = null;

        return;
    }

    // Abandon the previous dry run: only the newest pattern is interesting.
    inFlight?.abort();
    inFlight = new AbortController();

    previewing.value = true;

    try {
        const { data } = await axios.post(route('admin.slots.generate.preview'), payload(), {
            signal: inFlight.signal,
        });

        preview.value = data;
        errors.value = {};
    } catch (error) {
        if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') {
            return;
        }

        preview.value = null;

        if (error?.response?.status === 422) {
            errors.value = error.response.data?.errors ?? {};
        } else {
            errors.value = {
                preview: ['Could not check that pattern just now. Try again in a moment.'],
            };
        }
    } finally {
        previewing.value = false;
    }
}

// Serialising the payload gives the watcher a value it can actually compare, so
// an unchanged pattern never fires a redundant dry run.
watch(() => JSON.stringify(payload()), schedulePreview);

/** "45 min" / "1 hr" / "1.5 hr" — kept out of the template so no `<` appears in a mustache. */
function durationChipLabel(minutes) {
    return minutes < 60 ? `${minutes} min` : `${minutes / 60} hr`;
}

onBeforeUnmount(() => {
    clearTimeout(timer);
    inFlight?.abort();
});

const firstError = (field) => {
    const value = errors.value?.[field];

    return Array.isArray(value) ? value[0] : (value ?? null);
};

const previewError = computed(
    () => firstError('preview') ?? (preview.value?.exceeds_limit ? preview.value.message : null),
);

const canSubmit = computed(
    () =>
        !previewing.value &&
        !submitting.value &&
        preview.value !== null &&
        preview.value.exceeds_limit !== true &&
        preview.value.total > 0,
);

const number = new Intl.NumberFormat('en-PH');

// The server's per-period costing for the current preview, only when it landed
// within the limit (a rejected preview has nothing to cost).
const pricingBreakdown = computed(() => {
    if (!preview.value || preview.value.exceeds_limit || preview.value.total === 0) {
        return [];
    }

    return preview.value.pricing?.breakdown ?? [];
});

/* --------------------------------------------------------------------- */
/* Commit                                                                 */
/* --------------------------------------------------------------------- */

async function submit() {
    if (!canSubmit.value) {
        return;
    }

    const summary = preview.value;
    const court = props.courts.find((item) => String(item.id) === String(form.court_id));

    const confirmed = await confirmAction({
        title: 'Generate these slots?',
        html:
            `<p style="margin:0 0 .5rem"><strong>${number.format(summary.created)}</strong> new slot(s) ` +
            `will be created for <strong>${escapeHtml(court?.name ?? 'this court')}</strong>.</p>` +
            (summary.skipped > 0
                ? `<p style="margin:0;font-size:.875rem">${number.format(summary.skipped)} already exist and will be left untouched.</p>`
                : ''),
        confirmText: 'Generate',
        cancelText: 'Review settings',
        variant: 'primary',
        icon: 'question',
    });

    if (!confirmed) {
        return;
    }

    submitting.value = true;

    router.post(route('admin.slots.generate'), payload(), {
        preserveScroll: true,
        onSuccess: () => emit('update:modelValue', false),
        onError: (bag) => {
            errors.value = Object.fromEntries(
                Object.entries(bag).map(([key, value]) => [key, [value]]),
            );
        },
        onFinish: () => {
            submitting.value = false;
        },
    });
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function close() {
    emit('update:modelValue', false);
}
</script>

<template>
    <Modal
        :model-value="modelValue"
        title="Generate slots"
        description="Describe the pattern once and lay it across a date range. Re-running is safe — existing slots are skipped, never duplicated."
        size="xl"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div class="space-y-6">
            <FormSelect
                v-model="form.court_id"
                label="Court"
                :options="courtOptions"
                placeholder="Select a court"
                :error="firstError('court_id')"
                required
            />

            <!-- Pricing: the club-wide non-peak/peak rates drive the run by
                 default; the admin only types a price to override them here. -->
            <div class="rounded-xl border border-ink-200/70 bg-ink-50/50 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm font-medium text-ink-700">Pricing</span>
                    <FormToggle
                        v-model="overridePrice"
                        label-right
                        size="sm"
                        label="Override with a flat price"
                    />
                </div>

                <!-- Default: show what each tier will be charged, read-only. -->
                <div v-if="!overridePrice" class="mt-3">
                    <p class="text-xs text-ink-500">
                        Each slot is priced from the club-wide rate table. Edit these under
                        <span class="font-medium text-ink-700">Settings › Booking › Court pricing</span>.
                    </p>

                    <dl class="mt-3 grid grid-cols-2 gap-2">
                        <div
                            v-for="rate in tierRates"
                            :key="rate.key"
                            class="rounded-lg border border-ink-200/70 bg-white px-3 py-2 text-center"
                        >
                            <dt class="text-[11px] font-medium text-ink-500">
                                {{ rate.label }}
                                <span class="block text-[10px] font-normal text-ink-400">{{ rate.hours }}</span>
                            </dt>
                            <dd class="mt-1 text-sm font-semibold tabular-nums text-ink-900">
                                {{ peso.format(rate.amount) }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Opt-in flat override for a one-off promo run. -->
                <div v-else class="mt-3">
                    <FormInput
                        v-model="form.price"
                        type="number"
                        label="Flat price per slot"
                        sr-only-label
                        prefix="₱"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                        :error="firstError('price')"
                        hint="Applied to every slot in this run, ignoring the club-wide rates."
                    />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormDatePicker
                    v-model="form.start_date"
                    label="From"
                    :error="firstError('start_date')"
                    required
                />

                <FormDatePicker
                    v-model="form.end_date"
                    label="To"
                    :min="form.start_date"
                    :error="firstError('end_date')"
                    required
                />
            </div>

            <!-- Day-of-week mask -->
            <div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm font-medium text-ink-700">Days of the week</span>

                    <div class="flex items-center gap-1">
                        <button
                            v-for="preset in [
                                { key: 'weekdays', label: 'Weekdays', value: WEEKDAYS },
                                { key: 'weekend', label: 'Weekend', value: WEEKEND },
                                { key: 'all', label: 'Every day', value: EVERY_DAY },
                            ]"
                            :key="preset.key"
                            type="button"
                            :class="[
                                'cursor-pointer rounded-md px-2 py-1 text-[11px] font-medium transition-colors duration-150 ease-[var(--ease-out-soft)]',
                                activePreset === preset.key
                                    ? 'bg-brand-50 text-brand-700'
                                    : 'text-ink-500 hover:bg-ink-100 hover:text-ink-800',
                            ]"
                            @click="applyPreset(preset.value)"
                        >
                            {{ preset.label }}
                        </button>
                    </div>
                </div>

                <div class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        v-for="day in DAYS"
                        :key="day.value"
                        type="button"
                        :aria-pressed="isSelected(day.value)"
                        :aria-label="day.label"
                        :title="day.label"
                        :class="[
                            'h-10 w-10 cursor-pointer rounded-lg border text-sm font-semibold transition-all duration-150 ease-[var(--ease-out-soft)]',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                            isSelected(day.value)
                                ? 'border-brand-600 bg-brand-600 text-white shadow-card'
                                : 'border-ink-200 bg-white text-ink-500 hover:border-ink-300 hover:bg-ink-50 hover:text-ink-800',
                        ]"
                        @click="toggleDay(day.value)"
                    >
                        {{ day.short }}
                    </button>
                </div>

                <p v-if="firstError('days_of_week')" class="mt-1.5 text-xs text-danger-600">
                    {{ firstError('days_of_week') }}
                </p>
            </div>

            <!-- Opening window -->
            <div class="grid gap-4 sm:grid-cols-2">
                <FormDatePicker
                    v-model="form.opening_time"
                    type="time"
                    label="Opens at"
                    :error="firstError('opening_time')"
                    required
                />

                <FormDatePicker
                    v-model="form.closing_time"
                    type="time"
                    label="Closes at"
                    hint="A closing time earlier than the opening time is read as “after midnight”."
                    :error="firstError('closing_time')"
                    required
                />
            </div>

            <!-- Duration -->
            <div>
                <span class="text-sm font-medium text-ink-700">Slot duration</span>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button
                        v-for="minutes in DURATIONS"
                        :key="minutes"
                        type="button"
                        :aria-pressed="Number(form.duration_minutes) === minutes"
                        :class="[
                            'h-9 cursor-pointer rounded-lg border px-3 text-xs font-semibold transition-all duration-150 ease-[var(--ease-out-soft)]',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                            Number(form.duration_minutes) === minutes
                                ? 'border-brand-600 bg-brand-50 text-brand-700'
                                : 'border-ink-200 bg-white text-ink-600 hover:border-ink-300 hover:bg-ink-50',
                        ]"
                        @click="form.duration_minutes = minutes"
                    >
                        {{ durationChipLabel(minutes) }}
                    </button>

                    <div class="w-32">
                        <FormInput
                            v-model="form.duration_minutes"
                            type="number"
                            size="sm"
                            min="5"
                            max="1440"
                            step="5"
                            suffix="min"
                            label="Custom duration"
                            sr-only-label
                        />
                    </div>
                </div>

                <p v-if="firstError('duration_minutes')" class="mt-1.5 text-xs text-danger-600">
                    {{ firstError('duration_minutes') }}
                </p>
            </div>

            <!-- Live cost of the pattern -->
            <Alert v-if="previewError" variant="error" title="That pattern will not run">
                {{ previewError }}
            </Alert>

            <div
                v-else
                class="rounded-xl border border-ink-200/70 bg-ink-50/70 p-4"
                aria-live="polite"
            >
                <div v-if="previewing" class="flex items-center gap-2 text-sm text-ink-500">
                    <LoaderCircle :size="16" class="animate-spin" aria-hidden="true" />
                    Working out how many slots this makes…
                </div>

                <div v-else-if="!preview" class="text-sm text-ink-500">
                    Fill in the pattern above to see how many slots it produces.
                </div>

                <div v-else-if="preview.total === 0" class="flex items-start gap-2 text-sm text-warn-700">
                    <TriangleAlert :size="16" class="mt-0.5 shrink-0" aria-hidden="true" />
                    These settings do not produce a single slot. Check the selected days and the
                    opening hours.
                </div>

                <div v-else class="flex flex-wrap items-center gap-x-6 gap-y-3">
                    <div class="flex items-center gap-2.5">
                        <span
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-success-50 text-success-600"
                            aria-hidden="true"
                        >
                            <CircleCheck :size="18" />
                        </span>
                        <span>
                            <span class="block text-lg leading-tight font-semibold tabular-nums text-ink-900">
                                {{ number.format(preview.created) }}
                            </span>
                            <span class="block text-xs text-ink-500">slots will be created</span>
                        </span>
                    </div>

                    <div v-if="preview.skipped > 0" class="text-sm text-ink-600">
                        <span class="font-semibold tabular-nums">{{ number.format(preview.skipped) }}</span>
                        already exist and will be skipped
                    </div>

                    <div class="text-xs text-ink-500">
                        {{ number.format(preview.days) }} day{{ preview.days === 1 ? '' : 's' }}
                        ×
                        {{ number.format(preview.slots_per_day) }} per day
                        =
                        {{ number.format(preview.total) }} total
                        <span class="text-ink-400">(limit {{ number.format(preview.limit ?? limit) }})</span>
                    </div>
                </div>

                <!-- Per-period pricing breakdown: exactly what the write path
                     will stamp, so the number and the money are never a guess. -->
                <div
                    v-if="pricingBreakdown.length"
                    class="mt-3 border-t border-ink-200/70 pt-3"
                >
                    <p class="text-[11px] font-medium tracking-wide text-ink-500 uppercase">
                        {{ preview.pricing?.source === 'override' ? 'Flat price' : 'Rate by tier' }}
                    </p>

                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1.5">
                        <div
                            v-for="band in pricingBreakdown"
                            :key="band.tier"
                            class="text-xs text-ink-600"
                        >
                            <span class="font-medium text-ink-800">{{ TIER_LABELS[band.tier] ?? band.tier }}</span>
                            <span class="tabular-nums">
                                {{ peso.format(band.rate) }} × {{ number.format(band.slots) }}
                            </span>
                            <span class="text-ink-400">=</span>
                            <span class="font-semibold tabular-nums text-ink-700">{{ peso.format(band.subtotal) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="secondary" :disabled="submitting" @click="close">Cancel</Button>
            <Button :disabled="!canSubmit" :loading="submitting" @click="submit">
                {{
                    preview && preview.created > 0
                        ? `Generate ${number.format(preview.created)} slots`
                        : 'Generate slots'
                }}
            </Button>
        </template>
    </Modal>
</template>
