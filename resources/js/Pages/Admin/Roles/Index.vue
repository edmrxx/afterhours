<script setup>
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { Lock, Pencil, Plus, ShieldCheck, ShieldPlus, Trash2, Users as UsersIcon } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Pagination from '@/Components/Pagination.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import IconButton from '@/Components/IconButton.vue';
import { useDataTable } from '@/Composables/useDataTable';
import { usePermissions } from '@/Composables/usePermissions';
import { useConfirm } from '@/Composables/useConfirm';

/*
| Roles index.
|
| Every row is a permission bundle the operator owns. Nothing about the list is
| special-cased except the protected administrator role, which the server also
| refuses to delete — the badge here is a courtesy, not the guard.
*/

defineProps({
    roles: { type: Object, required: true },
    /** Total permissions in the catalogue — the denominator on each row. */
    permissionTotal: { type: Number, default: 0 },
    filters: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const { confirmThenDelete } = useConfirm();

const table = useDataTable(
    'admin.roles.index',
    {},
    { only: ['roles', 'filters'], sort: 'name', direction: 'asc' },
);

const columns = [
    { key: 'name', label: 'Role', sortable: true },
    { key: 'permissions_count', label: 'Permissions', width: '16rem' },
    { key: 'users_count', label: 'Assigned to', align: 'center', width: '9rem' },
    { key: 'created_at_human', label: 'Created', sortable: false, width: '10rem' },
];

function deleteLock(row) {
    if (row.is_protected) {
        return 'Built-in role — cannot be deleted';
    }

    if (row.users_count > 0) {
        return `Still assigned to ${row.users_count} ${row.users_count === 1 ? 'account' : 'accounts'}`;
    }

    return null;
}

function destroy(row) {
    if (deleteLock(row)) {
        return;
    }

    confirmThenDelete(route('admin.roles.destroy', row.id), `${row.name} role`);
}
</script>

<template>
    <AppLayout title="Roles">
        <PageHeader
            title="Roles"
            subtitle="Named bundles of permissions. Create as many as the club needs — Manager, Supervisor, Cashier, Encoder — no code change required."
            :breadcrumbs="[{ label: 'Roles' }]"
            :home-href="route('admin.dashboard')"
        >
            <template #actions>
                <Button v-if="can('roles.create')" :href="route('admin.roles.create')">
                    <template #icon><Plus :size="16" /></template>
                    New role
                </Button>
            </template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="roles.data"
            :loading="table.loading"
            :sort="table.sort"
            :direction="table.direction"
            v-model:search="table.search"
            v-model:per-page="table.perPage"
            :filtered="table.hasActiveFilters"
            search-placeholder="Search roles…"
            min-width="min-w-[54rem]"
            empty-title="No roles defined"
            empty-description="A role groups permissions together so accounts can be granted access consistently."
            :empty-icon="ShieldCheck"
            @sort="table.sortBy"
        >
            <template #emptyAction>
                <Button v-if="can('roles.create')" :href="route('admin.roles.create')">
                    <template #icon><ShieldPlus :size="16" /></template>
                    Create the first role
                </Button>
            </template>

            <!-- Role -->
            <template #cell-name="{ row }">
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        :class="[
                            'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl',
                            row.is_protected ? 'bg-brand-50 text-brand-600' : 'bg-ink-100 text-ink-500',
                        ]"
                        aria-hidden="true"
                    >
                        <component :is="row.is_protected ? Lock : ShieldCheck" :size="17" />
                    </span>
                    <div class="min-w-0">
                        <p class="flex items-center gap-1.5 truncate text-sm font-semibold text-ink-900">
                            {{ row.name }}
                        </p>
                        <p class="truncate text-xs text-ink-500">
                            {{ row.is_protected ? 'Built-in — always holds every permission' : 'Custom role' }}
                        </p>
                    </div>
                </div>
            </template>

            <!-- Permission coverage -->
            <template #cell-permissions_count="{ row }">
                <div class="flex items-center gap-3">
                    <div
                        class="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-ink-100"
                        role="presentation"
                    >
                        <div
                            class="h-full rounded-full bg-brand-500 transition-[width] duration-300 ease-[var(--ease-out-soft)]"
                            :style="{
                                width: `${permissionTotal ? Math.round((row.permissions_count / permissionTotal) * 100) : 0}%`,
                            }"
                        />
                    </div>
                    <span class="text-xs font-medium text-ink-700 tabular-nums">
                        {{ row.permissions_count }} / {{ permissionTotal }}
                    </span>
                </div>
            </template>

            <!-- Assignment -->
            <template #cell-users_count="{ row }">
                <Badge
                    :label="String(row.users_count)"
                    :tone="row.users_count > 0 ? 'info' : 'ink'"
                    :dot="false"
                    size="sm"
                />
            </template>

            <!-- Actions -->
            <template #actions="{ row }">
                <IconButton
                    v-if="can('roles.update')"
                    variant="edit"
                    :label="row.is_protected ? 'Review permissions' : 'Edit role'"
                    :href="route('admin.roles.edit', row.id)"
                >
                    <template #default="{ size }"><Pencil :size="size" /></template>
                </IconButton>

                <IconButton
                    v-if="can('roles.delete')"
                    variant="delete"
                    :label="deleteLock(row) ?? 'Delete role'"
                    :disabled="Boolean(deleteLock(row))"
                    @click="destroy(row)"
                >
                    <template #default="{ size }"><Trash2 :size="size" /></template>
                </IconButton>
            </template>

            <template #footer>
                <Pagination :paginator="roles" label="roles" :only="['roles', 'filters']" />
            </template>
        </DataTable>

        <p v-if="can('users.view')" class="mt-4 flex items-center gap-1.5 text-xs text-ink-400">
            <UsersIcon :size="13" aria-hidden="true" />
            Assign roles to people on the
            <Link :href="route('admin.users.index')" class="font-medium text-brand-600 hover:text-brand-700">
                Users
            </Link>
            screen.
        </p>
    </AppLayout>
</template>
