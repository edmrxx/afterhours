<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    Clock,
    Globe,
    IdCard,
    KeyRound,
    Mail,
    Phone,
    Save,
    ShieldCheck,
    Trash2,
    User,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/Alert.vue';
import Avatar from '@/Components/Avatar.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormFileUpload from '@/Components/FormFileUpload.vue';
import FormInput from '@/Components/FormInput.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Tabs from '@/Components/Tabs.vue';
import { useSwal } from '@/Composables/useSwal';

/*
| Own profile.
|
| Two tabs on one page rather than two routes: `routes/web.php` exposes a
| single GET /admin/profile, so the split is client state.
*/

const props = defineProps({
    profile: { type: Object, required: true },
    /** Upload ceiling in megabytes, mirrored from the Form Request. */
    maxAvatarMb: { type: Number, default: 2 },
});

const { confirmAction, toastInfo } = useSwal();

const tab = ref('profile');

const TABS = [
    { key: 'profile', label: 'Profile', icon: User },
    { key: 'security', label: 'Security', icon: ShieldCheck },
];

/* ------------------------------------------------------------------ */
/* Form                                                                */
/* ------------------------------------------------------------------ */

const form = useForm({
    // File uploads must travel as multipart POST; Laravel reads the spoofed verb.
    _method: 'put',
    name: props.profile.name ?? '',
    email: props.profile.email ?? '',
    phone: props.profile.phone ?? '',
    avatar: null,
    remove_avatar: false,
});

/* ------------------------------------------------------------------ */
/* Avatar preview                                                      */
/* ------------------------------------------------------------------ */

const objectUrl = ref(null);

function releaseObjectUrl() {
    if (objectUrl.value) {
        URL.revokeObjectURL(objectUrl.value);
        objectUrl.value = null;
    }
}

watch(
    () => form.avatar,
    (file) => {
        releaseObjectUrl();

        if (file instanceof File) {
            objectUrl.value = URL.createObjectURL(file);
            form.remove_avatar = false;
        }
    },
);

onBeforeUnmount(releaseObjectUrl);

const previewUrl = computed(() => {
    if (objectUrl.value) {
        return objectUrl.value;
    }

    return form.remove_avatar ? null : (props.profile.avatar_url ?? null);
});

const hasAvatar = computed(() => Boolean(previewUrl.value));

async function removeAvatar() {
    const confirmed = await confirmAction({
        title: 'Remove your photo?',
        text: 'Your initials will be shown instead until you upload a new one.',
        confirmText: 'Remove photo',
        cancelText: 'Keep it',
        variant: 'danger',
    });

    if (!confirmed) {
        return;
    }

    form.avatar = null;
    releaseObjectUrl();
    form.remove_avatar = true;

    toastInfo('Photo will be removed when you save.');
}

/* ------------------------------------------------------------------ */
/* Submit                                                              */
/* ------------------------------------------------------------------ */

function submit() {
    form.post(route('admin.profile.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.avatar = null;
            form.remove_avatar = false;
            releaseObjectUrl();
        },
    });
}

function resetForm() {
    form.reset('name', 'email', 'phone', 'avatar', 'remove_avatar');
    form.clearErrors();
    releaseObjectUrl();
}

const isDirty = computed(
    () =>
        form.name !== (props.profile.name ?? '') ||
        form.email !== (props.profile.email ?? '') ||
        form.phone !== (props.profile.phone ?? '') ||
        form.avatar instanceof File ||
        form.remove_avatar === true,
);

/* ------------------------------------------------------------------ */
/* Security tab presentation                                           */
/* ------------------------------------------------------------------ */

const DATE_FORMAT = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatDate(value) {
    if (!value) {
        return 'Never';
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? 'Unknown' : DATE_FORMAT.format(parsed);
}

const securityFacts = computed(() => [
    {
        key: 'username',
        icon: IdCard,
        label: 'Username',
        value: `@${props.profile.username}`,
        hint: 'Your sign-in identifier. Only an administrator can change it.',
    },
    {
        key: 'last_login',
        icon: Clock,
        label: 'Last sign-in',
        value: formatDate(props.profile.last_login_at),
        hint: 'Recorded on every successful sign-in.',
    },
    {
        key: 'last_ip',
        icon: Globe,
        label: 'Last sign-in address',
        value: props.profile.last_login_ip ?? '—',
        hint: 'If you do not recognise this, change your password immediately.',
    },
    {
        key: 'member_since',
        icon: ShieldCheck,
        label: 'Account created',
        value: formatDate(props.profile.created_at),
        hint: null,
    },
]);
</script>

<template>
    <AppLayout title="My profile">
        <PageHeader
            title="My profile"
            subtitle="Your details, your photo and how you sign in."
            :breadcrumbs="[{ label: 'My profile' }]"
        >
            <template #actions>
                <Button
                    variant="secondary"
                    :href="route('password.change')"
                >
                    <template #icon>
                        <KeyRound :size="16" aria-hidden="true" />
                    </template>
                    Change password
                </Button>
            </template>
        </PageHeader>

        <!-- Identity strip -->
        <div
            class="mb-6 flex flex-col gap-4 rounded-xl border border-ink-200/70 bg-white p-5 shadow-card sm:flex-row sm:items-center sm:p-6"
        >
            <Avatar
                :name="profile.name"
                :initials="profile.initials"
                :src="previewUrl"
                size="xl"
            />

            <div class="min-w-0 flex-1">
                <h2 class="truncate text-lg font-semibold tracking-tight text-ink-900">
                    {{ profile.name }}
                </h2>
                <p class="mt-0.5 truncate text-sm text-ink-500">
                    @{{ profile.username }}
                    <span v-if="profile.email"> · {{ profile.email }}</span>
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <Badge :status="profile.is_active" />
                    <Badge
                        v-for="role in profile.roles"
                        :key="role"
                        tone="brand"
                        :label="role"
                        :dot="false"
                        class="capitalize"
                    />
                    <Badge
                        tone="ink"
                        :label="`${profile.permission_count} permissions`"
                        :dot="false"
                    />
                </div>
            </div>
        </div>

        <Tabs v-model="tab" :tabs="TABS" class="mb-6" />

        <!-- ---------------------------------------------------------- -->
        <!-- Profile tab                                                 -->
        <!-- ---------------------------------------------------------- -->
        <form
            v-show="tab === 'profile'"
            class="grid grid-cols-1 gap-6 lg:grid-cols-3"
            novalidate
            @submit.prevent="submit"
        >
            <div class="lg:col-span-2">
                <Card title="Your details" subtitle="Shown across the admin area.">
                    <div class="space-y-5">
                        <FormInput
                            v-model="form.name"
                            label="Full name"
                            :icon="User"
                            autocomplete="name"
                            :error="form.errors.name"
                            :disabled="form.processing"
                            required
                        />

                        <FormInput
                            :model-value="profile.username"
                            label="Username"
                            :icon="IdCard"
                            readonly
                            hint="Your sign-in identifier. Contact an administrator to change it."
                        />

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <FormInput
                                v-model="form.email"
                                label="Email address"
                                type="email"
                                :icon="Mail"
                                autocomplete="email"
                                placeholder="you@example.com"
                                :error="form.errors.email"
                                :disabled="form.processing"
                                hint="Used for booking notifications sent to staff."
                            />

                            <FormInput
                                v-model="form.phone"
                                label="Phone number"
                                type="tel"
                                :icon="Phone"
                                autocomplete="tel"
                                placeholder="0917 123 4567"
                                :error="form.errors.phone"
                                :disabled="form.processing"
                            />
                        </div>
                    </div>

                    <template #footer>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <Button
                                v-if="isDirty"
                                variant="ghost"
                                :disabled="form.processing"
                                @click="resetForm"
                            >
                                Discard changes
                            </Button>

                            <Button
                                type="submit"
                                :loading="form.processing"
                                :disabled="form.processing || !isDirty"
                            >
                                <template #icon>
                                    <Save :size="16" aria-hidden="true" />
                                </template>
                                Save changes
                            </Button>
                        </div>
                    </template>
                </Card>
            </div>

            <div>
                <Card title="Profile photo" subtitle="Optional — initials are used otherwise.">
                    <div class="space-y-4">
                        <div class="flex items-center gap-4">
                            <Avatar
                                :name="profile.name"
                                :initials="profile.initials"
                                :src="previewUrl"
                                size="xl"
                            />
                            <div class="min-w-0 text-xs leading-relaxed text-ink-500">
                                <p class="font-medium text-ink-700">Preview</p>
                                <p class="mt-0.5">
                                    Square images look best. JPG, PNG or WEBP up to
                                    {{ maxAvatarMb }}MB.
                                </p>
                            </div>
                        </div>

                        <FormFileUpload
                            v-model="form.avatar"
                            label="Upload a new photo"
                            square
                            accept="image/png,image/jpeg,image/webp"
                            :max-size="maxAvatarMb"
                            :error="form.errors.avatar"
                            :progress="form.progress?.percentage ?? null"
                            :disabled="form.processing"
                        />

                        <Alert
                            v-if="form.remove_avatar"
                            variant="warning"
                            title="Photo queued for removal"
                        >
                            Save your changes to remove it, or discard to keep it.
                        </Alert>

                        <Button
                            v-else-if="hasAvatar"
                            variant="ghost"
                            size="sm"
                            block
                            :disabled="form.processing"
                            @click="removeAvatar"
                        >
                            <template #icon>
                                <Trash2 :size="15" aria-hidden="true" />
                            </template>
                            Remove current photo
                        </Button>
                    </div>
                </Card>
            </div>
        </form>

        <!-- ---------------------------------------------------------- -->
        <!-- Security tab                                                -->
        <!-- ---------------------------------------------------------- -->
        <div v-show="tab === 'security'" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <Card title="Password" subtitle="The credential that protects everything else.">
                    <div class="space-y-4">
                        <Alert
                            v-if="profile.must_change_password"
                            variant="warning"
                            title="Your password still needs changing"
                        >
                            This account is using the password it was created with.
                            Nothing else in the admin area will open until you replace it.
                        </Alert>

                        <p class="text-sm leading-relaxed text-ink-600">
                            Choose a password of at least 8 characters with upper and
                            lower case letters and a number. It is checked against
                            known breach data, so a password exposed in a past leak
                            will be rejected even if it looks strong.
                        </p>

                        <Button :href="route('password.change')">
                            <template #icon>
                                <KeyRound :size="16" aria-hidden="true" />
                            </template>
                            Change password
                        </Button>
                    </div>
                </Card>
            </div>

            <div>
                <Card title="Account activity" subtitle="Read only.">
                    <dl class="space-y-4">
                        <div
                            v-for="fact in securityFacts"
                            :key="fact.key"
                            class="flex gap-3"
                        >
                            <span
                                class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-500"
                                aria-hidden="true"
                            >
                                <component :is="fact.icon" :size="15" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <dt class="text-xs font-medium text-ink-500">
                                    {{ fact.label }}
                                </dt>
                                <dd class="mt-0.5 truncate text-sm font-semibold text-ink-900">
                                    {{ fact.value }}
                                </dd>
                                <p v-if="fact.hint" class="mt-1 text-xs leading-relaxed text-ink-400">
                                    {{ fact.hint }}
                                </p>
                            </div>
                        </div>
                    </dl>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
