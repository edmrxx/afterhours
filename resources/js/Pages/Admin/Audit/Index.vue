<script setup>
import { computed } from 'vue';
import { route } from 'ziggy-js';
import { Eye, Filter, Monitor, RotateCcw, ScrollText } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import DataTable from '@/Components/DataTable.vue';
import FormDatePicker from '@/Components/FormDatePicker.vue';
import FormSelect from '@/Components/FormSelect.vue';
import IconButton from '@/Components/IconButton.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { useDataTable } from '@/Composables/useDataTable';

/*
| Audit trail index.
|
| The log is the busiest table in the system, so every control here maps onto an
| indexed column: user, module + action, and the created_at range. Search is the
| one unindexed path and is therefore the last resort rather than the default.
*/

const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({ users: [], modules: [], actions: [] }) },
});

const table = useDataTable(
    'admin.audit.index',
    {
        user_id: '',
        module: '',
        action: '',
        date_from: '',
        date_to: '',
    },
    {
        sort: 'created_at',
        direction: 'desc',
        only: ['entries', 'filters'],
    },
);

/* Action colour semantics — fixed by the spec, not by the generic status map. */
const ACTION_TONES = {
    create: 'success',
    update: 'info',
    delete: 'danger',
    login: 'ink',
    logout: 'ink',
    activate: 'success',
    deactivate: 'warn',
    view: 'ink',
};

const actionLabels = computed(() =>
    Object.fromEntries((props.options.actions ?? []).map((item) => [item.value, item.label])),
);

const actionLabel = (action) =>
    actionLabels.value[action] ??
    String(action ?? '')
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');

const columns = [
    { key: 'user_name', label: 'User', sortable: true },
    { key: 'role_name', label: 'Role', sortable: true },
    { key: 'module', label: 'Module', sortable: true },
    { key: 'action', label: 'Action', sortable: true },
    { key: 'description', label: 'Description' },
    { key: 'ip_address', label: 'IP', sortable: true },
    { key: 'browser', label: 'Browser' },
    { key: 'created_at', label: 'Timestamp', sortable: true, align: 'right' },
];
</script>

<template>
    <AppLayout title="Audit Trail">
        <PageHeader
            title="Audit trail"
            subtitle="Every create, update, delete and sign-in recorded across the system."
            :breadcrumbs="[{ label: 'Audit trail' }]"
        />

        <!-- Filter bar -->
        <section
            class="mb-5 rounded-xl border border-ink-200/70 bg-white p-4 shadow-card sm:p-5"
            aria-label="Filter the audit trail"
        >
            <div class="mb-3 flex items-center gap-2 text-xs font-semibold tracking-wide text-ink-500 uppercase">
                <Filter :size="14" aria-hidden="true" />
                Filters
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <FormSelect
                    v-model="table.filters.user_id"
                    label="User"
                    size="sm"
                    placeholder="All users"
                    :options="options.users"
                />
                <FormSelect
                    v-model="table.filters.module"
                    label="Module"
                    size="sm"
                    placeholder="All modules"
                    :options="options.modules"
                />
                <FormSelect
                    v-model="table.filters.action"
                    label="Action"
                    size="sm"
                    placeholder="All actions"
                    :options="options.actions"
                />
                <FormDatePicker v-model="table.filters.date_from" label="From" size="sm" />
                <FormDatePicker
                    v-model="table.filters.date_to"
                    label="To"
                    size="sm"
                    :min="table.filters.date_from || undefined"
                />
            </div>

            <div v-if="table.hasActiveFilters" class="mt-4 flex justify-end">
                <Button variant="secondary" size="sm" @click="table.reset()">
                    <template #icon><RotateCcw :size="14" /></template>
                    Clear filters
                </Button>
            </div>
        </section>

        <DataTable
            :columns="columns"
            :rows="entries.data"
            :loading="table.loading"
            :sort="table.sort"
            :direction="table.direction"
            v-model:search="table.search"
            v-model:per-page="table.perPage"
            :filtered="table.hasActiveFilters"
            search-placeholder="Search description, user or IP…"
            :empty-icon="ScrollText"
            empty-title="No activity recorded yet"
            empty-description="Actions taken in the admin area will appear here as they happen."
            min-width="min-w-[76rem]"
            dense
            @sort="table.sortBy"
        >
            <template #cell-user_name="{ row }">
                <div class="min-w-0">
                    <p class="truncate font-medium text-ink-900">{{ row.user_name }}</p>
                    <p v-if="!row.user_exists && row.user_id" class="text-[11px] text-ink-400">
                        account removed
                    </p>
                </div>
            </template>

            <template #cell-role_name="{ row }">
                <span class="text-ink-600 capitalize">{{ row.role_name }}</span>
            </template>

            <template #cell-module="{ row }">
                <span class="font-medium text-ink-700">{{ row.module }}</span>
            </template>

            <template #cell-action="{ row }">
                <Badge
                    size="xs"
                    :tone="ACTION_TONES[row.action] ?? 'ink'"
                    :label="actionLabel(row.action)"
                />
            </template>

            <template #cell-description="{ row }">
                <p class="line-clamp-2 max-w-md text-ink-600">
                    {{ row.description || '—' }}
                </p>
            </template>

            <template #cell-ip_address="{ row }">
                <span class="font-mono text-xs text-ink-500 tabular-nums">
                    {{ row.ip_address || '—' }}
                </span>
            </template>

            <template #cell-browser="{ row }">
                <span class="inline-flex items-center gap-1.5 text-ink-600">
                    <Monitor :size="13" class="shrink-0 text-ink-400" aria-hidden="true" />
                    <span class="min-w-0">
                        <span class="block truncate">{{ row.browser || 'Unknown' }}</span>
                        <span class="block truncate text-[11px] text-ink-400">
                            {{ row.platform || '—' }}
                        </span>
                    </span>
                </span>
            </template>

            <template #cell-created_at="{ row }">
                <span class="block whitespace-nowrap text-ink-700 tabular-nums">
                    {{ row.created_at_label }}
                </span>
                <span class="block text-[11px] text-ink-400">{{ row.created_at_human }}</span>
            </template>

            <template #actions="{ row }">
                <IconButton
                    variant="view"
                    label="View entry"
                    :href="route('admin.audit.show', row.id)"
                >
                    <template #default="{ size }"><Eye :size="size" /></template>
                </IconButton>
            </template>

            <template #footer>
                <Pagination :paginator="entries" :only="['entries', 'filters']" label="entries" />
            </template>
        </DataTable>
    </AppLayout>
</template>
