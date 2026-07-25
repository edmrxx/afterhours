<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';

/*
| Pagination — renders a Laravel paginator.
|
| Pass the whole paginator (preferred) or just its links array:
|
|   <Pagination :paginator="courts" />
|   <Pagination :links="courts.links" :from="courts.from" :to="courts.to" :total="courts.total" />
|
| Visits preserve scroll and state so the table does not jump, and reuse the
| same `only` keys the index controller partial-reloads with.
*/

const props = defineProps({
    /** A Laravel length-aware paginator object. */
    paginator: { type: Object, default: null },
    /** [{ url, label, active }] — used when `paginator` is not supplied. */
    links: { type: Array, default: null },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    total: { type: Number, default: null },
    /** Partial-reload keys, e.g. ['courts']. */
    only: { type: Array, default: () => [] },
    /** Noun used in the summary line. */
    label: { type: String, default: 'results' },
});

const allLinks = computed(() => props.paginator?.links ?? props.links ?? []);

const from = computed(() => props.from ?? props.paginator?.from ?? null);
const to = computed(() => props.to ?? props.paginator?.to ?? null);
const total = computed(() => props.total ?? props.paginator?.total ?? null);

const previous = computed(() => allLinks.value[0] ?? null);
const next = computed(() => allLinks.value[allLinks.value.length - 1] ?? null);

/** The numbered/ellipsis links between Previous and Next. */
const pages = computed(() => allLinks.value.slice(1, -1));

const visitOptions = computed(() => ({
    preserveScroll: true,
    preserveState: true,
    ...(props.only.length ? { only: props.only } : {}),
}));

const hasMultiplePages = computed(() => pages.value.length > 1);

const numberFormat = new Intl.NumberFormat();

const format = (value) => (value === null ? '0' : numberFormat.format(value));
</script>

<template>
    <nav
        v-if="allLinks.length > 0"
        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        aria-label="Pagination"
    >
        <p v-if="total !== null" class="order-2 text-xs text-ink-500 sm:order-1">
            Showing
            <span class="font-medium text-ink-700">{{ format(from) }}</span>
            to
            <span class="font-medium text-ink-700">{{ format(to) }}</span>
            of
            <span class="font-medium text-ink-700">{{ format(total) }}</span>
            {{ label }}
        </p>
        <span v-else class="order-2 sm:order-1" />

        <div v-if="hasMultiplePages" class="order-1 flex items-center gap-1 sm:order-2">
            <!-- Previous -->
            <component
                :is="previous?.url ? Link : 'span'"
                :href="previous?.url ?? undefined"
                v-bind="previous?.url ? visitOptions : {}"
                :aria-disabled="previous?.url ? undefined : 'true'"
                aria-label="Previous page"
                :class="[
                    'inline-flex h-9 items-center gap-1 rounded-lg border px-2.5 text-sm font-medium transition-colors duration-150 ease-[var(--ease-out-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                    previous?.url
                        ? 'cursor-pointer border-ink-200 bg-white text-ink-700 hover:bg-ink-50 hover:text-ink-900'
                        : 'cursor-not-allowed border-ink-200/70 bg-ink-50 text-ink-300',
                ]"
            >
                <ChevronLeft :size="16" aria-hidden="true" />
                <span class="hidden sm:inline">Previous</span>
            </component>

            <!-- Numbers (desktop) -->
            <div class="hidden items-center gap-1 sm:flex">
                <component
                    :is="link.url && !link.active ? Link : 'span'"
                    v-for="(link, index) in pages"
                    :key="`${link.label}-${index}`"
                    :href="link.url ?? undefined"
                    v-bind="link.url && !link.active ? visitOptions : {}"
                    :aria-current="link.active ? 'page' : undefined"
                    :aria-label="link.url ? `Go to page ${link.label}` : undefined"
                    :class="[
                        'inline-flex h-9 min-w-9 items-center justify-center rounded-lg border px-2 text-sm font-medium tabular-nums transition-colors duration-150 ease-[var(--ease-out-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                        link.active
                            ? 'border-brand-600 bg-brand-600 text-white shadow-card'
                            : link.url
                              ? 'cursor-pointer border-ink-200 bg-white text-ink-700 hover:bg-ink-50 hover:text-ink-900'
                              : 'border-transparent bg-transparent text-ink-400',
                    ]"
                    v-text="link.label"
                />
            </div>

            <!-- Condensed indicator (mobile) -->
            <span
                v-if="paginator?.current_page"
                class="inline-flex h-9 items-center rounded-lg border border-ink-200 bg-white px-3 text-xs font-medium text-ink-600 tabular-nums sm:hidden"
            >
                {{ paginator.current_page }} / {{ paginator.last_page }}
            </span>

            <!-- Next -->
            <component
                :is="next?.url ? Link : 'span'"
                :href="next?.url ?? undefined"
                v-bind="next?.url ? visitOptions : {}"
                :aria-disabled="next?.url ? undefined : 'true'"
                aria-label="Next page"
                :class="[
                    'inline-flex h-9 items-center gap-1 rounded-lg border px-2.5 text-sm font-medium transition-colors duration-150 ease-[var(--ease-out-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                    next?.url
                        ? 'cursor-pointer border-ink-200 bg-white text-ink-700 hover:bg-ink-50 hover:text-ink-900'
                        : 'cursor-not-allowed border-ink-200/70 bg-ink-50 text-ink-300',
                ]"
            >
                <span class="hidden sm:inline">Next</span>
                <ChevronRight :size="16" aria-hidden="true" />
            </component>
        </div>
    </nav>
</template>
