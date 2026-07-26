<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { ArrowRight, CalendarDays, CircleHelp, LoaderCircle, Search } from '@lucide/vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import FormInput from '@/Components/FormInput.vue';

/*
| PublicSite/Lookup — "check my booking".
|
| The booking code is the only credential on the public site, so this form is
| deliberately forgiving about how it is typed (case, spaces, missing prefix are
| all normalised server-side) and deliberately vague about failures: a wrong
| code and a non-existent code produce the same message, so the form can never
| be used to enumerate real bookings.
*/

const props = defineProps({
    codePrefix: { type: String, default: 'AH' },
});

const form = useForm({
    code: '',
});

const example = computed(() => `${props.codePrefix}-K7M4XQ9B`);

function submit() {
    // The code is deliberately kept on error — the customer usually mistyped a
    // character and should not have to retype the whole thing.
    form.post(route('public.booking.lookup.submit'), { preserveScroll: true });
}
</script>

<template>
    <PublicLayout title="Check my booking">
        <div class="mx-auto w-full max-w-lg">
            <!-- ── Dark noir hero band ─────────────────────────────────── -->
            <div
                class="overflow-hidden rounded-2xl bg-gradient-to-br from-noir-900 to-graphite-700 px-6 py-10 text-center shadow-float sm:px-10 sm:py-12"
            >
                <!-- Dark surface: the white wordmark, placed bare — no plate. -->
                <img
                    src="/images/brand/logo-mark-light.png"
                    alt="After Hours"
                    width="960"
                    height="463"
                    class="mx-auto h-auto w-32"
                />

                <p class="mt-5 font-display-heading text-xs tracking-[0.2em] text-ash-500">
                    Check my booking
                </p>
                <h1 class="mt-2 font-display-heading text-3xl text-white sm:text-4xl">
                    Find Your Reservation
                </h1>
                <p class="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-bone-200">
                    Enter the booking code we sent you. No account, no password — the code is all
                    you need.
                </p>
            </div>

            <!-- ── Form card ────────────────────────────────────────────── -->
            <div
                class="relative z-10 mx-4 -mt-6 rounded-2xl border border-bone-300/70 bg-white p-6 shadow-card sm:mx-6 sm:p-8"
            >
                <form @submit.prevent="submit">
                    <FormInput
                        v-model="form.code"
                        label="Booking code"
                        :placeholder="example"
                        :icon="Search"
                        size="lg"
                        required
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        class="font-mono tracking-wider uppercase"
                        :hint="`Looks like ${example}. Case does not matter.`"
                        :error="form.errors.code"
                    />

                    <button
                        type="submit"
                        class="mt-6 inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-noir-900 px-5 py-3 text-sm font-semibold text-white shadow-card transition-colors duration-200 ease-[var(--ease-out-soft)] hover:bg-noir-800 hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-55"
                        :disabled="form.processing"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="animate-spin"
                            :size="18"
                            aria-hidden="true"
                        />
                        Find my booking
                        <ArrowRight v-if="!form.processing" :size="16" aria-hidden="true" />
                    </button>
                </form>
            </div>

            <div class="mt-6 space-y-3 px-4 sm:px-6">
                <div
                    class="flex items-start gap-3 rounded-xl border border-bone-300/70 bg-white p-4"
                >
                    <CircleHelp
                        :size="17"
                        class="mt-0.5 shrink-0 text-noir-400"
                        aria-hidden="true"
                    />
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-noir-900">Lost your code?</p>
                        <p class="mt-0.5 text-sm leading-relaxed text-noir-500">
                            It is in the text message and email we sent when you booked, and in
                            the link you were given after paying.
                        </p>
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center gap-3 rounded-xl border border-bone-300/70 bg-white p-4"
                >
                    <CalendarDays
                        :size="17"
                        class="shrink-0 text-ash-600"
                        aria-hidden="true"
                    />
                    <p class="min-w-0 flex-1 text-sm text-noir-600">
                        Not booked yet? The schedule is open now.
                    </p>
                    <Link
                        :href="route('public.courts.index')"
                        class="inline-flex items-center gap-1.5 rounded-lg text-sm font-medium text-noir-900 transition-colors hover:text-graphite-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
                    >
                        Browse courts
                        <ArrowRight :size="14" aria-hidden="true" />
                    </Link>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
