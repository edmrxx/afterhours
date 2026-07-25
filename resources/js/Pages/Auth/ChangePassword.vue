<script setup>
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { Check, KeyRound, LogOut, ShieldCheck, X } from '@lucide/vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import FormInput from '@/Components/FormInput.vue';
import { useSwal } from '@/Composables/useSwal';

/*
| Forced password rotation.
|
| Reached through the `password.changed` middleware, which lets nothing else in
| the admin area load until this form succeeds. The copy has to explain that,
| otherwise the redirect reads as a bug.
*/

defineProps({
    /** True when the middleware sent the user here rather than the menu. */
    mustChange: { type: Boolean, default: false },
    /** { name, username, initials } */
    user: { type: Object, required: true },
});

const { confirmLogout } = useSwal();

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

/* ------------------------------------------------------------------ */
/* Strength meter — mirrors the server policy, never replaces it       */
/* ------------------------------------------------------------------ */

/**
 * The rules the server actually enforces, so the checklist can never promise
 * something `ChangePasswordRequest` will then reject.
 */
const requirements = computed(() => {
    const value = form.password;

    return [
        { label: 'At least 8 characters', met: value.length >= 8 },
        { label: 'An uppercase and a lowercase letter', met: /[a-z]/.test(value) && /[A-Z]/.test(value) },
        { label: 'At least one number', met: /\d/.test(value) },
        {
            label: 'Different from your current password',
            met: value.length > 0 && value !== form.current_password,
        },
    ];
});

const metCount = computed(() => requirements.value.filter((item) => item.met).length);

/** 0–4. The four required rules, plus a bonus tier for length and symbols. */
const strength = computed(() => {
    const value = form.password;

    if (value.length === 0) {
        return 0;
    }

    let score = metCount.value;

    // Everything mandatory satisfied — reward real length or a symbol.
    if (score === 4 && !(value.length >= 12 || /[^A-Za-z0-9]/.test(value))) {
        score = 3;
    }

    return Math.min(score, 4);
});

const STRENGTH_STYLES = [
    { label: 'Enter a password', bar: 'bg-ink-200', text: 'text-ink-400' },
    { label: 'Very weak', bar: 'bg-danger-500', text: 'text-danger-600' },
    { label: 'Weak', bar: 'bg-danger-500', text: 'text-danger-600' },
    { label: 'Good', bar: 'bg-warn-500', text: 'text-warn-700' },
    { label: 'Strong', bar: 'bg-success-500', text: 'text-success-700' },
];

const strengthStyle = computed(() => STRENGTH_STYLES[strength.value] ?? STRENGTH_STYLES[0]);

const confirmationMismatch = computed(
    () =>
        form.password_confirmation.length > 0 &&
        form.password !== form.password_confirmation,
);

const canSubmit = computed(
    () =>
        !form.processing &&
        form.current_password.length > 0 &&
        metCount.value === requirements.value.length &&
        !confirmationMismatch.value &&
        form.password_confirmation.length > 0,
);

function submit() {
    form.put(route('password.update'), {
        onFinish: () => form.reset('current_password', 'password', 'password_confirmation'),
    });
}

async function signOut() {
    if (await confirmLogout()) {
        router.post(route('logout'));
    }
}
</script>

<template>
    <AuthLayout
        title="Change password"
        :heading="mustChange ? 'Choose a new password' : 'Change your password'"
        :subheading="`Signed in as ${user.name} (@${user.username}).`"
        :show-back-link="false"
    >
        <div class="space-y-5">
            <Alert
                v-if="mustChange"
                variant="warning"
                title="This step is required"
            >
                Your account is still using the password it was created with, which
                is shared knowledge and not tied to you. Nothing else in the admin
                area will open until you replace it with one only you know.
            </Alert>

            <Alert v-else variant="brand" title="Keeping your account yours">
                Changing your password signs you back in on this device and leaves
                the audit trail a record of when it happened.
            </Alert>

            <form class="space-y-5" novalidate @submit.prevent="submit">
                <FormInput
                    v-model="form.current_password"
                    label="Current password"
                    type="password"
                    :icon="KeyRound"
                    autocomplete="current-password"
                    :error="form.errors.current_password"
                    :disabled="form.processing"
                    hint="Confirms it is really you making the change."
                    required
                    autofocus
                />

                <div class="border-t border-ink-200/70 pt-5">
                    <FormInput
                        v-model="form.password"
                        label="New password"
                        type="password"
                        :icon="ShieldCheck"
                        autocomplete="new-password"
                        :error="form.errors.password"
                        :disabled="form.processing"
                        required
                    />

                    <!-- Strength meter -->
                    <div class="mt-3">
                        <div class="flex items-center justify-between gap-3">
                            <div
                                class="flex h-1.5 flex-1 gap-1"
                                role="progressbar"
                                :aria-valuenow="strength"
                                aria-valuemin="0"
                                aria-valuemax="4"
                                :aria-label="`Password strength: ${strengthStyle.label}`"
                            >
                                <span
                                    v-for="step in 4"
                                    :key="step"
                                    :class="[
                                        'h-full flex-1 rounded-full transition-colors duration-200 ease-[var(--ease-out-soft)]',
                                        step <= strength ? strengthStyle.bar : 'bg-ink-200',
                                    ]"
                                />
                            </div>
                            <span
                                :class="['shrink-0 text-xs font-semibold', strengthStyle.text]"
                                aria-live="polite"
                            >
                                {{ strengthStyle.label }}
                            </span>
                        </div>

                        <ul class="mt-3 space-y-1.5">
                            <li
                                v-for="requirement in requirements"
                                :key="requirement.label"
                                :class="[
                                    'flex items-center gap-2 text-xs transition-colors duration-200',
                                    requirement.met ? 'text-success-700' : 'text-ink-500',
                                ]"
                            >
                                <component
                                    :is="requirement.met ? Check : X"
                                    :size="14"
                                    :stroke-width="2.5"
                                    :class="['shrink-0', requirement.met ? 'text-success-600' : 'text-ink-300']"
                                    aria-hidden="true"
                                />
                                {{ requirement.label }}
                            </li>
                        </ul>
                    </div>
                </div>

                <FormInput
                    v-model="form.password_confirmation"
                    label="Confirm new password"
                    type="password"
                    :icon="ShieldCheck"
                    autocomplete="new-password"
                    :error="
                        form.errors.password_confirmation ??
                        (confirmationMismatch ? 'The two new passwords do not match.' : null)
                    "
                    :disabled="form.processing"
                    required
                />

                <Button
                    type="submit"
                    size="lg"
                    block
                    :loading="form.processing"
                    :disabled="!canSubmit"
                >
                    <template #icon>
                        <ShieldCheck :size="18" aria-hidden="true" />
                    </template>
                    {{ form.processing ? 'Updating…' : 'Update password' }}
                </Button>
            </form>
        </div>

        <template #footer>
            <button
                type="button"
                class="mx-auto flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-ink-500 transition-colors hover:text-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                @click="signOut"
            >
                <LogOut :size="14" aria-hidden="true" />
                Sign out instead
            </button>
        </template>
    </AuthLayout>
</template>
