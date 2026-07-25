<script setup>
import Skeleton from './Skeleton.vue';

/*
| SkeletonTable — standalone table placeholder.
|
| DataTable renders skeleton rows internally, so reach for this only when a
| table lives outside DataTable (dashboards, drawers, print previews).
*/

defineProps({
    rows: { type: Number, default: 6 },
    columns: { type: Number, default: 5 },
    header: { type: Boolean, default: true },
    /** Wrap in a bordered card surface. */
    framed: { type: Boolean, default: true },
});

const WIDTHS = ['w-2/3', 'w-1/2', 'w-3/4', 'w-1/3', 'w-5/6', 'w-2/5'];
</script>

<template>
    <div
        :class="framed ? 'overflow-hidden rounded-xl border border-ink-200/70 bg-white shadow-card' : ''"
        role="status"
        aria-live="polite"
        aria-busy="true"
    >
        <span class="sr-only">Loading table data…</span>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] table-fixed">
                <thead v-if="header" class="bg-ink-50">
                    <tr>
                        <th
                            v-for="c in columns"
                            :key="`h-${c}`"
                            class="px-4 py-3 text-left first:pl-5 last:pr-5"
                        >
                            <Skeleton class="h-3 w-20" />
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-200/70">
                    <tr v-for="r in rows" :key="`r-${r}`">
                        <td
                            v-for="c in columns"
                            :key="`r-${r}-c-${c}`"
                            class="px-4 py-4 first:pl-5 last:pr-5"
                        >
                            <Skeleton
                                class="h-3.5"
                                :class="WIDTHS[(r + c) % WIDTHS.length]"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
