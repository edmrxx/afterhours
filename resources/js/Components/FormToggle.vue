<script setup>
import { computed, useId } from 'vue';

/*
| FormToggle — switch for settings and instant status flips.
|
|   <FormToggle v-model="form.is_active" label="Accept bookings"
|               hint="Turn off to take the court off the public site" />
*/

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    /** sm | md */
    size: { type: String, default: 'md' },
    /** Put the label to the right of the switch instead of the left. */
    labelRight: { type: Boolean, default: false },
    /** Accessible name when no visible label is rendered. */
    ariaLabel: { type: String, default: null },
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'change']);

const uid = useId();
const inputId = computed(() => props.id ?? `toggle-${uid}`);

const SIZES = {
    sm: { track: 'h-5 w-9', knob: 'h-4 w-4', shift: 'translate-x-4' },
    md: { track: 'h-6 w-11', knob: 'h-5 w-5', shift: 'translate-x-5' },
};

const dims = computed(() => SIZES[props.size] ?? SIZES.md);

const isBusy = computed(() => props.disabled || props.loading);

function toggle() {
    if (isBusy.value) {
        return;
    }

    emit('update:modelValue', !props.modelValue);
    emit('change', !props.modelValue);
}
</script>

<template>
    <div class="w-full">
        <div
            :class="[
                'flex items-start gap-3',
                labelRight ? 'flex-row' : 'flex-row-reverse justify-between',
            ]"
        >
            <button
                :id="inputId"
                type="button"
                role="switch"
                :aria-checked="modelValue"
                :aria-label="ariaLabel ?? (label ? undefined : 'Toggle')"
                :aria-describedby="hint ? `${inputId}-hint` : undefined"
                :disabled="isBusy"
                :class="[
                    'relative inline-flex shrink-0 items-center rounded-full border-2 border-transparent',
                    'transition-colors duration-200 ease-[var(--ease-out-soft)]',
                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                    dims.track,
                    modelValue ? 'bg-brand-600' : 'bg-ink-300',
                    isBusy ? 'cursor-not-allowed opacity-55' : 'cursor-pointer',
                    labelRight ? '' : 'mt-0.5',
                ]"
                @click="toggle"
            >
                <span
                    :class="[
                        'pointer-events-none inline-block transform rounded-full bg-white shadow-card',
                        'transition-transform duration-200 ease-[var(--ease-out-soft)]',
                        dims.knob,
                        modelValue ? dims.shift : 'translate-x-0',
                        loading ? 'animate-pulse' : '',
                    ]"
                />
            </button>

            <div v-if="label || hint" class="min-w-0 flex-1">
                <label
                    :for="inputId"
                    :class="[
                        'block text-sm leading-tight font-medium text-ink-800',
                        isBusy ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                    ]"
                >
                    {{ label }}
                </label>
                <p v-if="hint" :id="`${inputId}-hint`" class="mt-1 text-xs leading-relaxed text-ink-500">
                    {{ hint }}
                </p>
            </div>
        </div>

        <p v-if="error" class="mt-1.5 text-xs font-medium text-danger-600" role="alert">
            {{ error }}
        </p>
    </div>
</template>
