<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronRight, House } from '@lucide/vue';

/*
| Breadcrumbs
|
|   <Breadcrumbs :items="[
|       { label: 'Courts', href: route('admin.courts.index') },
|       { label: court.name },
|   ]" />
|
| The trailing item is always plain text and marked aria-current="page".
*/

defineProps({
    /** [{ label, href? }] */
    items: { type: Array, default: () => [] },
    /** Prepend a home crumb linking to `homeHref`. */
    home: { type: Boolean, default: true },
    homeHref: { type: String, default: null },
    homeLabel: { type: String, default: 'Dashboard' },
});
</script>

<template>
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-ink-500">
            <li v-if="home && homeHref" class="flex items-center">
                <Link
                    :href="homeHref"
                    class="inline-flex items-center gap-1 rounded px-1 py-0.5 transition-colors hover:text-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                    :aria-label="homeLabel"
                >
                    <House :size="13" aria-hidden="true" />
                    <span class="sr-only sm:not-sr-only">{{ homeLabel }}</span>
                </Link>
            </li>

            <li
                v-for="(item, index) in items"
                :key="`${item.label}-${index}`"
                class="flex min-w-0 items-center"
            >
                <ChevronRight
                    v-if="index > 0 || (home && homeHref)"
                    :size="13"
                    class="mx-0.5 shrink-0 text-ink-300"
                    aria-hidden="true"
                />

                <Link
                    v-if="item.href && index < items.length - 1"
                    :href="item.href"
                    class="truncate rounded px-1 py-0.5 transition-colors hover:text-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                >
                    {{ item.label }}
                </Link>
                <span
                    v-else
                    class="truncate px-1 py-0.5 font-medium text-ink-700"
                    aria-current="page"
                >
                    {{ item.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
