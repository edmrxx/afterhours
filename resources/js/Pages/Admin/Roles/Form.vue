<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { CircleSlash, Lock, Save, ShieldCheck, SquareCheck, Users as UsersIcon } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import FormInput from '@/Components/FormInput.vue';
import FormCheckbox from '@/Components/FormCheckbox.vue';

/*
| Roles create + edit — the permission matrix.
|
| Groups are built entirely server-side from the `permissions` table, so a
| permission seeded after this file was written renders here automatically.
| Nothing in this component knows a single permission name.
*/

const props = defineProps({
    /** null on create. */
    role: { type: Object, default: null },
    /** [{ key, label, permissions: [{ name, label, description }] }] */
    groups: { type: Array, default: () => [] },
    /** The administrator role is visible but immutable. */
    isProtected: { type: Boolean, default: false },
    protectedRole: { type: String, default: 'Admin' },
});

const isEdit = computed(() => props.role !== null);

const allPermissions = computed(() =>
    props.groups.flatMap((group) => group.permissions.map((permission) => permission.name)),
);

const form = useForm({
    name: props.role?.name ?? '',
    permissions: [...(props.role?.permissions ?? [])],
});

/* ------------------------------------------------------------------ */
/* Selection helpers                                                   */
/* ------------------------------------------------------------------ */

const selected = computed(() => new Set(form.permissions));

const selectedCount = computed(() => form.permissions.length);

const totalCount = computed(() => allPermissions.value.length);

const allSelected = computed(
    () => totalCount.value > 0 && selectedCount.value >= totalCount.value,
);

function groupState(group) {
    const names = group.permissions.map((permission) => permission.name);
    const chosen = names.filter((name) => selected.value.has(name));

    return {
        names,
        count: chosen.length,
        total: names.length,
        all: names.length > 0 && chosen.length === names.length,
        some: chosen.length > 0 && chosen.length < names.length,
    };
}

function toggleGroup(group) {
    if (props.isProtected) {
        return;
    }

    const { names, all } = groupState(group);

    form.permissions = all
        ? form.permissions.filter((name) => !names.includes(name))
        : [...new Set([...form.permissions, ...names])];
}

function selectAll() {
    if (props.isProtected) {
        return;
    }

    form.permissions = [...allPermissions.value];
}

function clearAll() {
    if (props.isProtected) {
        return;
    }

    form.permissions = [];
}

/* ------------------------------------------------------------------ */
/* Submit                                                              */
/* ------------------------------------------------------------------ */

function submit() {
    if (isEdit.value) {
        form.put(route('admin.roles.update', props.role.id), { preserveScroll: true });

        return;
    }

    form.post(route('admin.roles.store'), { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="isEdit ? `Edit ${role.name}` : 'New role'">
        <PageHeader
            :title="isEdit ? role.name : 'New role'"
            :subtitle="
                isProtected
                    ? `${protectedRole} is built into the system. Its permissions are shown for reference and cannot be changed.`
                    : 'Name the role, then tick exactly what it is allowed to do. Anyone holding it inherits these permissions everywhere.'
            "
            :breadcrumbs="[
                { label: 'Roles', href: route('admin.roles.index') },
                { label: isEdit ? 'Edit' : 'New' },
            ]"
            :home-href="route('admin.dashboard')"
            :back-href="route('admin.roles.index')"
            back-label="Back to roles"
        />

        <form class="space-y-6" @submit.prevent="submit">
            <Alert
                v-if="isProtected"
                variant="warning"
                :title="`The ${protectedRole} role is protected`"
                message="It can never be renamed, deleted, or stripped of a permission — otherwise the system could be left with nobody able to administer it. To give someone narrower access, create a new role instead."
            />

            <!-- Identity + summary -------------------------------------- -->
            <Card>
                <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <FormInput
                            v-model="form.name"
                            label="Role name"
                            placeholder="e.g. Supervisor, Cashier, Accountant"
                            :icon="ShieldCheck"
                            :error="form.errors.name"
                            :disabled="isProtected"
                            required
                            :hint="
                                isProtected
                                    ? 'Built-in roles cannot be renamed.'
                                    : 'Shown wherever people are assigned. Keep it short and describe the job, not the person.'
                            "
                            autocomplete="off"
                        />
                    </div>

                    <div class="rounded-xl border border-ink-200/70 bg-ink-50/60 p-4">
                        <p class="text-xs font-medium text-ink-500">Permissions selected</p>
                        <p class="mt-1 flex items-baseline gap-1.5">
                            <span class="text-2xl leading-none font-semibold text-ink-900 tabular-nums">
                                {{ selectedCount }}
                            </span>
                            <span class="text-sm text-ink-500 tabular-nums">of {{ totalCount }}</span>
                        </p>
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-ink-200/70">
                            <div
                                class="h-full rounded-full bg-brand-500 transition-[width] duration-300 ease-[var(--ease-out-soft)]"
                                :style="{
                                    width: `${totalCount ? Math.round((selectedCount / totalCount) * 100) : 0}%`,
                                }"
                            />
                        </div>

                        <p
                            v-if="isEdit && role.users_count > 0"
                            class="mt-3 flex items-center gap-1.5 text-[11px] text-ink-500"
                        >
                            <UsersIcon :size="12" aria-hidden="true" />
                            {{ role.users_count }}
                            {{ role.users_count === 1 ? 'account uses' : 'accounts use' }} this role
                        </p>
                    </div>
                </div>
            </Card>

            <!-- Permission matrix ---------------------------------------- -->
            <Card>
                <template #header>
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-ink-900">Permissions</h3>
                        <p class="mt-0.5 text-xs text-ink-500">
                            Grouped by module. Access everywhere in After Hours is checked against these
                            names — never against a role name.
                        </p>
                    </div>
                </template>

                <template #actions>
                    <button
                        type="button"
                        :disabled="isProtected || allSelected"
                        class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg px-2.5 text-xs font-medium text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:pointer-events-none disabled:opacity-45"
                        @click="selectAll"
                    >
                        <SquareCheck :size="14" aria-hidden="true" />
                        Select all
                    </button>
                    <button
                        type="button"
                        :disabled="isProtected || selectedCount === 0"
                        class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg px-2.5 text-xs font-medium text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:pointer-events-none disabled:opacity-45"
                        @click="clearAll"
                    >
                        <CircleSlash :size="14" aria-hidden="true" />
                        Clear
                    </button>
                </template>

                <p
                    v-if="form.errors.permissions"
                    class="mb-5 rounded-lg bg-danger-50 px-3.5 py-2.5 text-xs font-medium text-danger-600 ring-1 ring-danger-500/20"
                    role="alert"
                >
                    {{ form.errors.permissions }}
                </p>

                <div
                    v-if="groups.length"
                    class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3"
                >
                    <fieldset
                        v-for="group in groups"
                        :key="group.key"
                        :class="[
                            'flex min-w-0 flex-col rounded-xl border p-4 sm:p-5',
                            'transition-colors duration-150 ease-[var(--ease-out-soft)]',
                            groupState(group).count > 0
                                ? 'border-brand-200 bg-brand-50/25'
                                : 'border-ink-200 bg-white',
                        ]"
                    >
                        <legend class="sr-only">{{ group.label }} permissions</legend>

                        <!-- Group header + select-all -->
                        <div class="mb-4 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-ink-900">
                                    {{ group.label }}
                                </p>
                                <p class="mt-0.5 text-[11px] text-ink-500 tabular-nums">
                                    {{ groupState(group).count }} of {{ groupState(group).total }} selected
                                </p>
                            </div>

                            <button
                                type="button"
                                :disabled="isProtected"
                                :aria-pressed="groupState(group).all"
                                :class="[
                                    'shrink-0 cursor-pointer rounded-lg px-2 py-1 text-[11px] font-semibold transition-colors',
                                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                                    'disabled:pointer-events-none disabled:opacity-45',
                                    groupState(group).all
                                        ? 'bg-brand-50 text-brand-700 hover:bg-brand-100'
                                        : 'text-ink-500 hover:bg-ink-100 hover:text-ink-800',
                                ]"
                                @click="toggleGroup(group)"
                            >
                                {{ groupState(group).all ? 'Deselect all' : 'Select all' }}
                            </button>
                        </div>

                        <!-- Permissions -->
                        <div class="space-y-3">
                            <FormCheckbox
                                v-for="permission in group.permissions"
                                :key="permission.name"
                                v-model="form.permissions"
                                :value="permission.name"
                                :label="permission.label"
                                :hint="permission.description"
                                :disabled="isProtected"
                            />
                        </div>
                    </fieldset>
                </div>

                <div v-else class="py-10 text-center text-sm text-ink-500">
                    No permissions have been seeded yet, so there is nothing to grant.
                </div>
            </Card>

            <!-- Actions -------------------------------------------------- -->
            <div
                class="sticky bottom-0 -mx-4 flex flex-col-reverse gap-2.5 border-t border-ink-200 bg-white/90 px-4 py-3 backdrop-blur-md sm:-mx-6 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:-mx-8 lg:px-8"
            >
                <p class="flex items-center gap-1.5 text-xs text-ink-500">
                    <Lock v-if="isProtected" :size="13" aria-hidden="true" />
                    <template v-if="isProtected">
                        {{ protectedRole }} always holds every permission.
                    </template>
                    <template v-else>
                        {{ selectedCount }} of {{ totalCount }} permissions will be granted.
                    </template>
                </p>

                <div class="flex flex-col-reverse gap-2.5 sm:flex-row sm:items-center">
                    <Button variant="secondary" :href="route('admin.roles.index')">Cancel</Button>

                    <Button
                        type="submit"
                        :loading="form.processing"
                        :disabled="isProtected || groups.length === 0"
                    >
                        <template #icon><Save :size="16" /></template>
                        {{ isEdit ? 'Save role' : 'Create role' }}
                    </Button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
