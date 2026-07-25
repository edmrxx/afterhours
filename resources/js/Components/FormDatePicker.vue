<script setup>
import { computed, ref, useAttrs, useId } from 'vue';
import { Calendar, Clock } from '@lucide/vue';
import FormField from './FormField.vue';

/*
| FormDatePicker — the native date/time input, restyled to match the kit.
|
| Native beats a JS date library here: it is zero-kB, fully accessible and gives
| mobile users the OS picker. Values are plain strings in the format Laravel
| expects (Y-m-d, H:i, Y-m-d\TH:i) so they post straight through.
|
|   <FormDatePicker v-model="form.slot_date" label="Date" :min="today" required />
|   <FormDatePicker v-model="form.start_time" label="Start" type="time" />
*/

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    /** date | time | datetime-local | month | week */
    type: { type: String, default: 'date' },
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    min: { type: String, default: null },
    max: { type: String, default: null },
    step: { type: [String, Number], default: null },
    /** sm | md | lg */
    size: { type: String, default: 'md' },
    srOnlyLabel: { type: Boolean, default: false },
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'change']);

const attrs = useAttrs();
const uid = useId();
const input = ref(null);
const inputId = computed(() => props.id ?? `date-${uid}`);

const SIZES = {
    sm: 'h-8 text-xs',
    md: 'h-10 text-sm',
    lg: 'h-12 text-sm',
};

const icon = computed(() => (props.type === 'time' ? Clock : Calendar));

const describedBy = computed(() => {
    if (props.error) {
        return `${inputId.value}-error`;
    }

    return props.hint ? `${inputId.value}-hint` : undefined;
});

/** Clicking the field opens the OS picker where the browser supports it. */
function openPicker() {
    if (props.disabled || props.readonly) {
        return;
    }

    try {
        input.value?.showPicker?.();
    } catch {
        // Safari and Firefox throw outside a user gesture — the native
        // indicator still works, so this is safe to swallow.
    }
}
</script>

<template>
    <FormField
        :label="label"
        :input-id="inputId"
        :hint="hint"
        :error="error"
        :required="required"
        :sr-only-label="srOnlyLabel"
    >
        <div class="relative">
            <span
                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-400"
            >
                <component :is="icon" :size="16" aria-hidden="true" />
            </span>

            <input
                v-bind="attrs"
                :id="inputId"
                ref="input"
                :type="type"
                :value="modelValue"
                :min="min ?? undefined"
                :max="max ?? undefined"
                :step="step ?? undefined"
                :required="required"
                :disabled="disabled"
                :readonly="readonly"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                :class="[
                    'block w-full rounded-lg border bg-white pr-3 pl-9 text-ink-900 shadow-card',
                    'transition-[border-color,box-shadow] duration-150 ease-[var(--ease-out-soft)]',
                    'focus:outline-none focus:ring-2',
                    '[&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-60 [&::-webkit-calendar-picker-indicator]:hover:opacity-100',
                    SIZES[size] ?? SIZES.md,
                    error
                        ? 'border-danger-500 focus:border-danger-500 focus:ring-danger-500/25'
                        : 'border-ink-200 hover:border-ink-300 focus:border-brand-500 focus:ring-brand-500/25',
                    disabled || readonly ? 'cursor-not-allowed bg-ink-50 text-ink-500' : 'cursor-pointer',
                ]"
                @click="openPicker"
                @input="emit('update:modelValue', $event.target.value)"
                @change="emit('change', $event.target.value)"
            />
        </div>
    </FormField>
</template>
