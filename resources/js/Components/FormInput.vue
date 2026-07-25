<script setup>
import { computed, ref, useAttrs, useId } from 'vue';
import { Eye, EyeOff } from '@lucide/vue';
import FormField from './FormField.vue';

/*
| FormInput
|
|   <FormInput v-model="form.name" label="Court name" required
|              :error="form.errors.name" hint="Shown on the public site" />
|
|   <FormInput v-model="form.price" label="Price" type="number" prefix="₱" step="0.01" />
|   <FormInput v-model="form.password" label="Password" type="password" />   ← reveal toggle
*/

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, default: null },
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    /** sm | md | lg */
    size: { type: String, default: 'md' },
    /** A Lucide component rendered inside the field, on the left. */
    icon: { type: [Object, Function], default: null },
    /** Static text affixes, e.g. "₱" or "/hour". */
    prefix: { type: String, default: null },
    suffix: { type: String, default: null },
    labelHint: { type: String, default: null },
    srOnlyLabel: { type: Boolean, default: false },
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'enter', 'blur', 'focus']);

const attrs = useAttrs();
const uid = useId();
const inputId = computed(() => props.id ?? `input-${uid}`);

const revealed = ref(false);

const isPassword = computed(() => props.type === 'password');

const resolvedType = computed(() =>
    isPassword.value && revealed.value ? 'text' : props.type,
);

const SIZES = {
    sm: 'h-8 text-xs',
    md: 'h-10 text-sm',
    lg: 'h-12 text-sm',
};

const describedBy = computed(() => {
    if (props.error) {
        return `${inputId.value}-error`;
    }

    return props.hint ? `${inputId.value}-hint` : undefined;
});

const hasLeft = computed(() => Boolean(props.icon || props.prefix));
const hasRight = computed(() => Boolean(props.suffix || isPassword.value));

function onInput(event) {
    const value = event.target.value;

    emit('update:modelValue', props.type === 'number' && value !== '' ? Number(value) : value);
}
</script>

<template>
    <FormField
        :label="label"
        :input-id="inputId"
        :hint="hint"
        :error="error"
        :required="required"
        :label-hint="labelHint"
        :sr-only-label="srOnlyLabel"
    >
        <template v-if="$slots.labelAction" #labelAction><slot name="labelAction" /></template>

        <div class="relative">
            <span
                v-if="hasLeft"
                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-400"
            >
                <component :is="icon" v-if="icon" :size="16" aria-hidden="true" />
                <span v-else class="text-sm">{{ prefix }}</span>
            </span>

            <input
                v-bind="attrs"
                :id="inputId"
                :type="resolvedType"
                :value="modelValue"
                :placeholder="placeholder ?? undefined"
                :required="required"
                :disabled="disabled"
                :readonly="readonly"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                :class="[
                    'block w-full rounded-lg border bg-white text-ink-900 shadow-card',
                    'placeholder:text-ink-400',
                    'transition-[border-color,box-shadow] duration-150 ease-[var(--ease-out-soft)]',
                    'focus:outline-none focus:ring-2 focus:ring-offset-0',
                    SIZES[size] ?? SIZES.md,
                    hasLeft ? 'pl-9' : 'pl-3',
                    hasRight ? 'pr-10' : 'pr-3',
                    error
                        ? 'border-danger-500 focus:border-danger-500 focus:ring-danger-500/25'
                        : 'border-ink-200 hover:border-ink-300 focus:border-brand-500 focus:ring-brand-500/25',
                    disabled || readonly ? 'cursor-not-allowed bg-ink-50 text-ink-500' : '',
                ]"
                @input="onInput"
                @keyup.enter="emit('enter', $event)"
                @blur="emit('blur', $event)"
                @focus="emit('focus', $event)"
            />

            <button
                v-if="isPassword"
                type="button"
                class="absolute inset-y-0 right-0 flex cursor-pointer items-center px-3 text-ink-400 transition-colors hover:text-ink-700 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500"
                :aria-label="revealed ? 'Hide password' : 'Show password'"
                @click="revealed = !revealed"
            >
                <component :is="revealed ? EyeOff : Eye" :size="16" aria-hidden="true" />
            </button>

            <span
                v-else-if="suffix"
                class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-sm text-ink-400"
            >
                {{ suffix }}
            </span>
        </div>
    </FormField>
</template>
