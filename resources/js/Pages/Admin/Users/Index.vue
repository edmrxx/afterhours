<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { KeyRound, Pencil, Plus, ShieldAlert, Trash2, UserPlus, Users as UsersIcon } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Pagination from '@/Components/Pagination.vue';
import Avatar from '@/Components/Avatar.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import IconButton from '@/Components/IconButton.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormToggle from '@/Components/FormToggle.vue';
import { useDataTable } from '@/Composables/useDataTable';
import { usePermissions } from '@/Composables/usePermissions';
import { useSwal } from '@/Composables/useSwal';
import { useConfirm } from '@/Composables/useConfirm';

/*
| Users index.
|
| Roles are rendered from `row.roles`, which the controller eager-loads — the
| role column on a 100-row page must never become 100 queries.
*/

const props = defineProps({
    /** Laravel length-aware paginator of user rows. */
    users: { type: Object, required: true },
    /** [{ value, label }] — every role in the table, no hardcoded list. */
    roleOptions: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const { confirmAction } = useSwal();
const { confirmThenDelete } = useConfirm();

const table = useDataTable(
    'admin.users.index',
    { role: '', status: '' },
    { only: ['users', 'filters'], sort: 'created_at', direction: 'desc' },
);

const columns = [
    { key: 'name', label: 'User', sortable: true },
    { key: 'roles', label: 'Role' },
    { key: 'email', label: 'Contact', sortable: true },
    { key: 'is_active', label: 'Active', align: 'center', width: '7rem' },
    { key: 'last_login_at', label: 'Last sign-in', sortable: true, width: '11rem' },
];

const statusOptions = [
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Deactivated only' },
];

const roleFilterOptions = computed(() =>
    props.roleOptions.map((role) => ({ value: role.value, label: role.label })),
);

/* ------------------------------------------------------------------ */
/* Row actions                                                         */
/* ------------------------------------------------------------------ */

/** Why the status switch is locked, or null when it is free to move. */
function statusLock(row) {
    if (!row.is_active) {
        return null;
    }

    if (row.is_self) {
        return 'You cannot deactivate your own account.';
    }

    if (row.is_last_active_admin) {
        return 'This is the last active administrator.';
    }

    return null;
}

function deleteLock(row) {
    if (row.is_self) {
        return 'You cannot delete your own account.';
    }

    if (row.is_last_admin) {
        return 'This is the last administrator.';
    }

    return null;
}

async function toggleStatus(row) {
    if (statusLock(row)) {
        return;
    }

    const deactivating = row.is_active;

    const confirmed = await confirmAction({
        title: deactivating ? `Deactivate ${row.name}?` : `Reactivate ${row.name}?`,
        text: deactivating
            ? 'They will be signed out immediately and cannot sign in again until reactivated.'
            : 'They will be able to sign in again straight away.',
        confirmText: deactivating ? 'Deactivate' : 'Reactivate',
        variant: deactivating ? 'danger' : 'success',
    });

    if (!confirmed) {
        return;
    }

    router.patch(route('admin.users.status', row.id), {}, { preserveScroll: true });
}

async function resetPassword(row) {
    const confirmed = await confirmAction({
        title: `Reset the password for ${row.name}?`,
        html:
            'A new temporary password will be generated and shown to you once. ' +
            `<strong>${row.name}</strong> must change it the next time they sign in.`,
        confirmText: 'Generate new password',
        variant: 'warning',
        icon: 'warning',
    });

    if (!confirmed) {
        return;
    }

    router.post(route('admin.users.reset', row.id), {}, { preserveScroll: true });
}

function destroy(row) {
    if (deleteLock(row)) {
        return;
    }

    confirmThenDelete(route('admin.users.destroy', row.id), row.name);
}
</script>

<template>
    <AppLayout title="Users">
        <PageHeader
            title="Users"
            subtitle="Staff accounts, their role and their access to the admin area."
            :breadcrumbs="[{ label: 'Users' }]"
            :home-href="route('admin.dashboard')"
        >
            <template #actions>
                <Button v-if="can('users.create')" :href="route('admin.users.create')">
                    <template #icon><Plus :size="16" /></template>
                    New user
                </Button>
            </template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="users.data"
            :loading="table.loading"
            :sort="table.sort"
            :direction="table.direction"
            v-model:search="table.search"
            v-model:per-page="table.perPage"
            :filtered="table.hasActiveFilters"
            search-placeholder="Search name, username or email…"
            min-width="min-w-[64rem]"
            empty-title="No staff accounts yet"
            empty-description="Invite a colleague and give them a role to get started."
            :empty-icon="UsersIcon"
            @sort="table.sortBy"
        >
            <!-- Filters -->
            <template #toolbar>
                <div class="w-full sm:w-44">
                    <FormSelect
                        v-model="table.filters.role"
                        :options="roleFilterOptions"
                        placeholder="All roles"
                        size="sm"
                        label="Role"
                        sr-only-label
                    />
                </div>
                <div class="w-full sm:w-44">
                    <FormSelect
                        v-model="table.filters.status"
                        :options="statusOptions"
                        placeholder="Any status"
                        size="sm"
                        label="Status"
                        sr-only-label
                    />
                </div>
                <button
                    v-if="table.hasActiveFilters"
                    type="button"
                    class="h-8 cursor-pointer rounded-lg px-2.5 text-xs font-medium text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                    @click="table.reset()"
                >
                    Clear filters
                </button>
            </template>

            <template #emptyAction>
                <Button v-if="can('users.create')" :href="route('admin.users.create')">
                    <template #icon><UserPlus :size="16" /></template>
                    Invite a user
                </Button>
            </template>

            <!-- Identity -->
            <template #cell-name="{ row }">
                <div class="flex min-w-0 items-center gap-3">
                    <Avatar :name="row.name" :initials="row.initials" size="md" />
                    <div class="min-w-0">
                        <p class="flex items-center gap-1.5 truncate text-sm font-semibold text-ink-900">
                            {{ row.name }}
                            <span
                                v-if="row.is_self"
                                class="rounded-full bg-brand-50 px-1.5 py-0.5 text-[10px] font-semibold text-brand-700"
                            >
                                You
                            </span>
                        </p>
                        <p class="truncate text-xs text-ink-500">@{{ row.username }}</p>
                    </div>
                </div>
            </template>

            <!-- Roles -->
            <template #cell-roles="{ row }">
                <div v-if="row.roles.length" class="flex flex-wrap gap-1">
                    <Badge
                        v-for="name in row.roles"
                        :key="name"
                        :label="name"
                        tone="brand"
                        :dot="false"
                        size="sm"
                    />
                </div>
                <span v-else class="text-xs text-ink-400">No role</span>
            </template>

            <!-- Contact -->
            <template #cell-email="{ row }">
                <p class="truncate text-sm text-ink-700">{{ row.email ?? '—' }}</p>
                <p class="truncate text-xs text-ink-500">{{ row.phone ?? 'No phone' }}</p>
            </template>

            <!-- Active switch -->
            <template #cell-is_active="{ row }">
                <div class="flex flex-col items-center gap-1">
                    <FormToggle
                        :model-value="row.is_active"
                        :disabled="!can('users.update') || Boolean(statusLock(row))"
                        size="sm"
                        :aria-label="`${row.is_active ? 'Deactivate' : 'Activate'} ${row.name}`"
                        @update:model-value="toggleStatus(row)"
                    />
                    <span
                        v-if="statusLock(row)"
                        class="flex items-center gap-1 text-[10px] leading-none text-ink-400"
                    >
                        <ShieldAlert :size="11" aria-hidden="true" />
                        Locked
                    </span>
                </div>
            </template>

            <!-- Last sign-in -->
            <template #cell-last_login_at="{ row }">
                <p v-if="row.last_login_human" class="text-sm text-ink-700">
                    {{ row.last_login_human }}
                </p>
                <p v-else class="text-sm text-ink-400">Never signed in</p>
                <p v-if="row.must_change_password" class="text-[11px] text-warn-600">
                    Password change pending
                </p>
            </template>

            <!-- Actions -->
            <template #actions="{ row }">
                <IconButton
                    v-if="can('users.update')"
                    variant="edit"
                    label="Edit user"
                    :href="route('admin.users.edit', row.id)"
                >
                    <template #default="{ size }"><Pencil :size="size" /></template>
                </IconButton>

                <IconButton
                    v-if="can('users.update')"
                    variant="warn"
                    label="Reset password"
                    @click="resetPassword(row)"
                >
                    <template #default="{ size }"><KeyRound :size="size" /></template>
                </IconButton>

                <IconButton
                    v-if="can('users.delete')"
                    variant="delete"
                    :label="deleteLock(row) ?? 'Delete user'"
                    :disabled="Boolean(deleteLock(row))"
                    @click="destroy(row)"
                >
                    <template #default="{ size }"><Trash2 :size="size" /></template>
                </IconButton>
            </template>

            <template #footer>
                <Pagination :paginator="users" label="users" :only="['users', 'filters']" />
            </template>
        </DataTable>

        <p v-if="can('roles.view')" class="mt-4 text-xs text-ink-400">
            Roles are data, not code — create as many as you need under
            <Link :href="route('admin.roles.index')" class="font-medium text-brand-600 hover:text-brand-700">
                Roles
            </Link>
            and they appear here immediately.
        </p>
    </AppLayout>
</template>
