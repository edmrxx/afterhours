<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    AtSign,
    Info,
    KeyRound,
    Lock,
    Mail,
    Phone,
    Save,
    ShieldCheck,
    User as UserIcon,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import SectionHeading from '@/Components/SectionHeading.vue';
import Alert from '@/Components/Alert.vue';
import Avatar from '@/Components/Avatar.vue';
import Button from '@/Components/Button.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormToggle from '@/Components/FormToggle.vue';
import FormCheckbox from '@/Components/FormCheckbox.vue';

/*
| Users create + edit.
|
| The role selector is populated from the server, never from a constant, so a
| role invented five minutes ago is assignable here without a rebuild. Locked
| controls mirror `UserPolicy` exactly — the server refuses the same edits the
| UI hides, so a crafted request gains nothing.
*/

const props = defineProps({
    /** null on create. */
    user: { type: Object, default: null },
    /** [{ value, label }] — every role in the database. */
    roleOptions: { type: Array, default: () => [] },
    canChangeRole: { type: Boolean, default: true },
    canChangeStatus: { type: Boolean, default: true },
    roleLockReason: { type: String, default: null },
    statusLockReason: { type: String, default: null },
});

const isEdit = computed(() => props.user !== null);

const form = useForm({
    name: props.user?.name ?? '',
    username: props.user?.username ?? '',
    email: props.user?.email ?? '',
    phone: props.user?.phone ?? '',
    role: props.user?.role ?? props.roleOptions[0]?.value ?? '',
    password: '',
    generate_password: true,
    is_active: props.user?.is_active ?? true,
});

/** Edit mode keeps the password section closed until it is deliberately opened. */
const changingPassword = ref(false);

const usernamePreview = computed(() => form.username.trim().toLowerCase());

const initials = computed(() => {
    if (props.user?.initials) {
        return props.user.initials;
    }

    const parts = form.name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return null;
    }

    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
});

function normaliseUsername() {
    form.username = form.username.trim().toLowerCase().replace(/[^a-z0-9._]/g, '');
}

function submit() {
    /*
     | Locked fields are omitted entirely rather than sent unchanged: the server
     | fills them from the current record, so there is nothing to tamper with.
     */
    const payload = (data) => {
        const next = { ...data };

        if (isEdit.value) {
            delete next.generate_password;

            if (!changingPassword.value || !next.password) {
                delete next.password;
            }

            if (!props.canChangeRole) {
                delete next.role;
            }

            if (!props.canChangeStatus) {
                delete next.is_active;
            }
        } else if (next.generate_password) {
            next.password = '';
        }

        return next;
    };

    if (isEdit.value) {
        form.transform(payload).put(route('admin.users.update', props.user.id), {
            preserveScroll: true,
        });

        return;
    }

    form.transform(payload).post(route('admin.users.store'), { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="isEdit ? `Edit ${user.name}` : 'New user'">
        <PageHeader
            :title="isEdit ? user.name : 'Invite a user'"
            :subtitle="
                isEdit
                    ? 'Update the account details, role and access.'
                    : 'Create a staff account and give it a role. The invitee sets their own password on first sign-in.'
            "
            :breadcrumbs="[
                { label: 'Users', href: route('admin.users.index') },
                { label: isEdit ? 'Edit' : 'New' },
            ]"
            :home-href="route('admin.dashboard')"
            :back-href="route('admin.users.index')"
            back-label="Back to users"
        />

        <form class="grid grid-cols-1 gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <!-- Main column ------------------------------------------------ -->
            <div class="space-y-6 lg:col-span-2">
                <Card>
                    <SectionHeading
                        title="Identity"
                        description="How this person is shown across the admin area, and how they sign in."
                        :icon="UserIcon"
                        divider
                        class="mb-5"
                    />

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <FormInput
                                v-model="form.name"
                                label="Full name"
                                placeholder="Juan Dela Cruz"
                                :icon="UserIcon"
                                :error="form.errors.name"
                                required
                                autocomplete="name"
                            />
                        </div>

                        <FormInput
                            v-model="form.username"
                            label="Username"
                            placeholder="juan.delacruz"
                            :icon="AtSign"
                            :error="form.errors.username"
                            :hint="
                                usernamePreview
                                    ? `Signs in as “${usernamePreview}”. Lowercase letters, numbers, dots and underscores.`
                                    : 'Lowercase letters, numbers, dots and underscores.'
                            "
                            required
                            autocomplete="off"
                            @blur="normaliseUsername"
                        />

                        <FormInput
                            v-model="form.phone"
                            label="Mobile number"
                            placeholder="0917 123 4567"
                            :icon="Phone"
                            :error="form.errors.phone"
                            hint="Optional. Used for booking-desk contact only."
                            autocomplete="tel"
                        />

                        <div class="sm:col-span-2">
                            <FormInput
                                v-model="form.email"
                                type="email"
                                label="Email address"
                                placeholder="juan@example.com"
                                :icon="Mail"
                                :error="form.errors.email"
                                hint="Optional, but required to receive system notifications."
                                autocomplete="email"
                            />
                        </div>
                    </div>
                </Card>

                <!-- Password ---------------------------------------------- -->
                <Card>
                    <SectionHeading
                        title="Password"
                        :description="
                            isEdit
                                ? 'Leave this section closed to keep the current password.'
                                : 'Whatever you set here is temporary — it must be changed on first sign-in.'
                        "
                        :icon="KeyRound"
                        divider
                        class="mb-5"
                    />

                    <!-- Create ------------------------------------------- -->
                    <template v-if="!isEdit">
                        <FormCheckbox
                            v-model="form.generate_password"
                            card
                            label="Generate a temporary password for me"
                            hint="A strong 14-character password is created and shown to you once, right after the account is saved. Share it with the invitee — they will be asked to replace it the moment they sign in."
                        />

                        <div v-if="!form.generate_password" class="mt-5">
                            <FormInput
                                v-model="form.password"
                                type="password"
                                label="Temporary password"
                                :icon="Lock"
                                :error="form.errors.password"
                                hint="At least 8 characters, with letters and numbers."
                                required
                                autocomplete="new-password"
                            />
                        </div>
                    </template>

                    <!-- Edit --------------------------------------------- -->
                    <template v-else>
                        <FormToggle
                            v-model="changingPassword"
                            label="Set a new password"
                            hint="Only switch this on if you need to hand the account back to its owner."
                            label-right
                        />

                        <div v-if="changingPassword" class="mt-5 space-y-4">
                            <FormInput
                                v-model="form.password"
                                type="password"
                                label="New password"
                                :icon="Lock"
                                :error="form.errors.password"
                                hint="At least 8 characters, with letters and numbers."
                                autocomplete="new-password"
                            />

                            <Alert
                                variant="warning"
                                title="This forces a rotation"
                                message="Because you know this password, the account is flagged and cannot be used for anything until the owner replaces it at the next sign-in."
                            />
                        </div>
                    </template>

                    <Alert
                        v-if="!isEdit"
                        class="mt-5"
                        variant="info"
                        title="Every new account rotates its password"
                        message="Invitees land on the change-password screen and cannot reach the admin area until they have set a password only they know."
                    />
                </Card>
            </div>

            <!-- Side column ------------------------------------------------ -->
            <div class="space-y-6">
                <Card v-if="isEdit">
                    <div class="flex items-center gap-3">
                        <Avatar :name="user.name" :initials="user.initials" size="lg" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-ink-900">{{ user.name }}</p>
                            <p class="truncate text-xs text-ink-500">@{{ user.username }}</p>
                        </div>
                    </div>

                    <dl class="mt-4 space-y-2 border-t border-ink-200/70 pt-4 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-500">Last sign-in</dt>
                            <dd class="text-right font-medium text-ink-800">
                                {{ user.last_login_human ?? 'Never' }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-500">Created</dt>
                            <dd class="text-right font-medium text-ink-800">
                                {{ user.created_at_human ?? '—' }}
                            </dd>
                        </div>
                        <div v-if="user.must_change_password" class="flex items-center justify-between gap-3">
                            <dt class="text-ink-500">Password</dt>
                            <dd class="text-right font-medium text-warn-600">Change pending</dd>
                        </div>
                    </dl>
                </Card>

                <Card>
                    <SectionHeading
                        title="Role & access"
                        description="Permissions come from the role — never from the person."
                        :icon="ShieldCheck"
                        divider
                        class="mb-5"
                    />

                    <div class="space-y-5">
                        <FormSelect
                            v-model="form.role"
                            label="Role"
                            :options="roleOptions"
                            placeholder="Choose a role"
                            :error="form.errors.role"
                            :disabled="!canChangeRole"
                            required
                            :hint="
                                canChangeRole
                                    ? 'Determines every screen and action this account can reach.'
                                    : undefined
                            "
                        />

                        <Alert
                            v-if="!canChangeRole"
                            variant="warning"
                            :message="roleLockReason ?? 'This role cannot be changed.'"
                        />

                        <div class="border-t border-ink-200/70 pt-5">
                            <FormToggle
                                v-model="form.is_active"
                                label="Account is active"
                                hint="Deactivated accounts are signed out immediately and cannot sign in."
                                :disabled="!canChangeStatus"
                                :error="form.errors.is_active"
                                label-right
                            />

                            <Alert
                                v-if="!canChangeStatus"
                                class="mt-4"
                                variant="warning"
                                :message="statusLockReason ?? 'This account cannot be deactivated.'"
                            />
                        </div>
                    </div>
                </Card>

                <Card v-if="roleOptions.length === 0" padding="sm">
                    <div class="flex items-start gap-2.5 text-xs text-ink-600">
                        <Info :size="15" class="mt-0.5 shrink-0 text-info-600" aria-hidden="true" />
                        <p>
                            No roles exist yet. Create one under Roles before inviting a user, or the
                            account will have no access at all.
                        </p>
                    </div>
                </Card>

                <div class="flex flex-col gap-2.5 sm:flex-row-reverse lg:flex-col">
                    <Button
                        type="submit"
                        :loading="form.processing"
                        :disabled="roleOptions.length === 0"
                        block
                    >
                        <template #icon><Save :size="16" /></template>
                        {{ isEdit ? 'Save changes' : 'Create account' }}
                    </Button>

                    <Button variant="secondary" :href="route('admin.users.index')" block>
                        Cancel
                    </Button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
