<script setup>
import { computed, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { ArrowLeft } from '@lucide/vue';
import { useSwal } from '@/Composables/useSwal';

/*
| AuthLayout — a centred single-card shell on a deep navy brand backdrop.
| Used by login and the forced password change.
|
| The backdrop is navy-900 (#112250) because the wordmark artwork is light
| (ivory lettering, sapphire paddle) and was drawn for dark surfaces — on navy
| the mark can be used bare, with no plate behind it.
|
|   <AuthLayout title="Sign in" heading="Welcome back"
|               subheading="Sign in to manage courts and bookings">
|       …form…
|   </AuthLayout>
*/

defineProps({
    title: { type: String, default: null },
    heading: { type: String, default: 'Welcome back' },
    subheading: { type: String, default: null },
    /** Show the "Back to the public site" link under the card. */
    showBackLink: { type: Boolean, default: true },
});

const page = usePage();
const { toastSuccess, toastError, toastWarning, toastInfo } = useSwal();

const appName = computed(() => page.props.appName ?? 'The Paddle Room');

watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) {
            return;
        }

        if (flash.success) toastSuccess(flash.success);
        if (flash.error) toastError(flash.error);
        if (flash.warning) toastWarning(flash.warning);
        if (flash.info) toastInfo(flash.info);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <Head :title="title ?? undefined" />

    <div class="relative flex min-h-dvh flex-col items-center justify-center overflow-hidden bg-navy-900 px-4 py-10 sm:px-6">
        <!-- Navy backdrop: sapphire glow above, taupe warmth below. -->
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div
                class="absolute -top-40 left-1/2 h-[34rem] w-[34rem] -translate-x-1/2 rounded-full bg-sapphire-500/40 blur-3xl"
            />
            <div
                class="absolute -right-24 -bottom-32 h-[26rem] w-[26rem] rounded-full bg-sapphire-400/15 blur-3xl"
            />
            <div
                class="absolute -bottom-40 -left-20 h-[22rem] w-[22rem] rounded-full bg-taupe-500/10 blur-3xl"
            />
        </div>

        <div class="w-full max-w-md">
            <!-- Brand lockup: the mark sits bare, straight on the navy backdrop. -->
            <div class="mb-8 flex flex-col items-center text-center">
                <img
                    src="/images/brand/logo-mark.png"
                    :alt="appName"
                    width="231"
                    height="178"
                    class="h-20 w-auto sm:h-24"
                />
                <p class="mt-4 text-[11px] font-semibold tracking-[0.18em] text-taupe-500 uppercase">
                    Staff sign in
                </p>
            </div>

            <!-- Card -->
            <div class="rounded-2xl border border-taupe-500/15 bg-white p-6 shadow-modal sm:p-8">
                <header v-if="heading || subheading || $slots.heading" class="mb-6">
                    <slot name="heading">
                        <h1 class="text-xl font-semibold tracking-tight text-ink-900">
                            {{ heading }}
                        </h1>
                        <p v-if="subheading" class="mt-1.5 text-sm leading-relaxed text-ink-500">
                            {{ subheading }}
                        </p>
                    </slot>
                </header>

                <slot />

                <footer v-if="$slots.footer" class="mt-6 border-t border-ink-200/70 pt-5">
                    <slot name="footer" />
                </footer>
            </div>

            <p v-if="showBackLink" class="mt-7 text-center text-xs text-ivory-300/70">
                <Link
                    :href="route('public.courts.index')"
                    class="inline-flex items-center gap-1.5 rounded px-1 py-0.5 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-taupe-500"
                >
                    <ArrowLeft :size="14" aria-hidden="true" />
                    Back to {{ appName }}
                </Link>
            </p>
        </div>
    </div>
</template>
