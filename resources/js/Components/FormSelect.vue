<script setup>
import { computed, useAttrs, useId } from 'vue';
import { ChevronDown } from '@lucide/vue';
import FormField from './FormField.vue';

/*
| FormSelect — native select, styled.
|
| `options` accepts either primitives or objects. When objects are used,
| `optionValue` / `optionLabel` name the keys (defaults: value / label), so
| Eloquent collections work unchanged:
|
|   <FormSelect v-model="form.court_id" label="Court" :options="courts"
|               option-value="id" option-label="name" placeholder="Select a court" />
|
|   <FormSelect v-model="table.filters.status" label="Status" clearable
|               :options="statusOptions(BOOKING_STATUSES)" placeholder="All statuses" />
*/

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [String, Number, Boolean], default: '' },
    /** Array of primitives or objects. */
    options: { type: Array, default: () => [] },
    optionValue: { type: String, default: 'value' },
    optionLabel: { type: String, default: 'label' },
    label: { type: String, default: null },
    /** Text of the leading blank option. */
    placeholder: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** sm | md | lg */
    size: { type: String, default: 'md' },
    srOnlyLabel: { type: Boolean, default: false },
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'change']);

const attrs = useAttrs();
const uid = useId();
const inputId = computed(() => props.id ?? `select-${uid}`);

const SIZES = {
    sm: 'h-8 text-xs',
    md: 'h-10 text-sm',
    lg: 'h-12 text-sm',
};

const normalised = computed(() =>
    props.options.map((option) => {
        if (option === null || typeof option !== 'object') {
            return { value: option, label: String(option), disabled: false };
        }

        return {
            value: option[props.optionValue],
            label: String(option[props.optionLabel] ?? option[props.optionValue] ?? ''),
            disabled: Boolean(option.disabled),
        };
    }),
);

const describedBy = computed(() => {
    if (props.error) {
        return `${inputId.value}-error`;
    }

    return props.hint ? `${inputId.value}-hint` : undefined;
});

function onChange(event) {
    const raw = event.target.value;

    // Preserve numeric option values so route params and filters stay typed.
    const match = normalised.value.find((option) => String(option.value) === raw);

    emit('update:modelValue', match ? match.value : raw);
    emit('change', match ? match.value : raw);
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
            <select
                v-bind="attrs"
                :id="inputId"
                :value="modelValue === null || modelValue === undefined ? '' : String(modelValue)"
                :required="required"
                :disabled="disabled"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                :class="[
                    'block w-full appearance-none rounded-lg border bg-white pr-9 pl-3 text-ink-900 shadow-card',
                    'transition-[border-color,box-shadow] duration-150 ease-[var(--ease-out-soft)]',
                    'focus:outline-none focus:ring-2',
                    SIZES[size] ?? SIZES.md,
                    error
                        ? 'border-danger-500 focus:border-danger-500 focus:ring-danger-500/25'
                        : 'border-ink-200 hover:border-ink-300 focus:border-brand-500 focus:ring-brand-500/25',
                    disabled ? 'cursor-not-allowed bg-ink-50 text-ink-500' : 'cursor-pointer',
                    modelValue === '' || modelValue === null ? 'text-ink-400' : '',
                ]"
                @change="onChange"
            >
                <option v-if="placeholder" value="">{{ placeholder }}</option>
                <slot>
                    <option
                        v-for="option in normalised"
                        :key="String(option.value)"
                        :value="String(option.value)"
                        :disabled="option.disabled"
                    >
                        {{ option.label }}
                    </option>
                </slot>
            </select>

            <ChevronDown
                :size="16"
                class="pointer-events-none absolute inset-y-0 right-3 my-auto text-ink-400"
                aria-hidden="true"
            />
        </div>
    </FormField>
</template>
