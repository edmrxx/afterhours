<script setup>
import { computed } from 'vue';
import { CircleAlert, CircleCheck, Info, TriangleAlert } from '@lucide/vue';
import Modal from './Modal.vue';
import Button from './Button.vue';

/*
| ConfirmDialog — an in-page confirmation for flows that need a form control
| (a rejection reason, a date, a checkbox) inside the dialog. For a plain
| yes/no, prefer useSwal().confirmAction / confirmDelete.
|
|   <ConfirmDialog v-model="rejecting" variant="danger" title="Reject booking"
|                  description="The slot is released immediately."
|                  confirm-text="Reject booking" :loading="form.processing"
|                  @confirm="submit">
|       <FormTextarea v-model="form.reason" label="Reason" :error="form.errors.reason" />
|   </ConfirmDialog>
*/

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: 'Are you sure?' },
    description: { type: String, default: null },
    confirmText: { type: String, default: 'Confirm' },
    cancelText: { type: String, default: 'Cancel' },
    /** primary | danger | success | warning | info */
    variant: { type: String, default: 'primary' },
    loading: { type: Boolean, default: false },
    /** Disable the confirm button, e.g. while a required field is empty. */
    confirmDisabled: { type: Boolean, default: false },
    /** sm | md | lg */
    size: { type: String, default: 'sm' },
});

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel']);

const ICONS = {
    primary: { component: Info, chip: 'bg-brand-50 text-brand-600' },
    info: { component: Info, chip: 'bg-info-50 text-info-600' },
    danger: { component: TriangleAlert, chip: 'bg-danger-50 text-danger-600' },
    warning: { component: CircleAlert, chip: 'bg-warn-50 text-warn-600' },
    success: { component: CircleCheck, chip: 'bg-success-50 text-success-600' },
};

const BUTTON_VARIANTS = {
    primary: 'primary',
    info: 'primary',
    danger: 'danger',
    warning: 'primary',
    success: 'success',
};

const icon = computed(() => ICONS[props.variant] ?? ICONS.primary);

const confirmVariant = computed(() => BUTTON_VARIANTS[props.variant] ?? 'primary');

function cancel() {
    emit('update:modelValue', false);
    emit('cancel');
}
</script>

<template>
    <Modal
        :model-value="modelValue"
        :size="size"
        :closeable="!loading"
        :close-on-backdrop="!loading"
        @update:model-value="emit('update:modelValue', $event)"
        @close="emit('cancel')"
    >
        <template #header>
            <div class="flex items-start gap-3.5">
                <span
                    :class="[
                        'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full',
                        icon.chip,
                    ]"
                    aria-hidden="true"
                >
                    <component :is="icon.component" :size="20" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-ink-900">{{ title }}</h2>
                    <p v-if="description" class="mt-1 text-sm leading-relaxed text-ink-500">
                        {{ description }}
                    </p>
                </div>
            </div>
        </template>

        <div v-if="$slots.default" class="space-y-4">
            <slot />
        </div>
        <p v-else class="sr-only">{{ description ?? title }}</p>

        <template #footer>
            <Button variant="secondary" :disabled="loading" @click="cancel">
                {{ cancelText }}
            </Button>
            <Button
                :variant="confirmVariant"
                :loading="loading"
                :disabled="confirmDisabled"
                @click="emit('confirm')"
            >
                {{ confirmText }}
            </Button>
        </template>
    </Modal>
</template>
