<script setup>
import { computed, ref, useId } from 'vue';
import { X } from '@lucide/vue';
import { useFocusTrap } from '@/Composables/useFocusTrap';

/*
| Drawer — slide-over panel for filters and detail views.
|
|   <Drawer v-model="showFilters" title="Filter bookings" size="sm">
|       …form…
|       <template #footer>
|           <Button variant="secondary" @click="table.reset()">Reset</Button>
|           <Button @click="showFilters = false">Apply</Button>
|       </template>
|   </Drawer>
*/

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: null },
    description: { type: String, default: null },
    /** sm | md | lg | xl */
    size: { type: String, default: 'md' },
    /** right | left */
    position: { type: String, default: 'right' },
    closeable: { type: Boolean, default: true },
    closeOnBackdrop: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue', 'close']);

const SIZES = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-xl',
    xl: 'sm:max-w-3xl',
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

const isLeft = computed(() => props.position === 'left');

const enterFrom = computed(() => (isLeft.value ? '-translate-x-full' : 'translate-x-full'));
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200 ease-[var(--ease-out-soft)]"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-200 ease-[var(--ease-out-soft)]"
            leave-to-class="opacity-0"
        >
            <div
                v-if="modelValue"
                class="fixed inset-0 z-50 bg-ink-900/40 backdrop-blur-sm"
                @mousedown.self="closeOnBackdrop && close()"
            >
                <div
                    :class="[
                        'pointer-events-none fixed inset-y-0 flex max-w-full',
                        isLeft ? 'left-0' : 'right-0',
                    ]"
                >
                    <Transition
                        appear
                        enter-active-class="transform transition duration-300 ease-[var(--ease-out-soft)]"
                        :enter-from-class="enterFrom"
                        leave-active-class="transform transition duration-200 ease-[var(--ease-out-soft)]"
                        :leave-to-class="enterFrom"
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
                                'pointer-events-auto flex h-full w-screen flex-col bg-white shadow-modal outline-none',
                                SIZES[size] ?? SIZES.md,
                            ]"
                        >
                            <header
                                class="flex items-start gap-4 border-b border-ink-200/70 px-5 py-4"
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
                                    aria-label="Close panel"
                                    @click="close"
                                >
                                    <X :size="18" aria-hidden="true" />
                                </button>
                            </header>

                            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                                <slot />
                            </div>

                            <footer
                                v-if="$slots.footer"
                                class="flex flex-col-reverse gap-2 border-t border-ink-200/70 px-5 py-4 sm:flex-row sm:justify-end"
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
