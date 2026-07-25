<script setup>
import { computed, useAttrs, useId } from 'vue';
import { Check, Minus } from '@lucide/vue';

/*
| FormCheckbox — single boolean, or one member of a checkbox group.
|
|   <FormCheckbox v-model="form.is_active" label="Active"
|                 hint="Inactive courts are hidden from the public site" />
|
|   <FormCheckbox v-for="p in permissions" :key="p.id" v-model="form.permissions"
|                 :value="p.name" :label="p.name" />
*/

defineOptions({ inheritAttrs: false });

const props = defineProps({
    /** Boolean in single mode, Array in group mode. */
    modelValue: { type: [Boolean, Array], default: false },
    /** Presence switches the component into group mode. */
    value: { type: [String, Number, Boolean], default: null },
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    /** Tri-state visual for "some children selected". */
    indeterminate: { type: Boolean, default: false },
    /** Render as a bordered, clickable card. */
    card: { type: Boolean, default: false },
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'change']);

const attrs = useAttrs();
const uid = useId();
const inputId = computed(() => props.id ?? `checkbox-${uid}`);

const isGroup = computed(() => Array.isArray(props.modelValue));

const checked = computed(() =>
    isGroup.value ? props.modelValue.includes(props.value) : Boolean(props.modelValue),
);

function toggle(event) {
    const next = event.target.checked;

    if (isGroup.value) {
        const list = [...props.modelValue];
        const index = list.indexOf(props.value);

        if (next && index === -1) {
            list.push(props.value);
        } else if (!next && index !== -1) {
            list.splice(index, 1);
        }

        emit('update:modelValue', list);
        emit('change', list);

        return;
    }

    emit('update:modelValue', next);
    emit('change', next);
}
</script>

<template>
    <div
        :class="[
            card
                ? [
                      'rounded-xl border p-3.5 transition-colors duration-150 ease-[var(--ease-out-soft)]',
                      checked
                          ? 'border-brand-300 bg-brand-50/60'
                          : 'border-ink-200 bg-white hover:border-ink-300',
                  ]
                : '',
        ]"
    >
        <div class="flex items-start gap-2.5">
            <span class="relative mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center">
                <input
                    v-bind="attrs"
                    :id="inputId"
                    type="checkbox"
                    class="peer h-4 w-4 cursor-pointer appearance-none rounded-[5px] border border-ink-300 bg-white transition-colors duration-150 ease-[var(--ease-out-soft)] checked:border-brand-600 checked:bg-brand-600 hover:border-ink-400 checked:hover:border-brand-700 checked:hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-ink-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                    :checked="checked"
                    :disabled="disabled"
                    :required="required"
                    :aria-invalid="error ? 'true' : undefined"
                    :aria-describedby="hint ? `${inputId}-hint` : undefined"
                    @change="toggle"
                />
                <component
                    :is="indeterminate && !checked ? Minus : Check"
                    :size="12"
                    :stroke-width="3"
                    :class="[
                        'pointer-events-none absolute text-white',
                        indeterminate && !checked ? 'text-ink-500 opacity-100' : 'opacity-0 peer-checked:opacity-100',
                    ]"
                    aria-hidden="true"
                />
            </span>

            <div v-if="label || hint || $slots.default" class="min-w-0 flex-1">
                <label
                    :for="inputId"
                    :class="[
                        'block text-sm leading-tight font-medium text-ink-800',
                        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                    ]"
                >
                    <slot>{{ label }}</slot>
                    <span v-if="required" class="text-danger-600" aria-hidden="true">*</span>
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
