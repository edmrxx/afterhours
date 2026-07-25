<script setup>
import { computed, ref } from 'vue';
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from '@lucide/vue';

/*
| Alert — inline, persistent messaging. Transient feedback belongs in a toast
| (useSwal); this is for state a user must keep seeing, e.g. "this booking hold
| expires in 12 minutes" or a rejection reason.
*/

const props = defineProps({
    /** success | error | warning | info | brand */
    variant: { type: String, default: 'info' },
    title: { type: String, default: null },
    /** Body copy; the default slot wins when both are supplied. */
    message: { type: String, default: null },
    dismissible: { type: Boolean, default: false },
    /** Hide the leading icon. */
    icon: { type: Boolean, default: true },
});

const emit = defineEmits(['dismiss']);

const visible = ref(true);

const STYLES = {
    success: {
        wrapper: 'bg-success-50 ring-success-500/20',
        icon: 'text-success-600',
        title: 'text-success-700',
        body: 'text-success-700/90',
        component: CircleCheck,
        role: 'status',
    },
    error: {
        wrapper: 'bg-danger-50 ring-danger-500/20',
        icon: 'text-danger-600',
        title: 'text-danger-700',
        body: 'text-danger-700/90',
        component: CircleAlert,
        role: 'alert',
    },
    warning: {
        wrapper: 'bg-warn-50 ring-warn-500/25',
        icon: 'text-warn-600',
        title: 'text-warn-700',
        body: 'text-warn-700/90',
        component: TriangleAlert,
        role: 'alert',
    },
    info: {
        wrapper: 'bg-info-50 ring-info-500/20',
        icon: 'text-info-600',
        title: 'text-info-600',
        body: 'text-ink-600',
        component: Info,
        role: 'status',
    },
    brand: {
        wrapper: 'bg-brand-50 ring-brand-200',
        icon: 'text-brand-600',
        title: 'text-brand-700',
        body: 'text-ink-600',
        component: Info,
        role: 'status',
    },
};

const style = computed(() => STYLES[props.variant] ?? STYLES.info);

function dismiss() {
    visible.value = false;
    emit('dismiss');
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-[var(--ease-out-soft)]"
        enter-from-class="opacity-0 -translate-y-1"
        leave-active-class="transition duration-150 ease-[var(--ease-out-soft)]"
        leave-to-class="opacity-0"
    >
        <div
            v-if="visible"
            :role="style.role"
            :class="['flex gap-3 rounded-xl p-4 ring-1 ring-inset', style.wrapper]"
        >
            <component
                :is="style.component"
                v-if="icon"
                :size="18"
                :class="['mt-0.5 shrink-0', style.icon]"
                aria-hidden="true"
            />

            <div class="min-w-0 flex-1">
                <p v-if="title" :class="['text-sm font-semibold', style.title]">
                    {{ title }}
                </p>
                <div
                    v-if="$slots.default || message"
                    :class="['text-sm', style.body, title ? 'mt-1' : '']"
                >
                    <slot>{{ message }}</slot>
                </div>
                <div v-if="$slots.actions" class="mt-3 flex flex-wrap gap-2">
                    <slot name="actions" />
                </div>
            </div>

            <button
                v-if="dismissible"
                type="button"
                class="-m-1 h-7 w-7 shrink-0 cursor-pointer rounded-lg p-1 text-ink-500 transition-colors hover:bg-black/5 hover:text-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                aria-label="Dismiss message"
                @click="dismiss"
            >
                <X :size="16" aria-hidden="true" />
            </button>
        </div>
    </Transition>
</template>
