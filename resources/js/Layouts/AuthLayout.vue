<script setup>
import { computed, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { ArrowLeft } from '@lucide/vue';
import { useSwal } from '@/Composables/useSwal';

/*
| AuthLayout — a centred single-card shell on a near-black brand backdrop.
| Used by login and the forced password change.
|
| The backdrop is noir-900 (#000000). The default wordmark is crimson, drawn for
| light surfaces, so a dark field like this one takes the light variant of the
| artwork (logo-mark-light.png) — placed bare, with no plate behind it.
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

const appName = computed(() => page.props.appName ?? 'After Hours');

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

    <div class="relative flex min-h-dvh flex-col items-center justify-center overflow-hidden bg-noir-900 px-4 py-10 sm:px-6">
        <!-- Noir backdrop: graphite glow above, a soft ash wash below. -->
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div
                class="absolute -top-40 left-1/2 h-[34rem] w-[34rem] -translate-x-1/2 rounded-full bg-graphite-500/40 blur-3xl"
            />
            <div
                class="absolute -right-24 -bottom-32 h-[26rem] w-[26rem] rounded-full bg-graphite-400/15 blur-3xl"
            />
            <div
                class="absolute -bottom-40 -left-20 h-[22rem] w-[22rem] rounded-full bg-ash-500/10 blur-3xl"
            />
        </div>

        <div class="w-full max-w-md">
            <!-- Brand lockup: the light mark sits bare, straight on the noir backdrop. -->
            <div class="mb-8 flex flex-col items-center text-center">
                <img
                    src="/images/brand/logo-mark-light.png"
                    :alt="appName"
                    width="960"
                    height="463"
                    class="h-20 w-auto sm:h-24"
                />
                <p class="mt-4 text-[11px] font-semibold tracking-[0.18em] text-ash-500 uppercase">
                    Staff sign in
                </p>
            </div>

            <!-- Card -->
            <div class="rounded-2xl border border-ash-500/15 bg-white p-6 shadow-modal sm:p-8">
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

            <p v-if="showBackLink" class="mt-7 text-center text-xs text-bone-300/70">
                <Link
                    :href="route('public.courts.index')"
                    class="inline-flex items-center gap-1.5 rounded px-1 py-0.5 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ash-500"
                >
                    <ArrowLeft :size="14" aria-hidden="true" />
                    Back to {{ appName }}
                </Link>
            </p>
        </div>
    </div>
</template>
