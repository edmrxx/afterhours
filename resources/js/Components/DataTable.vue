<script setup>
import { computed, useId } from 'vue';
import { ArrowDown, ArrowUp, ChevronsUpDown, Inbox, Search, SearchX } from '@lucide/vue';
import EmptyState from './EmptyState.vue';
import Skeleton from './Skeleton.vue';

/*
| DataTable — the table standard for every index screen.
|
| It is presentational: pair it with useDataTable(), which owns the server round
| trip. Wiring is always the same three lines:
|
|   const table = useDataTable('admin.courts.index')
|
|   <DataTable
|       :columns="[
|           { key: 'name', label: 'Court', sortable: true },
|           { key: 'base_price', label: 'Price', sortable: true, align: 'right' },
|           { key: 'is_active', label: 'Status' },
|       ]"
|       :rows="courts.data"
|       :loading="table.loading"
|       :sort="table.sort"
|       :direction="table.direction"
|       v-model:search="table.search"
|       v-model:per-page="table.perPage"
|       :filtered="table.hasActiveFilters"
|       @sort="table.sortBy"
|   >
|       <template #cell-is_active="{ row }"><Badge :status="row.is_active" /></template>
|       <template #actions="{ row }">…IconButtons…</template>
|       <template #footer><Pagination :paginator="courts" /></template>
|   </DataTable>
|
| Column shape: { key, label, sortable?, align?, width?, class?, headerClass?,
|                 format?(value, row) }
| `key` may be dotted ("court.name") to reach into an eager-loaded relation.
*/

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    /** Property name or resolver used for :key on each row. */
    rowKey: { type: [String, Function], default: 'id' },
    loading: { type: Boolean, default: false },

    /* Search --------------------------------------------------------- */
    searchable: { type: Boolean, default: true },
    search: { type: String, default: '' },
    searchPlaceholder: { type: String, default: 'Search…' },

    /* Sorting -------------------------------------------------------- */
    sort: { type: String, default: null },
    direction: { type: String, default: 'desc' },

    /* Paging --------------------------------------------------------- */
    perPage: { type: [Number, String], default: 25 },
    perPageOptions: { type: Array, default: () => [10, 25, 50, 100] },
    showPerPage: { type: Boolean, default: true },

    /* Empty / loading ------------------------------------------------ */
    skeletonRows: { type: Number, default: 8 },
    emptyTitle: { type: String, default: 'Nothing to show yet' },
    emptyDescription: { type: String, default: null },
    emptyIcon: { type: [Object, Function], default: () => Inbox },
    /** True when a search/filter is active — swaps in "no matches" copy. */
    filtered: { type: Boolean, default: false },

    /* Selection ------------------------------------------------------ */
    selectable: { type: Boolean, default: false },
    /** v-model:selected — array of row keys. */
    selected: { type: Array, default: () => [] },

    /* Layout --------------------------------------------------------- */
    dense: { type: Boolean, default: false },
    stickyHeader: { type: Boolean, default: false },
    /** Minimum table width before horizontal scrolling kicks in. */
    minWidth: { type: String, default: 'min-w-[52rem]' },
    /** Highlight rows on hover (turn off for read-only tables). */
    hoverable: { type: Boolean, default: true },
});

const emit = defineEmits([
    'update:search',
    'update:perPage',
    'update:selected',
    'sort',
    'row-click',
]);

const uid = useId();
const searchId = `${uid}-search`;
const perPageId = `${uid}-perpage`;

const ALIGN = {
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
};

const visibleColumns = computed(() => props.columns.filter((column) => !column.hidden));

const columnCount = computed(
    () => visibleColumns.value.length + (props.selectable ? 1 : 0) + 1,
);

const cellPadding = computed(() => (props.dense ? 'px-4 py-2' : 'px-4 py-3.5'));

/** Dotted-path getter so "court.name" resolves through an eager-loaded relation. */
function resolve(row, path) {
    if (!path) {
        return null;
    }

    return path
        .split('.')
        .reduce((carry, segment) => (carry == null ? carry : carry[segment]), row);
}

function cellValue(row, column) {
    const raw = resolve(row, column.key);

    return typeof column.format === 'function' ? column.format(raw, row) : raw;
}

function keyFor(row, index) {
    if (typeof props.rowKey === 'function') {
        return props.rowKey(row);
    }

    return resolve(row, props.rowKey) ?? index;
}

/* Sorting ------------------------------------------------------------ */

function ariaSort(column) {
    if (!column.sortable || props.sort !== column.key) {
        return column.sortable ? 'none' : undefined;
    }

    return props.direction === 'asc' ? 'ascending' : 'descending';
}

function sortIcon(column) {
    if (props.sort !== column.key) {
        return ChevronsUpDown;
    }

    return props.direction === 'asc' ? ArrowUp : ArrowDown;
}

/* Selection ---------------------------------------------------------- */

const pageKeys = computed(() => props.rows.map((row, index) => keyFor(row, index)));

const allSelected = computed(
    () =>
        pageKeys.value.length > 0 &&
        pageKeys.value.every((key) => props.selected.includes(key)),
);

const someSelected = computed(
    () =>
        !allSelected.value &&
        pageKeys.value.some((key) => props.selected.includes(key)),
);

function toggleAll() {
    if (allSelected.value) {
        emit(
            'update:selected',
            props.selected.filter((key) => !pageKeys.value.includes(key)),
        );

        return;
    }

    const merged = new Set([...props.selected, ...pageKeys.value]);

    emit('update:selected', [...merged]);
}

function toggleRow(key) {
    if (props.selected.includes(key)) {
        emit(
            'update:selected',
            props.selected.filter((item) => item !== key),
        );

        return;
    }

    emit('update:selected', [...props.selected, key]);
}

const showEmpty = computed(() => !props.loading && props.rows.length === 0);
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-ink-200/70 bg-white shadow-card">
        <!-- Toolbar -->
        <div
            v-if="searchable || showPerPage || $slots.toolbar || $slots.toolbarEnd"
            class="flex flex-col gap-3 border-b border-ink-200/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5"
        >
            <div class="flex flex-1 flex-wrap items-center gap-2.5">
                <div v-if="searchable" class="relative w-full sm:w-72">
                    <Search
                        :size="16"
                        class="pointer-events-none absolute inset-y-0 left-3 my-auto text-ink-400"
                        aria-hidden="true"
                    />
                    <label :for="searchId" class="sr-only">{{ searchPlaceholder }}</label>
                    <input
                        :id="searchId"
                        type="search"
                        :value="search"
                        :placeholder="searchPlaceholder"
                        class="h-9 w-full rounded-lg border border-ink-200 bg-white pr-3 pl-9 text-sm text-ink-900 shadow-card transition-[border-color,box-shadow] duration-150 ease-[var(--ease-out-soft)] placeholder:text-ink-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25 focus:outline-none"
                        @input="emit('update:search', $event.target.value)"
                    />
                </div>

                <slot name="toolbar" />
            </div>

            <div class="flex items-center gap-2.5">
                <slot name="toolbarEnd" />

                <div v-if="showPerPage" class="flex items-center gap-2">
                    <label :for="perPageId" class="hidden text-xs text-ink-500 sm:block">
                        Rows
                    </label>
                    <select
                        :id="perPageId"
                        :value="perPage"
                        aria-label="Rows per page"
                        class="h-9 cursor-pointer rounded-lg border border-ink-200 bg-white pr-7 pl-2.5 text-sm text-ink-700 shadow-card transition-colors hover:border-ink-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25 focus:outline-none"
                        @change="emit('update:perPage', Number($event.target.value))"
                    >
                        <option v-for="option in perPageOptions" :key="option" :value="option">
                            {{ option }}
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="w-full overflow-x-auto">
            <table :class="['w-full border-collapse', minWidth]">
                <thead
                    :class="[
                        'bg-ink-50 text-ink-500',
                        stickyHeader ? 'sticky top-0 z-10' : '',
                    ]"
                >
                    <tr class="border-b border-ink-200/70">
                        <th v-if="selectable" scope="col" class="w-10 px-4 py-3">
                            <input
                                type="checkbox"
                                class="h-4 w-4 cursor-pointer rounded border-ink-300 text-brand-600 accent-brand-600"
                                :checked="allSelected"
                                :indeterminate.prop="someSelected"
                                aria-label="Select all rows on this page"
                                @change="toggleAll"
                            />
                        </th>

                        <th
                            v-for="column in visibleColumns"
                            :key="column.key"
                            scope="col"
                            :aria-sort="ariaSort(column)"
                            :style="column.width ? { width: column.width } : undefined"
                            :class="[
                                'px-4 py-3 text-xs font-semibold tracking-wide whitespace-nowrap uppercase',
                                ALIGN[column.align] ?? ALIGN.left,
                                column.headerClass ?? '',
                            ]"
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                :class="[
                                    'group inline-flex cursor-pointer items-center gap-1.5 rounded transition-colors hover:text-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                                    column.align === 'right' ? 'flex-row-reverse' : '',
                                    sort === column.key ? 'text-brand-700' : '',
                                ]"
                                @click="emit('sort', column.key)"
                            >
                                <slot :name="`header-${column.key}`" :column="column">
                                    {{ column.label }}
                                </slot>
                                <component
                                    :is="sortIcon(column)"
                                    :size="13"
                                    :class="[
                                        'shrink-0 transition-opacity',
                                        sort === column.key
                                            ? 'opacity-100'
                                            : 'opacity-40 group-hover:opacity-70',
                                    ]"
                                    aria-hidden="true"
                                />
                            </button>

                            <slot v-else :name="`header-${column.key}`" :column="column">
                                {{ column.label }}
                            </slot>
                        </th>

                        <th
                            v-if="$slots.actions"
                            scope="col"
                            class="w-px px-4 py-3 text-right text-xs font-semibold tracking-wide uppercase"
                        >
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-ink-200/70 text-sm text-ink-700">
                    <!-- Skeleton rows -->
                    <template v-if="loading">
                        <tr v-for="n in skeletonRows" :key="`skeleton-${n}`" aria-hidden="true">
                            <td v-if="selectable" :class="cellPadding">
                                <Skeleton rounded="sm" class="h-4 w-4" />
                            </td>
                            <td
                                v-for="(column, i) in visibleColumns"
                                :key="`skeleton-${n}-${column.key}`"
                                :class="cellPadding"
                            >
                                <Skeleton
                                    class="h-3.5"
                                    :class="i === 0 ? 'w-40' : i % 3 === 0 ? 'w-16' : 'w-24'"
                                />
                            </td>
                            <td v-if="$slots.actions" :class="cellPadding">
                                <div class="flex justify-end gap-1.5">
                                    <Skeleton rounded="lg" class="h-8 w-8" />
                                    <Skeleton rounded="lg" class="h-8 w-8" />
                                </div>
                            </td>
                        </tr>
                    </template>

                    <!-- Empty state -->
                    <tr v-else-if="showEmpty">
                        <td :colspan="columnCount" class="p-0">
                            <slot name="empty">
                                <EmptyState
                                    :icon="filtered ? SearchX : emptyIcon"
                                    :title="filtered ? 'No matching results' : emptyTitle"
                                    :description="
                                        filtered
                                            ? 'Try a different search term or clear the filters to see everything.'
                                            : emptyDescription
                                    "
                                >
                                    <template v-if="$slots.emptyAction" #action>
                                        <slot name="emptyAction" />
                                    </template>
                                </EmptyState>
                            </slot>
                        </td>
                    </tr>

                    <!-- Data rows -->
                    <template v-else>
                    <tr
                        v-for="(row, index) in rows"
                        :key="keyFor(row, index)"
                        :class="[
                            'transition-colors duration-150 ease-[var(--ease-out-soft)]',
                            hoverable ? 'hover:bg-ink-50' : '',
                            selectable && selected.includes(keyFor(row, index))
                                ? 'bg-brand-50/50'
                                : '',
                        ]"
                        @click="emit('row-click', row)"
                    >
                        <td v-if="selectable" :class="cellPadding" @click.stop>
                            <input
                                type="checkbox"
                                class="h-4 w-4 cursor-pointer rounded border-ink-300 accent-brand-600"
                                :checked="selected.includes(keyFor(row, index))"
                                :aria-label="`Select row ${index + 1}`"
                                @change="toggleRow(keyFor(row, index))"
                            />
                        </td>

                        <td
                            v-for="column in visibleColumns"
                            :key="column.key"
                            :class="[
                                cellPadding,
                                ALIGN[column.align] ?? ALIGN.left,
                                column.class ?? '',
                            ]"
                        >
                            <slot
                                :name="`cell-${column.key}`"
                                :row="row"
                                :value="cellValue(row, column)"
                                :index="index"
                            >
                                <span
                                    v-if="cellValue(row, column) === null || cellValue(row, column) === ''"
                                    class="text-ink-400"
                                >
                                    —
                                </span>
                                <template v-else>{{ cellValue(row, column) }}</template>
                            </slot>
                        </td>

                        <td
                            v-if="$slots.actions"
                            :class="[cellPadding, 'text-right whitespace-nowrap']"
                            @click.stop
                        >
                            <div class="flex items-center justify-end gap-0.5">
                                <slot name="actions" :row="row" :index="index" />
                            </div>
                        </td>
                    </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Footer / pagination -->
        <div
            v-if="$slots.footer && !showEmpty"
            class="border-t border-ink-200/70 px-4 py-3 sm:px-5"
        >
            <slot name="footer" />
        </div>
    </div>
</template>
