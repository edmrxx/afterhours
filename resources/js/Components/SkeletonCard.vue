<script setup>
import Skeleton from './Skeleton.vue';

/*
| SkeletonCard — placeholder for a card-shaped panel while its data loads.
*/

defineProps({
    /** Show the header row (avatar + title + subtitle). */
    header: { type: Boolean, default: true },
    /** Show a leading square media block. */
    media: { type: Boolean, default: false },
    /** Body text lines. */
    lines: { type: Number, default: 3 },
    /** Show a footer action row. */
    footer: { type: Boolean, default: false },
});
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-ink-200/70 bg-white shadow-card"
        role="status"
        aria-live="polite"
        aria-busy="true"
    >
        <span class="sr-only">Loading…</span>

        <Skeleton v-if="media" rounded="none" class="h-40 w-full" />

        <div v-if="header" class="flex items-center gap-3 border-b border-ink-200/70 px-5 py-4">
            <Skeleton rounded="lg" class="h-10 w-10 shrink-0" />
            <div class="min-w-0 flex-1 space-y-2">
                <Skeleton class="h-3.5 w-1/3" />
                <Skeleton class="h-3 w-1/2" />
            </div>
        </div>

        <div class="space-y-2.5 p-5">
            <Skeleton :lines="lines" />
        </div>

        <div v-if="footer" class="flex gap-2 border-t border-ink-200/70 px-5 py-3">
            <Skeleton rounded="lg" class="h-8 w-24" />
            <Skeleton rounded="lg" class="h-8 w-20" />
        </div>
    </div>
</template>
