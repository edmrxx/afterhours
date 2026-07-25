<script setup>
import { computed, ref, useId } from 'vue';
import { X } from '@lucide/vue';
import { useFocusTrap } from '@/Composables/useFocusTrap';

/*
| Modal — teleported, focus-trapped dialog.
|
|   <Modal v-model="open" title="Reject booking" size="md">
|       …body…
|       <template #footer>
|           <Button variant="secondary" @click="open = false">Cancel</Button>
|           <Button variant="danger" @click="submit">Reject</Button>
|       </template>
|   </Modal>
*/

const props = defineProps({
    /** v-model — visibility. */
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: null },
    description: { type: String, default: null },
    /** sm | md | lg | xl | 2xl | full */
    size: { type: String, default: 'md' },
    /** Show the header close button and allow Esc. */
    closeable: { type: Boolean, default: true },
    /** Clicking the backdrop closes the dialog. */
    closeOnBackdrop: { type: Boolean, default: true },
    /** Let the body scroll internally rather than growing the dialog. */
    scrollable: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue', 'close', 'opened']);

const SIZES = {
    sm: 'max-w-sm',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
    '2xl': 'max-w-6xl',
    full: 'max-w-[min(96rem,calc(100vw-2rem))]',
};

const panel = ref(null);
const uid = useId();
const titleId = `${uid}-title`;
const descId = `${uid}-desc`;

const isOpen = computed(() => props.modelValue);

function close() {
    if (!props.closeable) {
        return;
    }

    emit('update:modelValue', false);
    emit('close');
}

useFocusTrap(panel, isOpen, { onEscape: close });

function onBackdrop() {
    if (props.closeOnBackdrop) {
        close();
    }
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200 ease-[var(--ease-out-soft)]"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150 ease-[var(--ease-out-soft)]"
            leave-to-class="opacity-0"
        >
            <div
                v-if="modelValue"
                class="fixed inset-0 z-50 overflow-y-auto bg-ink-900/40 backdrop-blur-sm"
                @mousedown.self="onBackdrop"
            >
                <div class="flex min-h-full items-end justify-center p-3 sm:items-center sm:p-6">
                    <Transition
                        appear
                        enter-active-class="transition duration-200 ease-[var(--ease-out-soft)]"
                        enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        leave-active-class="transition duration-150 ease-[var(--ease-out-soft)]"
                        leave-to-class="opacity-0 sm:scale-95"
                        @after-enter="emit('opened')"
                    >
                        <div
                            v-if="modelValue"
                            ref="panel"
                            role="dialog"
                            aria-modal="true"
                            :aria-labelledby="title ? titleId : undefined"
                            :aria-describedby="description ? descId : undefined"
                            tabindex="-1"
                            :class="[
                                'w-full rounded-2xl bg-white shadow-modal outline-none',
                                'flex max-h-[calc(100dvh-2rem)] flex-col',
                                SIZES[size] ?? SIZES.md,
                            ]"
                            @mousedown.stop
                        >
                            <header
                                v-if="title || $slots.header || closeable"
                                class="flex items-start gap-4 border-b border-ink-200/70 px-5 py-4 sm:px-6"
                            >
                                <div class="min-w-0 flex-1">
                                    <slot name="header">
                                        <h2
                                            v-if="title"
                                            :id="titleId"
                                            class="text-base font-semibold text-ink-900"
                                        >
                                            {{ title }}
                                        </h2>
                                        <p
                                            v-if="description"
                                            :id="descId"
                                            class="mt-1 text-sm text-ink-500"
                                        >
                                            {{ description }}
                                        </p>
                                    </slot>
                                </div>

                                <button
                                    v-if="closeable"
                                    type="button"
                                    class="-m-1.5 shrink-0 cursor-pointer rounded-lg p-1.5 text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                                    aria-label="Close dialog"
                                    @click="close"
                                >
                                    <X :size="18" aria-hidden="true" />
                                </button>
                            </header>

                            <div
                                :class="[
                                    'px-5 py-5 sm:px-6',
                                    scrollable ? 'min-h-0 flex-1 overflow-y-auto' : '',
                                ]"
                            >
                                <slot />
                            </div>

                            <footer
                                v-if="$slots.footer"
                                class="flex flex-col-reverse gap-2 border-t border-ink-200/70 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"
                            >
                                <slot name="footer" />
                            </footer>
                        </div>
                    </Transition>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
