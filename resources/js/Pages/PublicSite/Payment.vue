<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    ArrowLeft,
    CalendarDays,
    Check,
    Clock,
    Copy,
    Hourglass,
    LoaderCircle,
    Mail,
    Phone,
    QrCode,
    ShieldCheck,
    Smartphone,
    TriangleAlert,
} from '@lucide/vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import FormFileUpload from '@/Components/FormFileUpload.vue';
import ProgressSteps from '@/Components/ProgressSteps.vue';
import { useSwal } from '@/Composables/useSwal';

/*
| PublicSite/Payment — step 3 of the funnel.
|
| This is a MANUAL verification flow, not a live payment gateway: the customer
| pays externally in whichever app they scanned, then uploads a screenshot of
| the receipt as proof. There is no automatic detection and no poller here —
| our team matches the payment by hand, which is why the copy never implies
| otherwise.
|
| The site may publish more than one QR (GCash, GoTyme), so the chosen method
| still travels with the submission — without it staff would not know which
| account to check the payment against.
|
| The countdown is driven off the server's clock (`serverTime`) rather than the
| device's, so a phone with a skewed clock cannot show a reassuring 20 minutes
| that the backend has already expired.
*/

const props = defineProps({
    booking: { type: Object, required: true },
    /**
     * {
     *   methods: [{ key, label, qr_url, account_name, account_number,
     *               account_number_label, scan_hint }],
     *   instructions, support_phone, support_email
     * }
     */
    payment: { type: Object, default: () => ({}) },
    serverTime: { type: String, default: null },
});

const { toastSuccess, toastError, confirmAction } = useSwal();

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const amount = computed(() => peso.format(Number(props.booking.amount ?? 0)));

// The controller ships only the methods it has enough settings for, GCash
// first: an empty list means nothing is published yet, and a single entry
// means this site never set up a second wallet — so there is nothing to
// choose between and the toggle stays off the page entirely.
const methods = computed(() => props.payment.methods ?? []);

/* ── Hold countdown, anchored to the server clock ───────────────────── */

const skewMs = props.serverTime ? Date.now() - Date.parse(props.serverTime) : 0;
const expiresAtMs = props.booking.hold_expires_at
    ? Date.parse(props.booking.hold_expires_at)
    : null;

const remainingMs = ref(0);
let ticker = null;

function recompute() {
    if (expiresAtMs === null) {
        remainingMs.value = 0;

        return;
    }

    remainingMs.value = Math.max(0, expiresAtMs - (Date.now() - skewMs));
}

onMounted(() => {
    recompute();
    ticker = window.setInterval(recompute, 1000);
});

onBeforeUnmount(() => {
    if (ticker !== null) {
        window.clearInterval(ticker);
    }
});

// Merges the client-side countdown with the server's own verdict, so a
// booking that arrives here with `can_pay: false` (an edge case the
// controller does not expect, but a defensive UI should still cover) is
// treated exactly the same as a hold that just ticked down to zero.
const expired = computed(
    () => props.booking.can_pay === false || (expiresAtMs !== null && remainingMs.value <= 0),
);

const countdown = computed(() => {
    const total = Math.floor(remainingMs.value / 1000);
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
});

/** Under five minutes the timer earns the customer's attention. */
const urgent = computed(() => !expired.value && remainingMs.value <= 5 * 60 * 1000);

const holdTone = computed(() => (expired.value ? 'expired' : urgent.value ? 'urgent' : 'calm'));

// The calm state is brand chrome (noir band, ash accent); urgent/expired
// deliberately stay on the warn/danger semantics so the customer can read the
// timer's seriousness at a glance rather than decoding the brand palette.
const HOLD_WRAPPER = {
    calm: 'bg-noir-900 ring-1 ring-inset ring-graphite-700',
    urgent: 'bg-warn-50 ring-1 ring-inset ring-warn-500/25',
    expired: 'bg-danger-50 ring-1 ring-inset ring-danger-500/20',
};

const HOLD_ICON = {
    calm: 'bg-ash-500/15 text-ash-500',
    urgent: 'bg-white text-warn-700 shadow-card',
    expired: 'bg-white text-danger-600 shadow-card',
};

const HOLD_TITLE = {
    calm: 'text-bone-50',
    urgent: 'text-warn-700',
    expired: 'text-danger-700',
};

const HOLD_BODY = {
    calm: 'text-bone-300',
    urgent: 'text-warn-700/90',
    expired: 'text-danger-700/90',
};

const HOLD_NUMBER = {
    calm: 'text-ash-500',
    urgent: 'text-warn-700',
    expired: 'text-danger-700',
};

/* ── Copy helpers ───────────────────────────────────────────────────── */

const copied = ref(null);

async function copy(value, key) {
    if (!value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(String(value));
        copied.value = key;
        toastSuccess('Copied to your clipboard.');
        window.setTimeout(() => {
            if (copied.value === key) {
                copied.value = null;
            }
        }, 2000);
    } catch {
        toastError('Your browser blocked copying. Please select the text manually.');
    }
}

/* ── Proof-of-payment submission ─────────────────────────────────────── */

const form = useForm({
    payment_proof: null,
    // Pre-selected rather than left blank: the overwhelming case is a site with
    // one method, where asking the customer to "choose" would be noise. Blank
    // only when nothing is published at all — the server does not ask for a
    // method it gave the page no way to render a chooser for, so a payment
    // taken over the phone still submits.
    payment_method: props.payment.methods?.[0]?.key ?? '',
});

// Resolved from the form rather than held separately, and the fallback covers
// the settings changing under a customer who already had the page open — an
// admin can unpublish a wallet mid-session, and a validation redirect refreshes
// these props while Inertia keeps this component (and its form state) mounted.
const selectedMethod = computed(
    () =>
        methods.value.find((method) => method.key === form.payment_method) ??
        methods.value[0] ??
        null,
);

// The fallback above only decides what is *drawn*; without this it would leave
// the POST still naming the wallet that just disappeared, so the guest would
// scan the GCash QR on screen and have the booking filed as GoTyme. Writing the
// resolved key back is what actually makes "what the QR shows and what the POST
// carries can never drift apart" true. No loop: once written, the computed
// resolves to the same entry and the watcher stops.
watch(
    selectedMethod,
    (method) => {
        const key = method?.key ?? '';

        if (form.payment_method !== key) {
            form.payment_method = key;
        }
    },
    { immediate: true },
);

/** A real choice to make — or an error the guest needs a control to answer. */
const showMethodChooser = computed(
    () =>
        methods.value.length > 1 ||
        (methods.value.length > 0 && Boolean(form.errors.payment_method)),
);

function selectMethod(key) {
    form.payment_method = key;
    form.clearErrors('payment_method');
    // The tick sits beside a value that is about to be swapped out, so it would
    // otherwise claim the incoming account number had just been copied.
    copied.value = null;
}

/** Copy that used to hard-code "GCash" follows the choice instead. With nothing
 *  configured there is no brand to name, so it degrades to neutral wording. */
const methodLabel = computed(() => selectedMethod.value?.label ?? null);

const appLabel = computed(() => methodLabel.value ?? 'payment');

function submit() {
    form.post(route('public.booking.payment.store', { booking: props.booking.code }), {
        forceFormData: true,
        preserveScroll: true,
    });
}

/* ── Cancel ─────────────────────────────────────────────────────────── */

const cancelling = ref(false);

async function cancelBooking() {
    const confirmed = await confirmAction({
        title: 'Release this slot?',
        text: 'Your hold will be cancelled and the time goes back on the schedule for someone else.',
        confirmText: 'Yes, cancel it',
        cancelText: 'Keep my hold',
        variant: 'danger',
    });

    if (!confirmed) {
        return;
    }

    router.post(
        route('public.booking.cancel', { booking: props.booking.code }),
        {},
        {
            onStart: () => {
                cancelling.value = true;
            },
            onFinish: () => {
                cancelling.value = false;
            },
        },
    );
}

const wizardSteps = [
    { label: 'Pick a slot' },
    { label: 'Your details' },
    { label: 'Send payment' },
    { label: 'Confirmed' },
];

const CTA_BASE =
    'inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold shadow-card transition-colors duration-200 ease-[var(--ease-out-soft)] hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-55';

/** Primary action: noir fill, white text (21:1); the graphite hover holds 9.0:1. */
const primaryCta = `${CTA_BASE} bg-brand-600 text-white hover:bg-brand-500`;

/** Secondary action: ash fill with NOIR text — white on ash-500 is ~1.9:1 and
 *  fails WCAG, so the ash surface always carries noir lettering. */
const ashCta = `${CTA_BASE} bg-ash-500 text-noir-900 hover:bg-ash-600`;
</script>

<template>
    <PublicLayout :title="`Pay for ${booking.code}`">
        <div class="mx-auto w-full max-w-4xl">
            <Link
                v-if="booking.court_slug"
                :href="route('public.courts.show', { court: booking.court_slug })"
                class="inline-flex w-fit items-center gap-1.5 rounded-lg text-sm font-medium text-noir-500 transition-colors hover:text-noir-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
            >
                <ArrowLeft :size="16" aria-hidden="true" />
                Back to {{ booking.court_name }}
            </Link>

            <div
                class="mt-4 rounded-xl border border-bone-300/70 bg-white px-5 py-5 shadow-card sm:px-8"
            >
                <ProgressSteps :steps="wizardSteps" :current="3" compact />
            </div>

            <!-- ── Hold countdown ─────────────────────────────────────── -->
            <div
                :class="[
                    'mt-5 flex flex-wrap items-center gap-4 rounded-xl p-5 transition-colors duration-300 ease-[var(--ease-out-soft)]',
                    HOLD_WRAPPER[holdTone],
                ]"
                role="timer"
                aria-live="off"
            >
                <span
                    :class="[
                        'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl',
                        HOLD_ICON[holdTone],
                    ]"
                    aria-hidden="true"
                >
                    <Hourglass :size="20" />
                </span>

                <div class="min-w-0 flex-1">
                    <p :class="['text-sm font-semibold', HOLD_TITLE[holdTone]]">
                        {{ expired ? 'Your hold has expired' : 'Your slot is being held' }}
                    </p>
                    <p :class="['mt-0.5 text-sm', HOLD_BODY[holdTone]]">
                        {{
                            expired
                                ? 'The slot has been released. Please pick a new time to book again.'
                                : 'Send your payment and upload your receipt before the timer runs out.'
                        }}
                    </p>
                </div>

                <p
                    v-if="!expired"
                    :class="[
                        'shrink-0 font-mono text-2xl font-semibold tabular-nums sm:text-3xl',
                        HOLD_NUMBER[holdTone],
                    ]"
                >
                    {{ countdown }}
                </p>
            </div>

            <Alert
                v-if="expired"
                class="mt-4"
                variant="error"
                title="This booking can no longer be paid"
            >
                Holds last only as long as the timer so other players get a fair shot at the court.
                <template #actions>
                    <Link
                        :href="
                            booking.court_slug
                                ? route('public.courts.show', { court: booking.court_slug })
                                : route('public.courts.index')
                        "
                        :class="ashCta"
                    >
                        Book another time
                    </Link>
                </template>
            </Alert>

            <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-5">
                <!-- ── Left: how to pay ──────────────────────────────── -->
                <div class="lg:col-span-3">
                    <section
                        class="overflow-hidden rounded-xl border border-bone-300/70 bg-white shadow-card"
                    >
                        <!-- SCAN TO PAY hero -->
                        <div
                            class="bg-gradient-to-br from-noir-900 to-graphite-700 px-5 py-7 text-center sm:px-8 sm:py-9"
                        >
                            <!-- Dark surface: the white wordmark, sitting bare. -->
                            <img
                                src="/images/brand/logo-mark-light.png"
                                alt="After Hours"
                                width="960"
                                height="463"
                                class="mx-auto mb-4 h-auto w-24"
                            />

                            <p
                                class="font-display-heading text-xs tracking-[0.2em] text-ash-500"
                            >
                                Step 2 · {{ methodLabel ? `Pay via ${methodLabel}` : 'Send payment' }}
                            </p>
                            <h2
                                class="mt-2 font-display-heading text-2xl text-white sm:text-3xl"
                            >
                                Scan to pay {{ amount }}
                            </h2>
                            <p class="mx-auto mt-2 max-w-md text-sm text-bone-200">
                                Open your {{ appLabel }} app, scan the code below, and send the
                                exact amount so we can match it to your booking.
                            </p>
                        </div>

                        <div class="p-5 sm:p-6">
                            <!-- The toggle itself only appears when there is a real choice, so a
                                 GCash-only site looks exactly as it always has — unless the server
                                 raised a method error, in which case the buttons appear regardless
                                 so the error always has a control that can answer it. -->
                            <div
                                v-if="showMethodChooser || form.errors.payment_method"
                                class="mb-5"
                            >
                                <p
                                    v-if="showMethodChooser"
                                    id="payment-method-label"
                                    class="text-[11px] font-medium tracking-wide text-noir-500 uppercase"
                                >
                                    Pay with
                                </p>
                                <div
                                    v-if="showMethodChooser"
                                    class="mt-2 grid gap-2"
                                    :class="methods.length > 1 ? 'grid-cols-2' : 'grid-cols-1'"
                                    role="group"
                                    aria-labelledby="payment-method-label"
                                >
                                    <button
                                        v-for="method in methods"
                                        :key="method.key"
                                        type="button"
                                        :aria-pressed="method.key === selectedMethod?.key"
                                        :class="[
                                            'flex cursor-pointer items-center justify-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold',
                                            'transition-colors duration-200 ease-[var(--ease-out-soft)]',
                                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900',
                                            method.key === selectedMethod?.key
                                                ? 'border-noir-900 bg-noir-900 text-white shadow-card'
                                                : 'border-bone-300 bg-white text-noir-700 hover:border-noir-300 hover:shadow-card',
                                        ]"
                                        @click="selectMethod(method.key)"
                                    >
                                        <Check
                                            v-if="method.key === selectedMethod?.key"
                                            :size="15"
                                            aria-hidden="true"
                                        />
                                        {{ method.label }}
                                    </button>
                                </div>

                                <p
                                    v-if="form.errors.payment_method"
                                    class="mt-1.5 text-xs font-medium text-danger-600"
                                    role="alert"
                                >
                                    {{ form.errors.payment_method }}
                                </p>
                            </div>

                            <!-- QR -->
                            <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
                                <div class="mx-auto shrink-0 sm:mx-0">
                                    <div
                                        v-if="selectedMethod?.qr_url"
                                        class="rounded-xl border-2 border-ash-400 bg-white p-3 shadow-card"
                                    >
                                        <img
                                            :src="selectedMethod.qr_url"
                                            :alt="`${selectedMethod.label} QR code`"
                                            class="h-44 w-44 object-contain"
                                        />
                                        <p
                                            v-if="selectedMethod.scan_hint"
                                            class="mt-2 text-center text-[11px] text-noir-500"
                                        >
                                            {{ selectedMethod.scan_hint }}
                                        </p>
                                    </div>

                                    <!-- Graceful fallback when no QR is uploaded yet -->
                                    <div
                                        v-else
                                        class="flex h-50 w-50 flex-col items-center justify-center rounded-xl border-2 border-dashed border-noir-200 bg-bone-100/60 px-4 text-center"
                                    >
                                        <QrCode
                                            :size="26"
                                            :stroke-width="1.5"
                                            class="text-noir-400"
                                            aria-hidden="true"
                                        />
                                        <p class="mt-2 text-xs font-medium text-noir-700">
                                            No QR code yet
                                        </p>
                                        <p class="mt-1 text-[11px] leading-relaxed text-noir-500">
                                            Use the account details beside this box to send your
                                            payment.
                                        </p>
                                    </div>
                                </div>

                                <!-- Account details -->
                                <dl class="min-w-0 flex-1 space-y-3">
                                    <div
                                        v-if="selectedMethod?.account_name"
                                        class="rounded-lg border border-bone-300 bg-bone-100/60 p-3"
                                    >
                                        <dt class="text-[11px] tracking-wide text-noir-500 uppercase">
                                            Account name
                                        </dt>
                                        <dd
                                            class="mt-0.5 flex items-center justify-between gap-2 text-sm font-medium text-noir-900"
                                        >
                                            <span class="min-w-0 truncate">{{
                                                selectedMethod.account_name
                                            }}</span>
                                            <button
                                                type="button"
                                                class="shrink-0 cursor-pointer rounded-md p-1.5 text-noir-400 transition-colors hover:bg-white hover:text-graphite-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
                                                :aria-label="`Copy ${methodLabel} account name`"
                                                @click="copy(selectedMethod.account_name, 'name')"
                                            >
                                                <component
                                                    :is="copied === 'name' ? Check : Copy"
                                                    :size="15"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </dd>
                                    </div>

                                    <div
                                        v-if="selectedMethod?.account_number"
                                        class="rounded-lg border border-bone-300 bg-bone-100/60 p-3"
                                    >
                                        <dt class="text-[11px] tracking-wide text-noir-500 uppercase">
                                            {{ selectedMethod.account_number_label }}
                                        </dt>
                                        <dd
                                            class="mt-0.5 flex items-center justify-between gap-2 font-mono text-sm font-semibold tracking-wide text-noir-900"
                                        >
                                            <span class="min-w-0 truncate">{{
                                                selectedMethod.account_number
                                            }}</span>
                                            <button
                                                type="button"
                                                class="shrink-0 cursor-pointer rounded-md p-1.5 text-noir-400 transition-colors hover:bg-white hover:text-graphite-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
                                                :aria-label="`Copy ${selectedMethod.account_number_label}`"
                                                @click="copy(selectedMethod.account_number, 'number')"
                                            >
                                                <component
                                                    :is="copied === 'number' ? Check : Copy"
                                                    :size="15"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </dd>
                                    </div>

                                    <div class="rounded-lg border border-bone-300 bg-bone-100/60 p-3">
                                        <dt class="text-[11px] tracking-wide text-noir-500 uppercase">
                                            Booking code
                                        </dt>
                                        <dd
                                            class="mt-0.5 flex items-center justify-between gap-2 font-mono text-sm font-semibold tracking-wide text-graphite-500"
                                        >
                                            <span class="min-w-0 truncate">{{ booking.code }}</span>
                                            <button
                                                type="button"
                                                class="shrink-0 cursor-pointer rounded-md p-1.5 text-noir-400 transition-colors hover:bg-white hover:text-graphite-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
                                                aria-label="Copy booking code"
                                                @click="copy(booking.code, 'code')"
                                            >
                                                <component
                                                    :is="copied === 'code' ? Check : Copy"
                                                    :size="15"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            <!-- No method is publishable at all: every configured one needs a
                                 QR or an account number before it reaches this page. -->
                            <Alert
                                v-if="methods.length === 0"
                                class="mt-5"
                                variant="warning"
                                title="Payment details are not published yet"
                            >
                                Our payment details are still being set up. Please contact us and
                                we will take your payment directly — your slot stays held in the
                                meantime.

                                <template #actions>
                                    <Button
                                        v-if="payment.support_phone"
                                        as="a"
                                        :href="`tel:${payment.support_phone}`"
                                        size="sm"
                                        variant="secondary"
                                    >
                                        <template #icon>
                                            <Phone :size="14" aria-hidden="true" />
                                        </template>
                                        {{ payment.support_phone }}
                                    </Button>
                                    <Button
                                        v-if="payment.support_email"
                                        as="a"
                                        :href="`mailto:${payment.support_email}`"
                                        size="sm"
                                        variant="secondary"
                                    >
                                        <template #icon>
                                            <Mail :size="14" aria-hidden="true" />
                                        </template>
                                        {{ payment.support_email }}
                                    </Button>
                                </template>
                            </Alert>

                            <!-- Free-text instructions from settings -->
                            <div
                                v-if="payment.instructions"
                                class="mt-5 rounded-lg border border-bone-300 bg-white p-4"
                            >
                                <h3
                                    class="flex items-center gap-2 text-xs font-semibold tracking-wide text-noir-500 uppercase"
                                >
                                    <Smartphone :size="14" aria-hidden="true" />
                                    Payment instructions
                                </h3>
                                <p
                                    class="mt-2 text-sm leading-relaxed whitespace-pre-line text-noir-700"
                                >
                                    {{ payment.instructions }}
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- ── Proof of payment form ─────────────────────── -->
                    <section
                        class="mt-5 overflow-hidden rounded-xl border-2 border-ash-400 bg-white shadow-card"
                    >
                        <header class="border-b border-ash-200 bg-ash-50 px-5 py-4 sm:px-6">
                            <h2
                                class="font-display-heading text-sm tracking-wide text-noir-900"
                            >
                                Upload your {{ appLabel }} receipt
                            </h2>
                            <p class="mt-0.5 flex items-start gap-1.5 text-xs text-noir-600">
                                <ShieldCheck
                                    :size="14"
                                    class="mt-0.5 shrink-0 text-graphite-500"
                                    aria-hidden="true"
                                />
                                Already sent your payment? Upload a screenshot of your
                                {{ appLabel }} receipt below. Our team checks every payment by
                                hand — this is not automatic.
                            </p>
                        </header>

                        <form class="p-5 sm:p-6" @submit.prevent="submit">
                            <FormFileUpload
                                v-model="form.payment_proof"
                                label="Screenshot of your receipt"
                                hint="A clear screenshot helps our team verify your payment quickly."
                                accept="image/png,image/jpeg,image/webp"
                                :max-size="4"
                                required
                                :disabled="expired"
                                :progress="form.progress?.percentage ?? null"
                                :error="form.errors.payment_proof"
                            />

                            <button
                                type="submit"
                                :class="[primaryCta, 'mt-6 w-full']"
                                :disabled="expired || form.processing"
                            >
                                <LoaderCircle
                                    v-if="form.processing"
                                    class="animate-spin"
                                    :size="18"
                                    aria-hidden="true"
                                />
                                Submit payment details
                            </button>

                            <p class="mt-3 text-center text-xs leading-relaxed text-noir-500">
                                We hold your slot while our team verifies the payment, and text
                                you the moment it is confirmed.
                            </p>
                        </form>
                    </section>
                </div>

                <!-- ── Right: booking summary ────────────────────────── -->
                <aside class="lg:col-span-2">
                    <div
                        class="overflow-hidden rounded-xl border border-bone-300/70 bg-white shadow-card lg:sticky lg:top-24"
                    >
                        <header class="border-b border-bone-300/70 px-5 py-4">
                            <h2 class="font-display-heading text-sm text-noir-900">
                                Your booking
                            </h2>
                        </header>

                        <dl class="divide-y divide-bone-300/70">
                            <div class="px-5 py-3.5">
                                <dt class="text-xs text-noir-500">Booking code</dt>
                                <dd
                                    class="mt-0.5 font-mono text-base font-semibold tracking-wide text-graphite-500"
                                >
                                    {{ booking.code }}
                                </dd>
                            </div>

                            <div class="px-5 py-3.5">
                                <dt class="text-xs text-noir-500">
                                    {{ booking.is_multi_court ? 'Courts' : 'Court' }}
                                </dt>
                                <!-- A booking may span more than one court; the
                                     guest is paying for all of them, so name
                                     every one. Single-court is unchanged. -->
                                <dd class="mt-0.5 text-sm font-medium text-noir-900">
                                    {{ booking.is_multi_court ? booking.court_names.join(', ') : booking.court_name }}
                                </dd>
                            </div>

                            <div class="px-5 py-3.5">
                                <dt class="flex items-center gap-1.5 text-xs text-noir-500">
                                    <CalendarDays :size="13" aria-hidden="true" />
                                    Date
                                </dt>
                                <dd class="mt-0.5 text-sm font-medium text-noir-900">
                                    {{ booking.date ?? '—' }}
                                </dd>
                            </div>

                            <div class="px-5 py-3.5">
                                <dt class="flex items-center gap-1.5 text-xs text-noir-500">
                                    <Clock :size="13" aria-hidden="true" />
                                    Time
                                </dt>
                                <!-- Each line names its court only when the
                                     booking spans several, so a cross-court
                                     guest can tell which time is which court.
                                     Single-court shows the bare time as before;
                                     the key carries the court so two courts at
                                     the same time never collide. -->
                                <dd v-if="booking.slot_count > 1" class="mt-0.5 space-y-0.5">
                                    <p
                                        v-for="(slot, index) in booking.slots"
                                        :key="`${index}-${slot.time_range}`"
                                        class="text-sm font-medium text-noir-900"
                                    >
                                        {{ booking.is_multi_court ? `${slot.court_name} · ${slot.time_range}` : slot.time_range }}
                                    </p>
                                </dd>
                                <dd v-else class="mt-0.5 text-sm font-medium text-noir-900">
                                    {{ booking.time_range ?? '—' }}
                                    <span v-if="booking.duration_minutes" class="text-noir-500">
                                        · {{ booking.duration_minutes }} min
                                    </span>
                                </dd>
                            </div>

                            <div class="px-5 py-3.5">
                                <dt class="text-xs text-noir-500">Booked for</dt>
                                <dd class="mt-0.5 text-sm font-medium text-noir-900">
                                    {{ booking.customer_name }}
                                </dd>
                                <dd class="mt-0.5 text-xs text-noir-500">
                                    {{ booking.customer_phone }}
                                </dd>
                            </div>

                            <div class="flex items-center justify-between bg-bone-100/60 px-5 py-4">
                                <dt class="text-sm font-medium text-noir-700">Total</dt>
                                <dd class="text-lg font-semibold tabular-nums text-noir-900">
                                    {{ amount }}
                                </dd>
                            </div>
                        </dl>

                        <div class="border-t border-bone-300/70 p-4">
                            <Button
                                variant="ghost"
                                size="sm"
                                block
                                :loading="cancelling"
                                @click="cancelBooking"
                            >
                                Cancel this booking
                            </Button>
                            <p class="mt-2 text-center text-[11px] leading-relaxed text-noir-400">
                                Changed your mind? Release the slot so another player can take it.
                            </p>
                        </div>
                    </div>

                    <p
                        class="mt-4 flex items-start gap-2 px-1 text-xs leading-relaxed text-noir-500"
                    >
                        <TriangleAlert
                            :size="14"
                            class="mt-0.5 shrink-0 text-noir-400"
                            aria-hidden="true"
                        />
                        Keep your booking code. It is the only thing you need to check this
                        booking later.
                    </p>
                </aside>
            </div>
        </div>
    </PublicLayout>
</template>
