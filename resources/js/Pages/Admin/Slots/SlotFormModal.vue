<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import Button from '@/Components/Button.vue';
import FormDatePicker from '@/Components/FormDatePicker.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import Modal from '@/Components/Modal.vue';

/*
| SlotFormModal — add or edit one hand-placed slot.
|
| Both modes share a form because the fields are identical; only the verb, the
| endpoint and the HTTP method change. The overlap rule that makes this screen
| trustworthy is enforced server-side in StoreSlotRequest — the live duration
| readout here is a convenience, never the authority.
*/

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** null = create mode. */
    slot: { type: Object, default: null },
    courts: { type: Array, default: () => [] },
    courtId: { type: [Number, String], default: null },
    date: { type: String, default: null },
    /**
     * The shared tier windows: { window: { peak: { start, end }, non_peak: {…} } }.
     * The MONEY is not here — it lives on each court in `courts[].rates`,
     * because two courts of different types charge differently for the same
     * hour.
     */
    pricing: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const editing = computed(() => props.slot !== null);

const form = useForm({
    court_id: '',
    slot_date: '',
    start_time: '',
    end_time: '',
    price: '',
    status: 'available',
});

const courtOptions = computed(() =>
    props.courts.map((court) => ({
        value: court.id,
        label: court.is_active ? court.name : `${court.name} (inactive)`,
    })),
);

const STATUS_OPTIONS = [
    { value: 'available', label: 'Available — open for booking' },
    { value: 'blocked', label: 'Blocked — kept off the public schedule' },
];

const today = new Date().toISOString().slice(0, 10);

function minutesOf(hhmm) {
    const match = /^(\d{1,2}):(\d{2})/.exec(String(hhmm ?? ''));

    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

/** Wrap-aware [start, end): a peak window of 17:00→00:00 covers the evening. */
function inWindow(min, start, end) {
    if (start === end) {
        return false;
    }

    return start < end ? min >= start && min < end : min >= start || min < end;
}

/** The two rates the given court charges, decided by its court type. */
function ratesForCourt(courtId) {
    const court = props.courts.find((candidate) => String(candidate.id) === String(courtId));

    return court?.rates ?? {};
}

/**
 * The rate for a slot on the given court starting at the given time — peak when
 * it falls in the (possibly past-midnight) peak window, non-peak otherwise.
 *
 * The WINDOW is club-wide but the MONEY is not: the same 7 PM hour is priced
 * one way on a full-size court and another on the Skinny Court, so the court
 * matters again and both arguments are required.
 */
function rateForTime(startTime, courtId) {
    const rates = ratesForCourt(courtId);
    const peakWindow = props.pricing?.window?.peak ?? {};
    const min = minutesOf(startTime);

    if (min === null) {
        return String(rates.non_peak ?? '');
    }

    const peakStart = minutesOf(peakWindow.start);
    const peakEnd = minutesOf(peakWindow.end);

    if (peakStart !== null && peakEnd !== null && inWindow(min, peakStart, peakEnd)) {
        return String(rates.peak ?? '');
    }

    return String(rates.non_peak ?? '');
}

/** Repopulate whenever the dialog opens, so a cancelled edit never leaks. */
watch(
    () => props.modelValue,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();

        if (props.slot) {
            form.court_id = props.slot.court_id;
            form.slot_date = props.slot.slot_date;
            form.start_time = props.slot.start_time;
            form.end_time = props.slot.end_time;
            form.price = String(props.slot.price ?? '');
            form.status = props.slot.status === 'blocked' ? 'blocked' : 'available';

            return;
        }

        const courtId = props.courtId ?? props.courts[0]?.id ?? '';

        form.court_id = courtId;
        form.slot_date = props.date ?? today;
        form.start_time = '08:00';
        form.end_time = '09:00';
        form.price = rateForTime(form.start_time, form.court_id);
        form.status = 'available';
    },
    { immediate: true },
);

// In create mode BOTH inputs move the price: the start time sorts the hour into
// a tier, and the court decides what that tier costs on its type. Switching from
// a full-size court to the Skinny Court has to move the figure, or the admin
// silently saves a slot at four times the intended rate. The admin can still
// type over whatever this suggests.
watch(
    [() => form.start_time, () => form.court_id],
    ([startTime, courtId]) => {
        if (!editing.value && props.modelValue) {
            form.price = rateForTime(startTime, courtId);
        }
    },
);

const durationLabel = computed(() => {
    const start = toMinutes(form.start_time);
    const end = toMinutes(form.end_time);

    if (start === null || end === null) {
        return null;
    }

    const minutes = end - start;

    if (minutes <= 0) {
        return 'The end time must be later than the start time.';
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    const spelled = hours === 0 ? `${rest} minutes` : rest === 0 ? `${hours} hour${hours === 1 ? '' : 's'}` : `${hours}h ${rest}m`;

    return `This slot runs for ${spelled}.`;
});

const durationIsError = computed(() => durationLabel.value?.startsWith('The end time') === true);

function toMinutes(value) {
    const match = /^(\d{1,2}):(\d{2})$/.exec(String(value ?? ''));

    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

const submitting = ref(false);

function close() {
    emit('update:modelValue', false);
}

function submit() {
    submitting.value = true;

    const done = {
        preserveScroll: true,
        onSuccess: () => {
            close();
            emit('saved');
        },
        onFinish: () => {
            submitting.value = false;
        },
    };

    if (editing.value) {
        form.put(route('admin.slots.update', { slot: props.slot.id }), done);

        return;
    }

    form.post(route('admin.slots.store'), done);
}
</script>

<template>
    <Modal
        :model-value="modelValue"
        :title="editing ? 'Edit slot' : 'Add a slot'"
        :description="
            editing
                ? 'Adjust the window or the price. Held and booked slots cannot be changed.'
                : 'Place a single slot by hand. Use “Generate slots” for a repeating schedule.'
        "
        size="lg"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <form id="slot-form" class="space-y-5" @submit.prevent="submit">
            <FormSelect
                v-model="form.court_id"
                label="Court"
                :options="courtOptions"
                placeholder="Select a court"
                :error="form.errors.court_id"
                required
            />

            <FormDatePicker
                v-model="form.slot_date"
                label="Date"
                :min="editing ? null : today"
                :error="form.errors.slot_date"
                required
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <FormDatePicker
                    v-model="form.start_time"
                    type="time"
                    label="Start time"
                    :error="form.errors.start_time"
                    required
                />

                <FormDatePicker
                    v-model="form.end_time"
                    type="time"
                    label="End time"
                    :error="form.errors.end_time"
                    required
                />
            </div>

            <p
                v-if="durationLabel"
                :class="['text-xs', durationIsError ? 'text-danger-600' : 'text-ink-500']"
            >
                {{ durationLabel }}
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput
                    v-model="form.price"
                    type="number"
                    label="Price"
                    prefix="₱"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    :error="form.errors.price"
                    required
                />

                <FormSelect
                    v-model="form.status"
                    label="Availability"
                    :options="STATUS_OPTIONS"
                    :error="form.errors.status"
                />
            </div>
        </form>

        <template #footer>
            <Button variant="secondary" :disabled="submitting" @click="close">Cancel</Button>
            <Button :loading="submitting || form.processing" @click="submit">
                {{ editing ? 'Save changes' : 'Add slot' }}
            </Button>
        </template>
    </Modal>
</template>
