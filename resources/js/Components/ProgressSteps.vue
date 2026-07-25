<script setup>
import { computed } from 'vue';
import { Check } from '@lucide/vue';

/*
| ProgressSteps — the public booking wizard indicator.
|
|   <ProgressSteps :current="2" :steps="[
|       { label: 'Pick a slot' },
|       { label: 'Your details' },
|       { label: 'Send payment' },
|       { label: 'Confirmation' },
|   ]" />
|
| `current` is 1-based. Steps before it render complete, the current one is
| highlighted, later ones are muted.
*/

const props = defineProps({
    /** [{ label, description? }] */
    steps: { type: Array, required: true },
    /** 1-based index of the step in progress. */
    current: { type: Number, default: 1 },
    /** horizontal | vertical */
    orientation: { type: String, default: 'horizontal' },
    /** Hide labels on small screens to save room. */
    compact: { type: Boolean, default: false },
});

const stateOf = (index) => {
    if (index + 1 < props.current) {
        return 'complete';
    }

    return index + 1 === props.current ? 'current' : 'upcoming';
};

const isVertical = computed(() => props.orientation === 'vertical');

const BUBBLE = {
    complete: 'bg-brand-600 text-white ring-brand-600',
    current: 'bg-white text-brand-700 ring-brand-600',
    upcoming: 'bg-white text-ink-400 ring-ink-300',
};

const LABEL = {
    complete: 'text-ink-700',
    current: 'text-brand-700 font-semibold',
    upcoming: 'text-ink-400',
};
</script>

<template>
    <nav :aria-label="`Step ${current} of ${steps.length}`">
        <ol
            :class="[
                'flex',
                isVertical ? 'flex-col gap-0' : 'items-start',
            ]"
        >
            <li
                v-for="(step, index) in steps"
                :key="step.label"
                :class="[
                    'relative flex',
                    isVertical ? 'gap-3 pb-6 last:pb-0' : 'flex-1 flex-col items-center last:flex-none',
                ]"
            >
                <!-- Connector -->
                <span
                    v-if="index < steps.length - 1"
                    :class="[
                        'absolute rounded-full transition-colors duration-300 ease-[var(--ease-out-soft)]',
                        isVertical
                            ? 'top-9 bottom-1 left-[15px] w-0.5'
                            : 'top-[15px] left-[calc(50%+22px)] right-[calc(-50%+22px)] h-0.5',
                        stateOf(index) === 'complete' ? 'bg-brand-600' : 'bg-ink-200',
                    ]"
                    aria-hidden="true"
                />

                <span
                    :class="[
                        'relative z-10 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold ring-2',
                        'transition-colors duration-300 ease-[var(--ease-out-soft)]',
                        BUBBLE[stateOf(index)],
                    ]"
                    :aria-current="stateOf(index) === 'current' ? 'step' : undefined"
                >
                    <Check v-if="stateOf(index) === 'complete'" :size="16" aria-hidden="true" />
                    <template v-else>{{ index + 1 }}</template>
                </span>

                <div
                    :class="[
                        'min-w-0',
                        isVertical ? 'pt-1' : 'mt-2 px-1 text-center',
                        compact && !isVertical ? 'hidden sm:block' : '',
                    ]"
                >
                    <p :class="['text-xs leading-tight sm:text-sm', LABEL[stateOf(index)]]">
                        {{ step.label }}
                    </p>
                    <p
                        v-if="step.description"
                        class="mt-0.5 hidden text-xs text-ink-400 sm:block"
                    >
                        {{ step.description }}
                    </p>
                </div>
            </li>
        </ol>
    </nav>
</template>
