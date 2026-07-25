<script setup>
import { computed, useAttrs, useId } from 'vue';
import FormField from './FormField.vue';

/*
| FormTextarea
|
|   <FormTextarea v-model="form.description" label="Description" :rows="5"
|                 :maxlength="500" show-count :error="form.errors.description" />
*/

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: null },
    placeholder: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    rows: { type: Number, default: 4 },
    maxlength: { type: Number, default: null },
    /** Show "12 / 500" beside the label. Requires `maxlength`. */
    showCount: { type: Boolean, default: false },
    /** Allow the user to drag-resize. */
    resizable: { type: Boolean, default: true },
    srOnlyLabel: { type: Boolean, default: false },
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'blur', 'focus']);

const attrs = useAttrs();
const uid = useId();
const inputId = computed(() => props.id ?? `textarea-${uid}`);

const describedBy = computed(() => {
    if (props.error) {
        return `${inputId.value}-error`;
    }

    return props.hint ? `${inputId.value}-hint` : undefined;
});

const counter = computed(() =>
    props.showCount && props.maxlength
        ? `${String(props.modelValue ?? '').length} / ${props.maxlength}`
        : null,
);
</script>

<template>
    <FormField
        :label="label"
        :input-id="inputId"
        :hint="hint"
        :error="error"
        :required="required"
        :label-hint="counter"
        :sr-only-label="srOnlyLabel"
    >
        <textarea
            v-bind="attrs"
            :id="inputId"
            :value="modelValue"
            :rows="rows"
            :maxlength="maxlength ?? undefined"
            :placeholder="placeholder ?? undefined"
            :required="required"
            :disabled="disabled"
            :readonly="readonly"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            :class="[
                'block w-full rounded-lg border bg-white px-3 py-2.5 text-sm leading-relaxed text-ink-900 shadow-card',
                'placeholder:text-ink-400',
                'transition-[border-color,box-shadow] duration-150 ease-[var(--ease-out-soft)]',
                'focus:outline-none focus:ring-2',
                resizable ? 'resize-y' : 'resize-none',
                error
                    ? 'border-danger-500 focus:border-danger-500 focus:ring-danger-500/25'
                    : 'border-ink-200 hover:border-ink-300 focus:border-brand-500 focus:ring-brand-500/25',
                disabled || readonly ? 'cursor-not-allowed bg-ink-50 text-ink-500' : '',
            ]"
            @input="emit('update:modelValue', $event.target.value)"
            @blur="emit('blur', $event)"
            @focus="emit('focus', $event)"
        />
    </FormField>
</template>
