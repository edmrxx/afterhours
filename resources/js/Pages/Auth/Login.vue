<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { ArrowRight, KeyRound, LogIn, Sparkles, User } from '@lucide/vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import Button from '@/Components/Button.vue';
import FormCheckbox from '@/Components/FormCheckbox.vue';
import FormInput from '@/Components/FormInput.vue';

/*
| Sign in.
|
| Flash messages are consumed by AuthLayout; validation errors surface inline
| through each field's `:error`. Nothing here re-handles either.
*/

const props = defineProps({
    /** True only in the local environment — gates the credentials hint. */
    isLocal: { type: Boolean, default: false },
    /** { username, password } in local, null everywhere else. */
    defaultCredentials: { type: Object, default: null },
});

const form = useForm({
    username: '',
    password: '',
    remember: false,
});

const showHint = computed(() => props.isLocal && Boolean(props.defaultCredentials));

function submit() {
    form.post(route('login.store'), {
        // Never leave a password sitting in reactive state after the round trip.
        onFinish: () => form.reset('password'),
    });
}

function fillDemoCredentials() {
    if (!props.defaultCredentials) {
        return;
    }

    form.clearErrors();
    form.username = props.defaultCredentials.username;
    form.password = props.defaultCredentials.password;
}
</script>

<template>
    <AuthLayout
        title="Sign in"
        heading="Welcome back"
        subheading="Sign in to manage courts, slots and bookings."
    >
        <!-- Animated brand rule: the only motion on an otherwise still card.
             Crimson → graphite → noir, the three circled brand colours, in
             order of decreasing lightness. Ash is deliberately not a stop: it
             is the logo's net grey, not one of the client's palette colours. -->
        <div
            class="-mt-2 mb-6 h-1 w-full animate-shimmer rounded-full bg-gradient-to-r from-brand-600 via-graphite-500 to-noir-900 bg-[length:200%_100%]"
            aria-hidden="true"
        />

        <form class="animate-fade-up space-y-5" novalidate @submit.prevent="submit">
            <FormInput
                v-model="form.username"
                label="Username"
                :icon="User"
                autocomplete="username"
                autocapitalize="none"
                autocorrect="off"
                spellcheck="false"
                placeholder="e.g. admin"
                :error="form.errors.username"
                :disabled="form.processing"
                required
                autofocus
            />

            <FormInput
                v-model="form.password"
                label="Password"
                type="password"
                :icon="KeyRound"
                autocomplete="current-password"
                placeholder="Your password"
                :error="form.errors.password"
                :disabled="form.processing"
                required
            />

            <div class="flex items-center justify-between gap-3">
                <FormCheckbox
                    v-model="form.remember"
                    label="Remember me"
                    :disabled="form.processing"
                />

                <p class="text-xs text-ink-400">
                    Trouble signing in? Ask an administrator.
                </p>
            </div>

            <Button
                type="submit"
                size="lg"
                block
                :loading="form.processing"
                :disabled="form.processing"
            >
                <template #icon>
                    <LogIn :size="18" aria-hidden="true" />
                </template>
                {{ form.processing ? 'Signing in…' : 'Sign in' }}
            </Button>
        </form>

        <!-- Local-only convenience. Never rendered in staging or production. -->
        <template v-if="showHint" #footer>
            <div class="rounded-xl bg-brand-50 p-4 ring-1 ring-brand-200 ring-inset">
                <div class="flex items-start gap-3">
                    <Sparkles :size="16" class="mt-0.5 shrink-0 text-brand-600" aria-hidden="true" />

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-brand-700">
                            Local development
                        </p>
                        <p class="mt-1 text-xs leading-relaxed text-ink-600">
                            Seeded account
                            <code class="rounded bg-white px-1.5 py-0.5 font-mono text-[11px] text-ink-800">
                                {{ defaultCredentials.username }}
                            </code>
                            /
                            <code class="rounded bg-white px-1.5 py-0.5 font-mono text-[11px] text-ink-800">
                                {{ defaultCredentials.password }}
                            </code>
                            — you will be asked to change it straight away.
                        </p>

                        <button
                            type="button"
                            class="mt-2.5 inline-flex cursor-pointer items-center gap-1 rounded-md px-1 py-0.5 text-xs font-semibold text-brand-700 transition-colors hover:text-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                            @click="fillDemoCredentials"
                        >
                            Fill them in
                            <ArrowRight :size="13" aria-hidden="true" />
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </AuthLayout>
</template>
