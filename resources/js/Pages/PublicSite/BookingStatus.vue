<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    ArrowRight,
    CalendarDays,
    CircleAlert,
    CircleCheck,
    CircleX,
    Clock,
    Copy,
    Check,
    Hourglass,
    MapPin,
    Phone,
    Search,
    TicketCheck,
    Timer,
    Wallet,
} from '@lucide/vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Alert from '@/Components/Alert.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import ProgressSteps from '@/Components/ProgressSteps.vue';
import { useSwal } from '@/Composables/useSwal';

/*
| PublicSite/BookingStatus — the shareable receipt.
|
| One page per booking code, safe to forward to whoever is playing. It answers
| exactly three questions, in this order: where is my booking, what happens
| next, and what do I do about it.
*/

const props = defineProps({
    booking: { type: Object, required: true },
    /** Present only while the booking still needs paying. */
    payment: { type: Object, default: null },
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

/* ── Narrative per state ────────────────────────────────────────────── */

// The checkout can publish more than one QR, so the copy must not send a
// GoTyme payer looking in GCash. A booking that has not been paid yet, and any
// booking taken before we offered the choice, has no recorded method — those
// read as the neutral wording rather than naming a wallet we cannot vouch for.
const methodLabel = computed(() => props.booking.payment_method_label ?? null);

const paidWith = computed(() => methodLabel.value ?? 'your payment app');

/** "GCash reference" is a lie on a GoTyme booking, and on an old one we simply
 *  do not know which app issued the number. */
const referenceLabel = computed(() =>
    methodLabel.value ? `${methodLabel.value} reference` : 'Payment reference',
);

// Reactive rather than a module constant: two of the states name the payment
// app, which is only known per booking.
const narrative = computed(() => ({
    awaiting_payment: {
        icon: Wallet,
        tone: 'warn',
        kicker: 'Step 2 of 3',
        headline: 'Waiting for your payment',
        next: `Send the exact amount from ${paidWith.value}, then upload a screenshot of your receipt. We hold the slot until the timer runs out.`,
    },
    pending_verification: {
        icon: Hourglass,
        tone: 'info',
        kicker: 'Step 3 of 3',
        headline: 'We are checking your payment',
        next: methodLabel.value
            ? `Our team is checking your payment against our ${methodLabel.value} account by hand. You will get a text (and an email, if you gave us one) the moment it clears. Nothing else is needed from you.`
            : 'Our team is checking your payment against our records by hand. You will get a text (and an email, if you gave us one) the moment it clears. Nothing else is needed from you.',
    },
    confirmed: {
        icon: CircleCheck,
        tone: 'success',
        kicker: 'You’re booked in',
        headline: 'You are booked in',
        next: 'Show this booking code at the court when you arrive. Try to be there five minutes before your slot starts.',
    },
    completed: {
        icon: TicketCheck,
        tone: 'brand',
        kicker: 'Session complete',
        headline: 'Thanks for playing',
        next: 'This session is done and dusted. We hope it was a good one — book another any time.',
    },
    rejected: {
        icon: CircleX,
        tone: 'danger',
        kicker: 'Payment not verified',
        headline: 'We could not verify that payment',
        next: 'Your slot has gone back on the schedule. If you believe the payment went through, get in touch with a screenshot of your receipt and we will sort it out.',
    },
    expired: {
        icon: Timer,
        tone: 'ink',
        kicker: 'Hold released',
        headline: 'This hold ran out',
        next: 'The slot was released so other players could book it. Nothing was charged — you are free to book a new time.',
    },
    cancelled: {
        icon: CircleX,
        tone: 'ink',
        kicker: 'Booking cancelled',
        headline: 'This booking was cancelled',
        next: 'The slot is back on the schedule. Book another time whenever you are ready.',
    },
}));

const story = computed(
    () =>
        narrative.value[props.booking.status] ?? {
            icon: CircleAlert,
            tone: 'ink',
            kicker: 'Booking status',
            headline: props.booking.status_label,
            next: 'Get in touch if anything looks wrong with this booking.',
        },
);

const HERO_TONES = {
    success: 'bg-success-50 ring-success-500/20',
    warn: 'bg-warn-50 ring-warn-500/25',
    danger: 'bg-danger-50 ring-danger-500/20',
    info: 'bg-info-50 ring-info-500/20',
    brand: 'bg-gradient-to-br from-noir-900 to-graphite-700 ring-noir-900/10',
    ink: 'bg-noir-50 ring-noir-100',
};

const HERO_ICONS = {
    success: 'bg-white text-success-600',
    warn: 'bg-white text-warn-600',
    danger: 'bg-white text-danger-600',
    info: 'bg-white text-info-600',
    brand: 'bg-ash-500/15 text-ash-500',
    ink: 'bg-white text-noir-500',
};

/** Headline/kicker/body text colour flips to a light palette on the dark
 * "completed" hero; every other tone sits on a light tint and stays dark. */
const HERO_TEXT = {
    success: { kicker: 'text-success-700', headline: 'text-noir-900', body: 'text-noir-600' },
    warn: { kicker: 'text-warn-700', headline: 'text-noir-900', body: 'text-noir-600' },
    danger: { kicker: 'text-danger-700', headline: 'text-noir-900', body: 'text-noir-600' },
    info: { kicker: 'text-info-600', headline: 'text-noir-900', body: 'text-noir-600' },
    brand: { kicker: 'text-ash-500', headline: 'text-white', body: 'text-bone-200' },
    ink: { kicker: 'text-noir-500', headline: 'text-noir-900', body: 'text-noir-600' },
};

const heroText = computed(() => HERO_TEXT[story.value.tone] ?? HERO_TEXT.ink);

const isOpen = computed(() =>
    ['awaiting_payment', 'pending_verification'].includes(props.booking.status),
);

const isSettled = computed(() => ['confirmed', 'completed'].includes(props.booking.status));

/* ── Live hold countdown ────────────────────────────────────────────── */

const skewMs = props.serverTime ? Date.now() - Date.parse(props.serverTime) : 0;
const expiresAtMs = props.booking.hold_expires_at
    ? Date.parse(props.booking.hold_expires_at)
    : null;

const remainingMs = ref(0);
let ticker = null;

function recompute() {
    remainingMs.value =
        expiresAtMs === null ? 0 : Math.max(0, expiresAtMs - (Date.now() - skewMs));
}

onMounted(() => {
    recompute();

    if (expiresAtMs !== null) {
        ticker = window.setInterval(recompute, 1000);
    }
});

onBeforeUnmount(() => {
    if (ticker !== null) {
        window.clearInterval(ticker);
    }
});

const countdown = computed(() => {
    const total = Math.floor(remainingMs.value / 1000);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;

    if (hours > 0) {
        return `${hours}h ${String(minutes).padStart(2, '0')}m`;
    }

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
});

const showCountdown = computed(
    () => isOpen.value && expiresAtMs !== null && remainingMs.value > 0,
);

/* ── Copy ───────────────────────────────────────────────────────────── */

const copied = ref(null);

async function copy(value, key) {
    if (!value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(String(value));
        copied.value = key;
        toastSuccess(key === 'link' ? 'Link copied — share it with your group.' : 'Copied.');
        window.setTimeout(() => {
            if (copied.value === key) {
                copied.value = null;
            }
        }, 2000);
    } catch {
        toastError('Your browser blocked copying. Please select the text manually.');
    }
}

function copyLink() {
    copy(
        typeof window === 'undefined'
            ? route('public.booking.status', { booking: props.booking.code })
            : window.location.href,
        'link',
    );
}

/* ── Cancel ─────────────────────────────────────────────────────────── */

const cancelling = ref(false);

async function cancelBooking() {
    const confirmed = await confirmAction({
        title: 'Cancel this booking?',
        text: 'The slot goes back on the schedule and this booking is closed. This cannot be undone.',
        confirmText: 'Yes, cancel it',
        cancelText: 'Keep my booking',
        variant: 'danger',
    });

    if (!confirmed) {
        return;
    }

    router.post(
        route('public.booking.cancel', { booking: props.booking.code }),
        {},
        {
            preserveScroll: true,
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

/** Primary action: noir fill, white text (21:1). Ash is never used as a
 *  white-text fill — white on ash-500 is ~1.9:1 and fails WCAG. */
const primaryCta =
    'inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-card transition-colors duration-200 ease-[var(--ease-out-soft)] hover:bg-graphite-500 hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900';
</script>

<template>
    <PublicLayout :title="`Booking ${booking.code}`">
        <div class="mx-auto w-full max-w-3xl">
            <div class="rounded-xl border border-bone-300/70 bg-white px-5 py-5 shadow-card sm:px-8">
                <ProgressSteps :steps="wizardSteps" :current="booking.step" compact />
            </div>

            <!-- ── Status hero ────────────────────────────────────────── -->
            <section
                :class="[
                    'mt-5 rounded-xl p-6 ring-1 ring-inset sm:p-8',
                    HERO_TONES[story.tone] ?? HERO_TONES.ink,
                ]"
            >
                <p
                    :class="[
                        'font-display-heading text-xs tracking-[0.2em]',
                        heroText.kicker,
                    ]"
                >
                    {{ story.kicker }}
                </p>

                <div class="mt-3 flex flex-wrap items-start gap-4">
                    <span
                        :class="[
                            'inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl shadow-card',
                            HERO_ICONS[story.tone] ?? HERO_ICONS.ink,
                        ]"
                        aria-hidden="true"
                    >
                        <component :is="story.icon" :size="26" :stroke-width="1.6" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-3">
                            <h1
                                :class="[
                                    'font-display-heading text-xl sm:text-2xl',
                                    heroText.headline,
                                ]"
                            >
                                {{ story.headline }}
                            </h1>
                            <Badge :status="booking.status" :label="booking.status_label" size="md" />
                        </div>

                        <p :class="['mt-2 max-w-prose text-sm leading-relaxed', heroText.body]">
                            {{ story.next }}
                        </p>
                    </div>
                </div>

                <!-- Booking code, big and copyable -->
                <div
                    class="mt-6 flex flex-wrap items-center gap-3 rounded-xl bg-white p-4 shadow-card"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] tracking-wide text-noir-500 uppercase">
                            Booking code
                        </p>
                        <p
                            class="mt-0.5 font-mono text-xl font-semibold tracking-wider text-graphite-500 sm:text-2xl"
                        >
                            {{ booking.code }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <Button variant="secondary" size="sm" @click="copy(booking.code, 'code')">
                            <template #icon>
                                <component
                                    :is="copied === 'code' ? Check : Copy"
                                    :size="14"
                                    aria-hidden="true"
                                />
                            </template>
                            Copy code
                        </Button>
                        <Button variant="ghost" size="sm" @click="copyLink">
                            <template #icon>
                                <component
                                    :is="copied === 'link' ? Check : Copy"
                                    :size="14"
                                    aria-hidden="true"
                                />
                            </template>
                            Copy link
                        </Button>
                    </div>
                </div>

                <!-- Countdown while a hold is live -->
                <p
                    v-if="showCountdown"
                    :class="[
                        'mt-4 flex items-center gap-2 text-sm font-medium',
                        story.tone === 'brand' ? 'text-bone-200' : 'text-noir-700',
                    ]"
                >
                    <Timer :size="15" class="opacity-70" aria-hidden="true" />
                    {{
                        booking.status === 'awaiting_payment'
                            ? 'Held for another'
                            : 'Held for verification for another'
                    }}
                    <span class="font-mono font-semibold tabular-nums">{{ countdown }}</span>
                </p>
            </section>

            <!-- ── Rejection reason ───────────────────────────────────── -->
            <Alert
                v-if="booking.status === 'rejected' && booking.rejection_reason"
                class="mt-5"
                variant="error"
                title="Why it was not verified"
                :message="booking.rejection_reason"
            />

            <!-- ── Primary calls to action ────────────────────────────── -->
            <div
                v-if="booking.can_pay"
                class="mt-5 flex flex-wrap items-center gap-3 rounded-xl border-2 border-ash-400 bg-ash-50 p-5"
            >
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-noir-900">
                        {{ amount }} still to pay
                    </p>
                    <p class="mt-0.5 text-sm text-noir-600">
                        Finish your payment to lock this slot in.
                    </p>
                </div>
                <Link :href="route('public.booking.payment', { booking: booking.code })" :class="primaryCta">
                    Pay now
                    <ArrowRight :size="16" aria-hidden="true" />
                </Link>
            </div>

            <div
                v-else-if="!isOpen && !isSettled"
                class="mt-5 flex flex-wrap items-center gap-3 rounded-xl border border-bone-300/70 bg-white p-5 shadow-card"
            >
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-noir-900">Want to book again?</p>
                    <p class="mt-0.5 text-sm text-noir-600">
                        The schedule is open — pick a new time in a couple of taps.
                    </p>
                </div>
                <Link
                    :href="
                        booking.court_slug
                            ? route('public.courts.show', { court: booking.court_slug })
                            : route('public.courts.index')
                    "
                    :class="primaryCta"
                >
                    Find a new time
                    <ArrowRight :size="16" aria-hidden="true" />
                </Link>
            </div>

            <!-- ── Details ────────────────────────────────────────────── -->
            <section
                :class="[
                    'mt-5 overflow-hidden rounded-xl border border-bone-300/70 bg-white shadow-card',
                    isSettled ? 'border-t-4 border-t-ash-500' : '',
                ]"
            >
                <header
                    class="flex items-center gap-3 border-b border-bone-300/70 px-5 py-4 sm:px-6"
                >
                    <!-- Light surface: the crimson mark is drawn for light
                         backgrounds, so it sits bare — no plate behind it. -->
                    <img
                        src="/images/brand/logo-mark.png"
                        alt="After Hours"
                        width="960"
                        height="463"
                        class="h-auto w-14 shrink-0"
                    />
                    <h2 class="min-w-0 font-display-heading text-sm text-noir-900">
                        Booking details
                    </h2>
                </header>

                <dl class="grid grid-cols-1 divide-y divide-bone-300/70 sm:grid-cols-2 sm:divide-y-0">
                    <div class="px-5 py-4 sm:border-b sm:border-bone-300/70">
                        <dt class="flex items-center gap-1.5 text-xs text-noir-500">
                            <MapPin :size="13" aria-hidden="true" />
                            {{ booking.is_multi_court ? 'Courts' : 'Court' }}
                        </dt>
                        <!-- A booking may span more than one court: list every
                             one so the receipt names each court being played,
                             not just the primary. Single-court is unchanged. -->
                        <dd class="mt-1 text-sm font-medium text-noir-900">
                            {{ booking.is_multi_court ? booking.court_names.join(', ') : booking.court_name }}
                        </dd>
                    </div>

                    <div class="px-5 py-4 sm:border-b sm:border-l sm:border-bone-300/70">
                        <dt class="flex items-center gap-1.5 text-xs text-noir-500">
                            <CalendarDays :size="13" aria-hidden="true" />
                            Date
                        </dt>
                        <dd class="mt-1 text-sm font-medium text-noir-900">
                            {{ booking.date ?? '—' }}
                        </dd>
                    </div>

                    <div class="px-5 py-4 sm:border-b sm:border-bone-300/70">
                        <dt class="flex items-center gap-1.5 text-xs text-noir-500">
                            <Clock :size="13" aria-hidden="true" />
                            Time
                        </dt>
                        <!-- Each line names its court only when the booking
                             spans several — a cross-court booking must say
                             which time belongs to which court. Single-court
                             renders the bare time range exactly as before, and
                             the key carries the court so two courts sharing a
                             time slot never collide. -->
                        <dd v-if="booking.slot_count > 1" class="mt-1 space-y-0.5">
                            <p
                                v-for="(slot, index) in booking.slots"
                                :key="`${index}-${slot.time_range}`"
                                class="text-sm font-medium text-noir-900"
                            >
                                {{ booking.is_multi_court ? `${slot.court_name} · ${slot.time_range}` : slot.time_range }}
                            </p>
                        </dd>
                        <dd v-else class="mt-1 text-sm font-medium text-noir-900">
                            {{ booking.time_range ?? '—' }}
                            <span v-if="booking.duration_minutes" class="font-normal text-noir-500">
                                · {{ booking.duration_minutes }} min
                            </span>
                        </dd>
                    </div>

                    <div class="px-5 py-4 sm:border-b sm:border-l sm:border-bone-300/70">
                        <dt class="flex items-center gap-1.5 text-xs text-noir-500">
                            <Wallet :size="13" aria-hidden="true" />
                            Amount
                        </dt>
                        <dd class="mt-1 text-sm font-semibold tabular-nums text-noir-900">
                            {{ amount }}
                        </dd>
                    </div>

                    <div class="px-5 py-4">
                        <dt class="text-xs text-noir-500">Booked for</dt>
                        <dd class="mt-1 text-sm font-medium text-noir-900">
                            {{ booking.customer_name }}
                        </dd>
                        <dd class="mt-0.5 flex items-center gap-1.5 text-xs text-noir-500">
                            <Phone :size="12" aria-hidden="true" />
                            {{ booking.customer_phone }}
                        </dd>
                    </div>

                    <div
                        v-if="booking.payment_reference"
                        class="px-5 py-4 sm:border-l sm:border-bone-300/70"
                    >
                        <dt class="text-xs text-noir-500">{{ referenceLabel }}</dt>
                        <dd class="mt-1 font-mono text-sm font-medium tracking-wide text-noir-900">
                            {{ booking.payment_reference }}
                        </dd>
                    </div>
                </dl>

                <div v-if="booking.notes" class="border-t border-bone-300/70 px-5 py-4 sm:px-6">
                    <p class="text-xs text-noir-500">Your note</p>
                    <p class="mt-1 text-sm leading-relaxed whitespace-pre-line text-noir-700">
                        {{ booking.notes }}
                    </p>
                </div>

                <div
                    v-if="booking.can_cancel"
                    class="flex flex-wrap items-center justify-between gap-3 border-t border-bone-300/70 bg-bone-100/60 px-5 py-4 sm:px-6"
                >
                    <p class="min-w-0 text-xs leading-relaxed text-noir-500">
                        Cannot make it? Cancel now so someone else can take the court.
                    </p>
                    <Button variant="ghost" size="sm" :loading="cancelling" @click="cancelBooking">
                        Cancel booking
                    </Button>
                </div>
            </section>

            <!-- ── Support ────────────────────────────────────────────── -->
            <div
                v-if="payment && (payment.support_phone || payment.support_email)"
                class="mt-5 flex flex-wrap items-center gap-3 rounded-xl bg-noir-50 p-4 ring-1 ring-inset ring-noir-100"
            >
                <p class="min-w-0 flex-1 text-sm text-noir-600">
                    Something not right with this booking? Talk to us and quote your code.
                </p>
                <Button
                    v-if="payment.support_phone"
                    as="a"
                    :href="`tel:${payment.support_phone}`"
                    size="sm"
                    variant="secondary"
                >
                    <template #icon><Phone :size="14" aria-hidden="true" /></template>
                    {{ payment.support_phone }}
                </Button>
                <Button
                    v-else-if="payment.support_email"
                    as="a"
                    :href="`mailto:${payment.support_email}`"
                    size="sm"
                    variant="secondary"
                >
                    {{ payment.support_email }}
                </Button>
            </div>

            <!-- ── Footer helpers ─────────────────────────────────────── -->
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 px-1">
                <Link
                    :href="route('public.booking.lookup')"
                    class="inline-flex items-center gap-1.5 rounded-lg text-sm font-medium text-noir-500 transition-colors hover:text-noir-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
                >
                    <Search :size="15" aria-hidden="true" />
                    Look up a different booking
                </Link>

                <Link
                    :href="route('public.courts.index')"
                    class="inline-flex items-center gap-1.5 rounded-lg text-sm font-medium text-noir-500 transition-colors hover:text-noir-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-noir-900"
                >
                    Browse all courts
                    <ArrowRight :size="15" aria-hidden="true" />
                </Link>
            </div>
        </div>
    </PublicLayout>
</template>
