<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    CalendarClock,
    CircleCheck,
    CircleSlash,
    Eye,
    LayoutGrid,
    MapPin,
    Pencil,
    Plus,
    Power,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormSelect from '@/Components/FormSelect.vue';
import IconButton from '@/Components/IconButton.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import { useDataTable } from '@/Composables/useDataTable';
import { usePermissions } from '@/Composables/usePermissions';
import { useSwal } from '@/Composables/useSwal';

const props = defineProps({
    /** Laravel length-aware paginator of court rows. */
    courts: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    perPageOptions: { type: Array, default: () => [10, 25, 50, 100] },
});

const { can } = usePermissions();
const { confirmDelete, confirmAction } = useSwal();

/*
| The composable hydrates itself from the URL, so the second argument is the
| *default* value of each filter — not the current one. Passing the current
| value would make `hasActiveFilters` permanently false.
*/
const table = useDataTable('admin.courts.index', { status: '' }, {
    only: ['courts', 'stats', 'filters'],
});

const columns = [
    { key: 'name', label: 'Court', sortable: true },
    { key: 'available_slots_count', label: 'Open slots', sortable: true, align: 'right' },
    { key: 'bookings_count', label: 'Bookings', sortable: true, align: 'right' },
    { key: 'is_active', label: 'Status' },
    { key: 'created_at', label: 'Added', sortable: true, align: 'right' },
];

const STATUS_OPTIONS = [
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
];

const shortDate = new Intl.DateTimeFormat('en-PH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const formatDate = (value) => (value ? shortDate.format(new Date(value)) : '—');

/**
 * A brand-new installation deserves an invitation, not an empty grid. Once any
 * court exists — even if the current filter hides them all — the table's own
 * "no matches" state is the right answer.
 */
const isFreshInstall = computed(
    () => (props.stats.total ?? 0) === 0 && !table.hasActiveFilters,
);

async function destroy(court) {
    if (!(await confirmDelete(court.name))) {
        return;
    }

    router.delete(route('admin.courts.destroy', court.slug), {
        preserveScroll: true,
        preserveState: true,
    });
}

async function toggleStatus(court) {
    if (court.is_active) {
        const confirmed = await confirmAction({
            title: `Deactivate "${court.name}"?`,
            text:
                'It disappears from the public booking site straight away. ' +
                'Existing bookings and slots are left exactly as they are.',
            confirmText: 'Deactivate',
            cancelText: 'Keep it active',
            variant: 'warning',
        });

        if (!confirmed) {
            return;
        }
    }

    router.patch(
        route('admin.courts.status', court.slug),
        {},
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <AppLayout title="Courts">
        <PageHeader
            title="Courts"
            subtitle="Every playing surface the club takes bookings for."
            :home-href="route('admin.dashboard')"
            :breadcrumbs="[{ label: 'Courts' }]"
        >
            <template v-if="can('courts.create') && !isFreshInstall" #actions>
                <Button :href="route('admin.courts.create')">
                    <template #icon><Plus :size="16" aria-hidden="true" /></template>
                    New court
                </Button>
            </template>
        </PageHeader>

        <!-- Fresh install: one clear next step, nothing else competing -->
        <Card v-if="isFreshInstall" padding="none">
            <EmptyState
                :icon="LayoutGrid"
                tone="brand"
                size="lg"
                title="No courts yet"
                description="A court is the thing customers actually book. Add your first one, then generate its time slots and you are open for business."
            >
                <template v-if="can('courts.create')" #action>
                    <Button size="lg" :href="route('admin.courts.create')">
                        <template #icon><Plus :size="18" aria-hidden="true" /></template>
                        Add your first court
                    </Button>
                </template>
                <template v-else #action>
                    <p class="text-xs text-ink-500">
                        Ask an administrator for the <code>courts.create</code> permission to add one.
                    </p>
                </template>
            </EmptyState>
        </Card>

        <template v-else>
            <!-- Overview -->
            <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Total courts"
                    :value="stats.total ?? 0"
                    :icon="LayoutGrid"
                    tone="brand"
                    hint="Including inactive"
                />
                <StatCard
                    label="Active"
                    :value="stats.active ?? 0"
                    :icon="CircleCheck"
                    tone="success"
                    hint="Visible on the booking site"
                />
                <StatCard
                    label="Inactive"
                    :value="stats.inactive ?? 0"
                    :icon="CircleSlash"
                    tone="ink"
                    hint="Hidden from customers"
                />
                <StatCard
                    label="Open slots"
                    :value="stats.available_slots ?? 0"
                    :icon="CalendarClock"
                    tone="info"
                    hint="Bookable, still upcoming"
                />
            </div>

            <DataTable
                :columns="columns"
                :rows="courts.data"
                :loading="table.loading"
                :sort="table.sort"
                :direction="table.direction"
                v-model:search="table.search"
                v-model:per-page="table.perPage"
                :per-page-options="perPageOptions"
                :filtered="table.hasActiveFilters"
                search-placeholder="Search by name or code…"
                empty-title="No courts match this view"
                empty-description="Adjust the filters to see more."
                :empty-icon="MapPin"
                @sort="table.sortBy"
            >
                <template #toolbar>
                    <div class="w-full sm:w-44">
                        <FormSelect
                            v-model="table.filters.status"
                            label="Status"
                            sr-only-label
                            size="sm"
                            placeholder="All statuses"
                            :options="STATUS_OPTIONS"
                        />
                    </div>

                    <button
                        v-if="table.hasActiveFilters"
                        type="button"
                        class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg px-2.5 text-xs font-medium text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                        @click="table.reset()"
                    >
                        <RotateCcw :size="14" aria-hidden="true" />
                        Clear
                    </button>
                </template>

                <!-- Court identity: thumbnail + name + code -->
                <template #cell-name="{ row }">
                    <div class="flex min-w-0 items-center gap-3">
                        <img
                            v-if="row.photo_url"
                            :src="row.photo_url"
                            :alt="`Photo of ${row.name}`"
                            loading="lazy"
                            class="h-10 w-14 shrink-0 rounded-lg border border-ink-200 bg-ink-100 object-cover"
                        />
                        <span
                            v-else
                            class="inline-flex h-10 w-14 shrink-0 items-center justify-center rounded-lg border border-ink-200 bg-ink-50 text-ink-400"
                            aria-hidden="true"
                        >
                            <MapPin :size="16" :stroke-width="1.75" />
                        </span>

                        <span class="min-w-0">
                            <span class="block truncate font-medium text-ink-900">
                                {{ row.name }}
                            </span>
                            <span class="block truncate font-mono text-xs text-ink-500">
                                {{ row.code }}
                            </span>
                        </span>
                    </div>
                </template>

                <template #cell-available_slots_count="{ row }">
                    <span
                        :class="[
                            'tabular-nums',
                            row.available_slots_count > 0
                                ? 'font-medium text-success-700'
                                : 'text-ink-400',
                        ]"
                    >
                        {{ row.available_slots_count }}
                    </span>
                    <span class="ml-1 text-xs text-ink-400 tabular-nums">
                        / {{ row.total_slots_count }}
                    </span>
                </template>

                <template #cell-bookings_count="{ row }">
                    <span class="tabular-nums text-ink-700">{{ row.bookings_count }}</span>
                </template>

                <template #cell-is_active="{ row }">
                    <Badge :status="row.is_active" />
                </template>

                <template #cell-created_at="{ row }">
                    <span class="text-xs whitespace-nowrap text-ink-500">
                        {{ formatDate(row.created_at) }}
                    </span>
                </template>

                <template #actions="{ row }">
                    <IconButton
                        variant="view"
                        label="View court"
                        :href="route('admin.courts.show', row.slug)"
                    >
                        <template #default="{ size }"><Eye :size="size" /></template>
                    </IconButton>

                    <IconButton
                        v-if="can('courts.update')"
                        variant="edit"
                        label="Edit court"
                        :href="route('admin.courts.edit', row.slug)"
                    >
                        <template #default="{ size }"><Pencil :size="size" /></template>
                    </IconButton>

                    <IconButton
                        v-if="can('courts.update')"
                        :variant="row.is_active ? 'warn' : 'brand'"
                        :label="row.is_active ? 'Deactivate court' : 'Activate court'"
                        @click="toggleStatus(row)"
                    >
                        <template #default="{ size }"><Power :size="size" /></template>
                    </IconButton>

                    <IconButton
                        v-if="can('courts.delete')"
                        variant="delete"
                        label="Delete court"
                        @click="destroy(row)"
                    >
                        <template #default="{ size }"><Trash2 :size="size" /></template>
                    </IconButton>
                </template>

                <template #emptyAction>
                    <Button
                        v-if="table.hasActiveFilters"
                        variant="secondary"
                        @click="table.reset()"
                    >
                        <template #icon><RotateCcw :size="16" aria-hidden="true" /></template>
                        Clear filters
                    </Button>
                </template>

                <template #footer>
                    <Pagination :paginator="courts" label="courts" :only="['courts', 'stats']" />
                </template>
            </DataTable>
        </template>
    </AppLayout>
</template>
