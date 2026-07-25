<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    BadgeCheck,
    Ban,
    Check,
    Clock,
    ExternalLink,
    Hash,
    History,
    ImageOff,
    Mail,
    MapPin,
    Phone,
    Receipt,
    Trash2,
    TriangleAlert,
    User,
    Wallet,
    X,
    ZoomIn,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/Alert.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useSwal } from '@/Composables/useSwal';
import { statusLabel } from '@/Composables/useStatus';

/*
| Booking detail — the second half of the verify loop.
|
| Everything the admin needs to make the call sits above the fold: which app
| the customer paid with, the amount, and the customer's own screenshot at a
| size you can actually read. The decision itself lives in a sticky panel so
| it stays within reach however far down the page they scroll.
|
| A reference number is no longer collected on the checkout — the screenshot
| is the proof now — so there is no dedicated field for it here. A booking
| taken before this change may still carry one; it surfaces as a quiet note
| beside the proof rather than a feature of its own.
*/

const props = defineProps({
    booking: { type: Object, required: true },
    timeline: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    serverTime: { type: String, default: null },
});

const { confirmAction, confirmDelete } = useSwal();

/* ------------------------------------------------------------------ */
/* Live hold countdown, anchored to server time                        */
/* ------------------------------------------------------------------ */

const skew = ref(0);
const now = ref(Date.now());
let ticker = null;

onMounted(() => {
    const server = props.serverTime ? Date.parse(props.serverTime) : NaN;

    skew.value = Number.isNaN(server) ? 0 : server - Date.now();

    ticker = window.setInterval(() => {
        now.value = Date.now();
    }, 1000);
});

onBeforeUnmount(() => {
    if (ticker !== null) {
        window.clearInterval(ticker);
    }
});

const secondsLeft = computed(() => {
    if (!props.booking.hold_active || !props.booking.hold_expires_at) {
        return null;
    }

    const expires = Date.parse(props.booking.hold_expires_at);

    if (Number.isNaN(expires)) {
        return null;
    }

    return Math.round((expires - (now.value + skew.value)) / 1000);
});

const countdown = computed(() => {
    const seconds = secondsLeft.value;

    if (seconds === null) {
        return null;
    }

    if (seconds <= 0) {
        return 'Hold expired — the slot has been released';
    }

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = String(seconds % 60).padStart(2, '0');

    return hours >= 1
        ? `Expires in ${hours}h ${minutes}m`
        : `Expires in ${minutes}m ${rest}s`;
});

const countdownTone = computed(() => {
    const seconds = secondsLeft.value;

    if (seconds === null) {
        return 'text-ink-500';
    }

    if (seconds <= 0) {
        return 'text-danger-600';
    }

    return seconds < 600 ? 'text-warn-600' : 'text-ink-600';
});

/* ------------------------------------------------------------------ */
/* Payment method — which app to open                                  */
/* ------------------------------------------------------------------ */

/*
| With two QRs live, a reference on its own no longer says where to look it
| up, and checking the wrong app's history reads as "payment not received".
| The method therefore appears on the payment card, on the reference label,
| in the sticky decision panel and in both dialogs.
|
| It is null on every booking taken before the second QR existed, and on one
| taken over the phone before any QR was published. That renders as an em
| dash — the repo's standing mark for "no value", and what ARCHITECTURE.md
| mandates here — while the surrounding copy drops the app name entirely,
| because a guessed "GCash" is exactly the mistake this exists to prevent.
*/
const METHOD_TONES = { gcash: 'info', gotyme: 'brand' };

/** The em dash every absent value in this codebase renders as. */
const NO_METHOD = '—';

const methodName = computed(() => props.booking.payment_method_label || null);

const methodTone = computed(() => METHOD_TONES[props.booking.payment_method] ?? 'ink');

const methodBadge = computed(() => methodName.value ?? NO_METHOD);

/** The account the money should have landed in, for the confirm warning. */
const accountPhrase = computed(() =>
    methodName.value ? `the ${methodName.value} account` : 'the receiving account',
);

/* ------------------------------------------------------------------ */
/* Actions                                                             */
/* ------------------------------------------------------------------ */

const busy = ref(false);

const escapeHtml = (value) =>
    String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

function submit(name, data = {}, onDone = null) {
    busy.value = true;

    router.post(route(name, props.booking.code), data, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            onDone?.();
        },
    });
}

async function confirmBooking() {
    const ok = await confirmAction({
        title: 'Confirm this payment?',
        html: `
            <div style="text-align:left;font-size:0.9rem;line-height:1.6">
                <p style="margin:0 0 0.75rem">
                    Check that this exact payment landed in ${escapeHtml(accountPhrase.value)} before you confirm.
                </p>
                <p style="margin:0"><strong>Amount</strong><br>
                    <span style="font-size:1.05rem">${escapeHtml(props.booking.amount_formatted)}</span>
                </p>
                <p style="margin:0.65rem 0 0"><strong>Booking</strong><br>
                    ${escapeHtml(props.booking.code)} · ${escapeHtml(props.booking.customer_name)}
                </p>
            </div>
        `,
        confirmText: 'Yes, payment received',
        cancelText: 'Not yet',
        variant: 'success',
        icon: 'question',
    });

    if (ok) {
        submit('admin.bookings.confirm');
    }
}

async function completeBooking() {
    const ok = await confirmAction({
        title: 'Mark as completed?',
        html: `<strong>${escapeHtml(props.booking.code)}</strong> will be recorded as played and counted in reports.`,
        confirmText: 'Mark completed',
        variant: 'primary',
    });

    if (ok) {
        submit('admin.bookings.complete');
    }
}

async function deleteBooking() {
    if (await confirmDelete(`Booking ${props.booking.code}`)) {
        busy.value = true;

        router.delete(route('admin.bookings.destroy', props.booking.code), {
            onFinish: () => {
                busy.value = false;
            },
        });
    }
}

/* Reject ------------------------------------------------------------- */

const rejectOpen = ref(false);
const reason = ref('');

function openReject() {
    reason.value = '';
    rejectOpen.value = true;
}

function submitReject() {
    submit('admin.bookings.reject', { reason: reason.value }, () => {
        rejectOpen.value = false;
    });
}

/**
 * Short noun phrase for "X will go back on sale" style sentences — reads
 * naturally whether the booking holds one slot or several.
 */
const scheduleNoun = computed(() => {
    const count = props.booking?.slot_count ?? 1;

    return count > 1
        ? `all ${count} time slots`
        : (props.booking?.slot?.time_range ?? 'the slot');
});

/* Proof lightbox ----------------------------------------------------- */

const proofOpen = ref(false);

/* ------------------------------------------------------------------ */
/* Derived view state                                                  */
/* ------------------------------------------------------------------ */

const hasDecision = computed(
    () => props.booking.can_confirm || props.booking.can_reject || props.booking.can_complete,
);

const isPending = computed(() => props.booking.status === 'pending_verification');

const ACTION_TONES = {
    create: { icon: Receipt, class: 'bg-brand-50 text-brand-700' },
    update: { icon: Check, class: 'bg-info-50 text-info-600' },
    delete: { icon: Trash2, class: 'bg-danger-50 text-danger-700' },
    view: { icon: History, class: 'bg-ink-100 text-ink-600' },
};

const entryStyle = (action) => ACTION_TONES[action] ?? ACTION_TONES.view;
</script>

<template>
    <AppLayout :title="`Booking ${booking.code}`">
        <PageHeader
            :title="booking.code"
            :subtitle="`${booking.customer_name} · ${booking.court_name ?? 'Court'}`"
            :back-href="route('admin.bookings.index')"
            back-label="Back to bookings"
            :breadcrumbs="[
                { label: 'Bookings', href: route('admin.bookings.index') },
                { label: booking.code },
            ]"
        >
            <template #actions>
                <Badge :status="booking.status" size="md" />
            </template>
        </PageHeader>

        <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-3">
            <!-- ============================================================ -->
            <!-- Main column                                                  -->
            <!-- ============================================================ -->
            <div class="space-y-5 lg:col-span-2">
                <!-- Payment — the reason this page exists -->
                <Card padding="none">
                    <div class="border-b border-ink-200/70 px-5 py-4 sm:px-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <!-- The method is part of the heading, not a
                                     detail further down: it decides which app
                                     the verifier opens before anything else on
                                     this card is worth reading. -->
                                <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold text-ink-900">
                                    <Wallet :size="18" class="text-brand-600" aria-hidden="true" />
                                    Payment
                                    <Badge size="md" :tone="methodTone" :label="methodBadge" />
                                </h2>
                                <p class="mt-0.5 text-xs text-ink-500">
                                    {{
                                        booking.payment_submitted_label
                                            ? `Submitted ${booking.payment_submitted_label}`
                                            : 'The customer has not submitted anything yet.'
                                    }}
                                </p>
                            </div>
                            <p class="text-2xl font-semibold text-ink-900 tabular-nums">
                                {{ booking.amount_formatted }}
                            </p>
                        </div>
                    </div>

                    <!-- Proof of payment -->
                    <div class="px-5 py-5 sm:px-6">
                        <p class="mb-3 text-xs font-medium tracking-wide text-ink-500 uppercase">
                            Proof of payment
                        </p>

                        <button
                            v-if="booking.payment_proof_url"
                            type="button"
                            class="group relative block w-full cursor-zoom-in overflow-hidden rounded-xl border border-ink-200 bg-ink-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                            @click="proofOpen = true"
                        >
                            <img
                                :src="booking.payment_proof_url"
                                :alt="`Payment proof for booking ${booking.code}`"
                                class="mx-auto max-h-[26rem] w-auto max-w-full object-contain transition-transform duration-200 ease-[var(--ease-out-soft)] group-hover:scale-[1.01]"
                                loading="lazy"
                            />
                            <span
                                class="pointer-events-none absolute right-3 bottom-3 inline-flex items-center gap-1.5 rounded-lg bg-ink-900/75 px-2.5 py-1.5 text-xs font-medium text-white opacity-0 transition-opacity duration-150 group-hover:opacity-100"
                            >
                                <ZoomIn :size="14" aria-hidden="true" />
                                View full size
                            </span>
                        </button>

                        <EmptyState
                            v-else
                            :icon="ImageOff"
                            title="No screenshot uploaded"
                            :description="`The customer did not upload a screenshot. Check your ${methodName ?? 'the app they paid with'} transaction history for a matching payment.`"
                            size="sm"
                        />

                        <!-- Legacy only: bookings taken before the checkout dropped
                             this field may still carry a reference number. No
                             dedicated field for it any more — just a quiet note
                             beside the evidence that actually matters now. -->
                        <p v-if="booking.payment_reference" class="mt-2.5 text-xs text-ink-400">
                            Reference on file:
                            <span class="font-mono text-ink-500">{{ booking.payment_reference }}</span>
                        </p>
                    </div>
                </Card>

                <!-- Customer + schedule -->
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <Card title="Customer">
                        <dl class="space-y-3.5 text-sm">
                            <div class="flex items-start gap-2.5">
                                <User :size="15" class="mt-0.5 shrink-0 text-ink-400" aria-hidden="true" />
                                <div class="min-w-0">
                                    <dt class="sr-only">Name</dt>
                                    <dd class="font-medium text-ink-900">{{ booking.customer_name }}</dd>
                                </div>
                            </div>
                            <div class="flex items-start gap-2.5">
                                <Phone :size="15" class="mt-0.5 shrink-0 text-ink-400" aria-hidden="true" />
                                <div class="min-w-0">
                                    <dt class="sr-only">Phone</dt>
                                    <dd class="truncate text-ink-700">
                                        <a
                                            :href="`tel:${booking.customer_phone}`"
                                            class="hover:text-brand-700 hover:underline"
                                        >
                                            {{ booking.customer_phone || '—' }}
                                        </a>
                                    </dd>
                                </div>
                            </div>
                            <div class="flex items-start gap-2.5">
                                <Mail :size="15" class="mt-0.5 shrink-0 text-ink-400" aria-hidden="true" />
                                <div class="min-w-0">
                                    <dt class="sr-only">Email</dt>
                                    <dd class="truncate text-ink-700">
                                        <a
                                            v-if="booking.customer_email"
                                            :href="`mailto:${booking.customer_email}`"
                                            class="hover:text-brand-700 hover:underline"
                                        >
                                            {{ booking.customer_email }}
                                        </a>
                                        <span v-else class="text-ink-400">No email</span>
                                    </dd>
                                </div>
                            </div>
                        </dl>

                        <div v-if="booking.notes" class="mt-4 rounded-lg bg-ink-50 p-3">
                            <p class="text-xs font-medium text-ink-500">Customer notes</p>
                            <p class="mt-1 text-sm whitespace-pre-line text-ink-700">
                                {{ booking.notes }}
                            </p>
                        </div>
                    </Card>

                    <Card title="Court & schedule">
                        <dl class="space-y-3.5 text-sm">
                            <div class="flex items-start gap-2.5">
                                <MapPin :size="15" class="mt-0.5 shrink-0 text-ink-400" aria-hidden="true" />
                                <div class="min-w-0">
                                    <dt class="sr-only">Court</dt>
                                    <dd class="font-medium text-ink-900">
                                        <a
                                            v-if="booking.court"
                                            :href="booking.court.url"
                                            class="hover:text-brand-700 hover:underline"
                                        >
                                            {{ booking.court.name }}
                                        </a>
                                        <span v-else>—</span>
                                    </dd>
                                    <dd v-if="booking.court" class="text-xs text-ink-500">
                                        {{ booking.court.code }}
                                    </dd>
                                    <!-- The court above is only the primary
                                         (earliest) slot's court; flag when the
                                         booking actually spans several so nobody
                                         confirms against a half-truth. The full
                                         per-court breakdown is listed below. -->
                                    <dd v-if="booking.is_multi_court" class="mt-1">
                                        <Badge
                                            size="xs"
                                            :dot="false"
                                            tone="brand"
                                            :label="`Spans ${booking.court_names.length} courts`"
                                        />
                                    </dd>
                                </div>
                            </div>
                            <div class="flex items-start gap-2.5">
                                <Clock :size="15" class="mt-0.5 shrink-0 text-ink-400" aria-hidden="true" />
                                <div class="min-w-0">
                                    <dt class="sr-only">Schedule</dt>
                                    <template v-if="booking.slot">
                                        <dd class="font-medium text-ink-900">
                                            {{ booking.slot.date_label }}
                                        </dd>
                                        <dd class="text-ink-600">
                                            {{ booking.slot.time_range }}
                                            <span class="text-ink-400">
                                                ({{ booking.slot.duration_minutes }} min)
                                            </span>
                                        </dd>
                                    </template>
                                    <dd v-else class="text-ink-400">Slot no longer exists</dd>
                                </div>
                            </div>
                            <div v-if="booking.slot_status" class="flex items-center gap-2.5">
                                <Hash :size="15" class="shrink-0 text-ink-400" aria-hidden="true" />
                                <div>
                                    <dt class="sr-only">Slot status</dt>
                                    <dd><Badge :status="booking.slot_status" size="xs" /></dd>
                                </div>
                            </div>
                        </dl>

                        <!-- Full itemised list — only when there is more than the
                             one primary slot shown above, otherwise this would
                             just repeat it. -->
                        <div
                            v-if="booking.slot_count > 1"
                            class="mt-4 border-t border-ink-200/70 pt-4"
                        >
                            <p class="mb-2 text-xs font-medium tracking-wide text-ink-500 uppercase">
                                All {{ booking.slot_count }} time slots
                            </p>
                            <ul class="space-y-1.5">
                                <li
                                    v-for="s in booking.slots"
                                    :key="s.id"
                                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 rounded-lg bg-ink-50 px-3 py-2 text-sm"
                                >
                                    <!-- Cross-court booking: badge each line with
                                         its court so a slot's court is never
                                         ambiguous. Single-court keeps the plain
                                         date span it has always shown. -->
                                    <span
                                        v-if="booking.is_multi_court"
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <Badge
                                            size="xs"
                                            :dot="false"
                                            tone="brand"
                                            :label="s.court_name"
                                        />
                                        <span class="font-medium text-ink-800">{{ s.date_label }}</span>
                                    </span>
                                    <span v-else class="font-medium text-ink-800">{{ s.date_label }}</span>
                                    <span class="text-ink-600">
                                        {{ s.time_range }}
                                        <span class="text-ink-400">({{ s.duration_minutes }} min)</span>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </Card>
                </div>

                <!-- Audit history -->
                <Card title="History" subtitle="Every recorded change to this booking.">
                    <ol v-if="timeline.length" class="relative space-y-5 pl-1">
                        <li
                            v-for="(entry, index) in timeline"
                            :key="entry.id"
                            class="relative flex gap-3.5"
                        >
                            <span
                                v-if="index < timeline.length - 1"
                                class="absolute top-8 left-[0.9375rem] h-full w-px bg-ink-200"
                                aria-hidden="true"
                            />
                            <span
                                :class="[
                                    'relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full',
                                    entryStyle(entry.action).class,
                                ]"
                            >
                                <component
                                    :is="entryStyle(entry.action).icon"
                                    :size="15"
                                    aria-hidden="true"
                                />
                            </span>
                            <div class="min-w-0 flex-1 pb-1">
                                <p class="text-sm text-ink-800">{{ entry.description }}</p>
                                <p class="mt-0.5 text-xs text-ink-400">
                                    {{ entry.user_name }}
                                    <span v-if="entry.role_name">· {{ entry.role_name }}</span>
                                    · {{ entry.created_label }}
                                </p>
                            </div>
                        </li>
                    </ol>

                    <EmptyState
                        v-else
                        :icon="History"
                        title="No history yet"
                        description="Transitions on this booking will appear here as they happen."
                        size="sm"
                    />
                </Card>
            </div>

            <!-- ============================================================ -->
            <!-- Sticky decision column                                       -->
            <!-- ============================================================ -->
            <div class="space-y-5">
                <div class="lg:sticky lg:top-24">
                    <Card>
                        <p class="text-xs font-medium tracking-wide text-ink-500 uppercase">
                            Verification
                        </p>

                        <div class="mt-2 flex items-center gap-2.5">
                            <Badge :status="booking.status" size="md" />
                        </div>

                        <p
                            v-if="countdown"
                            :class="['mt-3 flex items-center gap-1.5 text-sm font-medium', countdownTone]"
                        >
                            <Clock :size="15" class="shrink-0" aria-hidden="true" />
                            {{ countdown }}
                        </p>

                        <!-- Decision -->
                        <div v-if="hasDecision" class="mt-5 space-y-2.5">
                            <p v-if="isPending && booking.payment_reference" class="text-sm text-ink-600">
                                Match
                                <span class="font-mono font-semibold text-ink-900">
                                    {{ booking.payment_reference }}
                                </span>
                                for
                                <span class="font-semibold text-ink-900">
                                    {{ booking.amount_formatted }}
                                </span>
                                in
                                <span class="font-semibold text-ink-900">
                                    {{ methodName ?? 'the app the customer used' }}</span
                                >, then decide.
                            </p>
                            <p v-else-if="isPending" class="text-sm text-ink-600">
                                Match the screenshot above for
                                <span class="font-semibold text-ink-900">
                                    {{ booking.amount_formatted }}
                                </span>
                                in
                                <span class="font-semibold text-ink-900">
                                    {{ methodName ?? 'the app the customer used' }}</span
                                >, then decide.
                            </p>

                            <Button
                                v-if="booking.can_confirm"
                                variant="success"
                                block
                                :loading="busy"
                                @click="confirmBooking"
                            >
                                <template #icon><Check :size="16" aria-hidden="true" /></template>
                                Confirm payment
                            </Button>

                            <Button
                                v-if="booking.can_reject"
                                variant="secondary"
                                block
                                :disabled="busy"
                                class="text-danger-600 hover:text-danger-700"
                                @click="openReject"
                            >
                                <template #icon><X :size="16" aria-hidden="true" /></template>
                                Reject payment
                            </Button>

                            <Button
                                v-if="booking.can_complete"
                                variant="primary"
                                block
                                :loading="busy"
                                @click="completeBooking"
                            >
                                <template #icon><BadgeCheck :size="16" aria-hidden="true" /></template>
                                Mark as completed
                            </Button>
                        </div>

                        <!-- Settled -->
                        <div v-else class="mt-4 space-y-3">
                            <Alert
                                v-if="booking.status === 'confirmed' || booking.status === 'completed'"
                                variant="success"
                                :title="`${statusLabel(booking.status)}${booking.confirmed_by ? ` by ${booking.confirmed_by}` : ''}`"
                                :message="booking.confirmed_at_label ?? 'The slot is reserved for this customer.'"
                            />
                            <Alert
                                v-else-if="booking.status === 'rejected'"
                                variant="error"
                                :title="`Rejected${booking.rejected_by ? ` by ${booking.rejected_by}` : ''}`"
                                :message="booking.rejection_reason || booking.rejected_at_label || 'The slot was returned to the market.'"
                            />
                            <Alert
                                v-else-if="booking.status === 'cancelled'"
                                variant="warning"
                                title="Cancelled by the customer"
                                :message="booking.cancelled_at_label ?? 'The slot was returned to the market.'"
                            />
                            <Alert
                                v-else-if="booking.status === 'expired'"
                                variant="warning"
                                title="Hold expired"
                                message="The customer did not complete payment in time and the slot was released."
                            />
                            <Alert
                                v-else
                                variant="info"
                                title="Nothing to verify yet"
                                message="This booking is waiting on the customer to pay and submit a reference."
                            />
                        </div>

                        <div
                            v-if="can.delete"
                            class="mt-5 border-t border-ink-200/70 pt-4"
                        >
                            <Button
                                variant="ghost"
                                size="sm"
                                block
                                :disabled="busy"
                                class="text-danger-600 hover:bg-danger-50 hover:text-danger-700"
                                @click="deleteBooking"
                            >
                                <template #icon><Trash2 :size="15" aria-hidden="true" /></template>
                                Delete this record
                            </Button>
                        </div>
                    </Card>

                    <!-- Provenance -->
                    <Card class="mt-5" title="Submission">
                        <dl class="space-y-2.5 text-xs">
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-ink-500">Booked</dt>
                                <dd class="text-right text-ink-700">{{ booking.created_full ?? '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-ink-500">IP address</dt>
                                <dd class="text-right font-mono text-ink-700">
                                    {{ booking.ip_address || '—' }}
                                </dd>
                            </div>
                            <div class="flex flex-col gap-1">
                                <dt class="text-ink-500">Device</dt>
                                <dd class="break-words text-ink-600">
                                    {{ booking.user_agent || '—' }}
                                </dd>
                            </div>
                        </dl>
                    </Card>
                </div>
            </div>
        </div>

        <!-- Full-size proof -->
        <Modal v-model="proofOpen" size="2xl" title="Proof of payment">
            <img
                v-if="booking.payment_proof_url"
                :src="booking.payment_proof_url"
                :alt="`Payment proof for booking ${booking.code}`"
                class="mx-auto max-h-[75vh] w-auto max-w-full rounded-lg object-contain"
            />
            <template #footer>
                <Button
                    v-if="booking.payment_proof_url"
                    as="a"
                    variant="secondary"
                    :href="booking.payment_proof_url"
                    target="_blank"
                    rel="noopener"
                >
                    <template #icon><ExternalLink :size="15" aria-hidden="true" /></template>
                    Open in a new tab
                </Button>
                <Button @click="proofOpen = false">Close</Button>
            </template>
        </Modal>

        <!-- Reject -->
        <Modal v-model="rejectOpen" title="Reject this payment?" size="md">
            <div
                class="flex items-start gap-3 rounded-xl border border-warn-500/25 bg-warn-50 p-3.5 text-sm text-ink-700"
            >
                <TriangleAlert :size="18" class="mt-0.5 shrink-0 text-warn-600" aria-hidden="true" />
                <p>
                    <span class="font-semibold">{{ booking.code }}</span> will be rejected and
                    <span class="font-semibold">{{ scheduleNoun }}</span>
                    will go straight back on sale. The customer is notified immediately.
                </p>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-xs text-ink-500">Customer</dt>
                    <dd class="font-medium text-ink-900">{{ booking.customer_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-500">Amount</dt>
                    <dd class="font-medium text-ink-900 tabular-nums">
                        {{ booking.amount_formatted }}
                    </dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-ink-500">Paid via</dt>
                    <dd class="mt-0.5 flex flex-wrap items-center gap-2">
                        <Badge size="xs" :dot="false" :tone="methodTone" :label="methodBadge" />
                        <!-- Legacy only — see the note by the payment card above. -->
                        <span v-if="booking.payment_reference" class="font-mono text-xs text-ink-400">
                            Ref: {{ booking.payment_reference }}
                        </span>
                    </dd>
                </div>
            </dl>

            <div class="mt-4">
                <FormTextarea
                    v-model="reason"
                    label="Reason (optional)"
                    :placeholder="
                        methodName
                            ? `e.g. No matching transaction found in ${methodName} for this amount.`
                            : 'e.g. No matching transaction found for this amount.'
                    "
                    hint="Sent to the customer word for word. Leave blank to send the standard message."
                    :rows="3"
                    :maxlength="500"
                    show-count
                />
            </div>

            <template #footer>
                <Button variant="secondary" @click="rejectOpen = false">Cancel</Button>
                <Button variant="danger" :loading="busy" @click="submitReject">
                    <template #icon><Ban :size="15" aria-hidden="true" /></template>
                    Reject booking
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
