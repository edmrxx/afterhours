<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { Menu, X } from '@lucide/vue';
import { useSwal } from '@/Composables/useSwal';

/*
| PublicLayout — the customer-facing shell.
|
| Bold, court-side and mobile-first: a logo-left / links-right top bar over a
| warm ivory page, and a dark navy footer. No sidebar, no admin chrome. Flash
| messages are handled here the same way AppLayout handles them, so public
| pages stay declarative too.
|
| LOGO PLACEMENT: the wordmark artwork is light (ivory lettering, sapphire
| paddle) and was drawn for dark backgrounds. On the ivory header it therefore
| sits inside a NAVY ROUNDED PLATE; in the navy footer it is placed bare. The
| artwork itself is never recoloured or inverted.
|
|   <PublicLayout title="Book a court">…</PublicLayout>
*/

defineProps({
    title: { type: String, default: null },
    /** Constrain the content column. Set false for full-bleed hero pages. */
    contained: { type: Boolean, default: true },
});

const page = usePage();
const { toastSuccess, toastError, toastWarning, toastInfo } = useSwal();

const menuOpen = ref(false);

const appName = computed(() => page.props.appName ?? 'The Paddle Room');

const links = computed(() => [
    { label: 'Reserve', href: route('public.courts.index') },
    { label: 'Find booking', href: route('public.booking.lookup') },
    { label: 'Staff', href: route('login') },
]);

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

watch(
    () => page.url,
    () => {
        menuOpen.value = false;
    },
);

const year = new Date().getFullYear();
</script>

<template>
    <Head :title="title ?? undefined" />

    <div class="flex min-h-dvh flex-col bg-ivory-100 text-navy-900">
        <!-- Header -->
        <header
            class="sticky top-0 z-40 border-b border-ivory-300/70 bg-ivory-50/90 backdrop-blur-md"
        >
            <div class="mx-auto flex h-16 w-full max-w-6xl items-center gap-4 px-4 sm:px-6">
                <Link
                    :href="route('public.courts.index')"
                    class="flex min-w-0 items-center gap-2.5 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-900"
                >
                    <!-- Light-locked artwork on a light surface: navy plate. -->
                    <span
                        class="inline-flex shrink-0 items-center justify-center rounded-xl bg-navy-900 px-2.5 py-2 shadow-card"
                    >
                        <img
                            src="/images/brand/logo-mark.png"
                            alt="The Paddle Room"
                            width="231"
                            height="178"
                            class="h-6 w-auto"
                        />
                    </span>
                    <span class="sr-only">{{ appName }}</span>
                </Link>

                <nav class="ml-auto hidden items-center gap-1 sm:flex" aria-label="Primary">
                    <Link
                        v-for="link in links"
                        :key="link.href"
                        :href="link.href"
                        class="rounded-lg px-3 py-2 text-sm font-semibold tracking-wide text-navy-700 uppercase transition-colors hover:bg-taupe-100 hover:text-navy-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-900"
                    >
                        {{ link.label }}
                    </Link>
                </nav>

                <button
                    type="button"
                    class="ml-auto inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-navy-700 transition-colors hover:bg-taupe-100 hover:text-navy-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-900 sm:hidden"
                    :aria-expanded="menuOpen"
                    aria-label="Toggle menu"
                    @click="menuOpen = !menuOpen"
                >
                    <component :is="menuOpen ? X : Menu" :size="20" aria-hidden="true" />
                </button>
            </div>

            <!-- Mobile menu -->
            <Transition
                enter-active-class="transition duration-200 ease-[var(--ease-out-soft)]"
                enter-from-class="opacity-0 -translate-y-2"
                leave-active-class="transition duration-150 ease-[var(--ease-out-soft)]"
                leave-to-class="opacity-0"
            >
                <nav
                    v-if="menuOpen"
                    class="border-t border-ivory-300/70 bg-ivory-50 px-4 py-3 sm:hidden"
                    aria-label="Primary mobile"
                >
                    <Link
                        v-for="link in links"
                        :key="link.href"
                        :href="link.href"
                        class="block rounded-lg px-3 py-2.5 text-sm font-semibold tracking-wide text-navy-700 uppercase transition-colors hover:bg-taupe-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-900"
                    >
                        {{ link.label }}
                    </Link>
                </nav>
            </Transition>
        </header>

        <!-- Page -->
        <main class="flex-1">
            <div
                :class="
                    contained
                        ? 'mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 sm:py-12'
                        : 'w-full'
                "
            >
                <slot />
            </div>
        </main>

        <!-- Footer -->
        <footer class="border-t border-navy-900 bg-navy-900 text-ivory-300">
            <div
                class="mx-auto flex w-full max-w-6xl flex-col gap-5 px-4 py-8 text-xs sm:px-6"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <!-- Dark surface: the mark is placed bare, no plate. -->
                    <Link
                        :href="route('public.courts.index')"
                        class="inline-flex rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-taupe-400"
                    >
                        <img
                            src="/images/brand/logo-mark.png"
                            alt="The Paddle Room"
                            width="231"
                            height="178"
                            class="h-9 w-auto"
                        />
                        <span class="sr-only">{{ appName }}</span>
                    </Link>

                    <div class="flex flex-wrap items-center gap-4">
                        <Link
                            v-for="link in links"
                            :key="link.href"
                            :href="link.href"
                            class="rounded font-semibold tracking-wide text-ivory-300 uppercase transition-colors hover:text-taupe-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-taupe-400"
                        >
                            {{ link.label }}
                        </Link>
                    </div>
                </div>

                <p class="border-t border-taupe-500/20 pt-4 text-ivory-300/70">
                    &copy; {{ year }} {{ appName }}. All rights reserved.
                </p>
            </div>
        </footer>
    </div>
</template>
