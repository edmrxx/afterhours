<script setup>
/*
| FormField — the label / hint / error chrome shared by every form control.
|
| Use it directly only when wrapping a control the kit does not provide
| (a colour picker, a third-party widget). Otherwise prefer FormInput and
| friends, which use this internally.
*/

defineProps({
    label: { type: String, default: null },
    /** id of the control this label points at. */
    inputId: { type: String, default: null },
    /** Helper copy under the control. Hidden while an error is shown. */
    hint: { type: String, default: null },
    /** Inertia validation message, e.g. form.errors.name. */
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    /** Right-aligned label accessory, e.g. a character counter. */
    labelHint: { type: String, default: null },
    /** Render the label visually hidden but still available to screen readers. */
    srOnlyLabel: { type: Boolean, default: false },
});
</script>

<template>
    <div class="w-full">
        <div
            v-if="label || $slots.labelAction"
            :class="['mb-1.5 flex items-baseline justify-between gap-3', srOnlyLabel ? 'sr-only' : '']"
        >
            <label
                v-if="label"
                :for="inputId ?? undefined"
                class="text-sm font-medium text-ink-800"
            >
                {{ label }}
                <span v-if="required" class="text-danger-600" aria-hidden="true">*</span>
                <span v-if="required" class="sr-only">(required)</span>
            </label>

            <span v-if="labelHint" class="text-xs text-ink-400">{{ labelHint }}</span>
            <slot name="labelAction" />
        </div>

        <slot />

        <p
            v-if="error"
            :id="inputId ? `${inputId}-error` : undefined"
            class="mt-1.5 text-xs font-medium text-danger-600"
            role="alert"
        >
            {{ error }}
        </p>
        <p
            v-else-if="hint || $slots.hint"
            :id="inputId ? `${inputId}-hint` : undefined"
            class="mt-1.5 text-xs leading-relaxed text-ink-500"
        >
            <slot name="hint">{{ hint }}</slot>
        </p>
    </div>
</template>
