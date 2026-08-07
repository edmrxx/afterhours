<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    BadgeCheck,
    Check,
    ClipboardList,
    Clock,
    Copy,
    Eye,
    Plus,
    RotateCcw,
    Trash2,
    TriangleAlert,
    X,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import DataTable from '@/Components/DataTable.vue';
import FormDatePicker from '@/Components/FormDatePicker.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import IconButton from '@/Components/IconButton.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import Tabs from '@/Components/Tabs.vue';
import { useDataTable } from '@/Composables/useDataTable';
import { useSwal } from '@/Composables/useSwal';
import { statusLabel } from '@/Composables/useStatus';

/*
| The verification queue.
|
| This screen is optimised for one loop, repeated dozens of times a day: open
| the receipt screenshot → check it in the right app on the phone → confirm
| or reject. Pending verification therefore sorts to the top by default
| (server side), every row is badged with the app the payment belongs to, and
| confirming restates the amount in the dialog so a mis-click cannot take
| money for the wrong booking.
|
| A reference number is no longer collected on the checkout, so it has no
| dedicated field here either — a booking taken before this change may still
| carry one, and it surfaces as a quiet note rather than a feature of its own.
*/

const props = defineProps({
    bookings: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    courts: { type: Array, default: () => [] },
    statusCounts: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    /** ISO timestamp from the server — anchors the countdown against clock skew. */
    serverTime: { type: String, default: null },
});

const { confirmAction, confirmDelete, toastSuccess, toastError } = useSwal();

// The defaults, NOT the current values — useDataTable hydrates the live state
// from the URL itself and compares against these to decide whether the table
// is filtered.
const table = useDataTable(
    'admin.bookings.index',
    { status: '', source: '', court_id: '', date_from: '', date_to: '' },
    { only: ['bookings', 'statusCounts', 'filters', 'serverTime'] },
);

/*
| Where the booking came from. Blank is "both" and stays the default — the
| desk works one queue, and splitting it by default would hide half the work
| behind a filter nobody remembered to clear.
*/
const sourceOptions = [
    { value: '', label: 'All sources' },
    { value: 'online', label: 'Public site' },
    { value: 'manual', label: 'Manual' },
];

const rows = computed(() => props.bookings?.data ?? []);

/* ------------------------------------------------------------------ */
/* Live clock — anchored to the server so a skewed laptop still counts  */
/* down to the right moment.                                            */
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

/** Seconds left on a hold, or null when the booking is not holding one. */
function secondsLeft(booking) {
    if (!booking?.hold_active || !booking.hold_expires_at) {
        return null;
    }

    const expires = Date.parse(booking.hold_expires_at);

    if (Number.isNaN(expires)) {
        return null;
    }

    return Math.round((expires - (now.value + skew.value)) / 1000);
}

function countdown(booking) {
    const seconds = secondsLeft(booking);

    if (seconds === null) {
        return null;
    }

    if (seconds <= 0) {
        return 'Hold expired';
    }

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (hours >= 1) {
        return `${hours}h ${minutes}m left`;
    }

    return `${minutes}m ${String(seconds % 60).padStart(2, '0')}s left`;
}

/** Under ten minutes the countdown turns urgent. */
function countdownTone(booking) {
    const seconds = secondsLeft(booking);

    if (seconds === null) {
        return '';
    }

    if (seconds <= 0) {
        return 'text-danger-600';
    }

    return seconds < 600 ? 'text-warn-600' : 'text-ink-500';
}

/* ------------------------------------------------------------------ */
/* Status tabs                                                         */
/* ------------------------------------------------------------------ */

/** Always visible, even at zero — they are the shape of the queue. */
const PINNED = ['pending_verification', 'awaiting_payment', 'confirmed'];

const TAB_ORDER = [
    'pending_verification',
    'awaiting_payment',
    'confirmed',
    'completed',
    'rejected',
    'cancelled',
    'expired',
];

const activeStatus = computed(() => table.filters.status || 'all');

const statusTabs = computed(() => [
    { key: 'all', label: 'All', badge: props.statusCounts.all ?? 0 },
    ...TAB_ORDER.filter(
        (status) => PINNED.includes(status) || (props.statusCounts[status] ?? 0) > 0,
    ).map((status) => ({
        key: status,
        label: statusLabel(status),
        badge: props.statusCounts[status] ?? 0,
    })),
]);

function setStatus(key) {
    table.filters.status = key === 'all' ? '' : key;
}

/* ------------------------------------------------------------------ */
/* Payment method                                                      */
/* ------------------------------------------------------------------ */

/*
| Two QRs are live, so the badge is what says which app a payment belongs to.
|
| `payment_method` is null on every booking taken before the second QR
| existed, and on one taken over the phone before any QR was published. That
| renders as an em dash, the same mark every other absent value on this page
| uses — defaulting it to GCash would send the verifier hunting through the
| wrong app's history.
*/
const METHOD_TONES = { gcash: 'info', gotyme: 'brand' };

/** The em dash every absent value in this codebase renders as. */
const NO_METHOD = '—';

const methodTone = (booking) => METHOD_TONES[booking?.payment_method] ?? 'ink';

const methodBadge = (booking) => booking?.payment_method_label || NO_METHOD;

const columns = [
    { key: 'code', label: 'Booking', sortable: true },
    { key: 'customer_name', label: 'Customer', sortable: true },
    { key: 'slot_date', label: 'Court & schedule', sortable: true },
    { key: 'amount', label: 'Amount', sortable: true, align: 'right' },
    { key: 'status', label: 'Status', sortable: true },
];

/* ------------------------------------------------------------------ */
/* Actions                                                             */
/* ------------------------------------------------------------------ */

const working = ref(null);

const escapeHtml = (value) =>
    String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

/**
 * Compact, natural-language description of the time(s) a booking covers —
 * the singular primary slot, or a plural summary once it spans more than one.
 */
function scheduleSummary(booking) {
    if (!booking?.slot) {
        return '';
    }

    const count = booking.slot_count ?? 1;

    return count > 1
        ? `${booking.slot.date_label} ${booking.slot.time_range} +${count - 1} more (${count} time slots)`
        : `${booking.slot.date_label} ${booking.slot.time_range}`;
}

/** Short noun phrase for "X will go back on sale" style sentences. */
function scheduleNoun(booking) {
    const count = booking?.slot_count ?? 1;

    return count > 1 ? `all ${count} time slots` : (booking?.slot?.time_range ?? 'the slot');
}

/**
 * The court(s) a booking covers — the primary court, or every court a
 * cross-court booking spans, so the confirm dialog states exactly what the
 * money is being taken for. Single-court reads identically to before.
 */
function courtSummary(booking) {
    return booking?.is_multi_court && Array.isArray(booking.court_names)
        ? booking.court_names.join(', ')
        : (booking?.court_name ?? 'Court');
}

function submit(name, booking, data = {}, onDone = null) {
    working.value = booking.code;

    router.post(route(name, booking.code), data, {
        preserveScroll: true,
        preserveState: false,
        onFinish: () => {
            working.value = null;
            onDone?.();
        },
    });
}

/**
 * The dialog restates the amount, because the whole risk of this screen is
 * confirming the wrong row.
 */
async function confirmBooking(booking) {
    // Name the account that should have received the money. Hard-coding GCash
    // would send half the verifications into the wrong app's history, and a
    // pre-GoTyme booking has no method at all — hence the neutral fallback.
    const account = booking.payment_method_label
        ? `the ${escapeHtml(booking.payment_method_label)} account`
        : 'the receiving account';

    const html = `
        <div style="text-align:left;font-size:0.9rem;line-height:1.6">
            <p style="margin:0 0 0.75rem">
                Check that this exact payment landed in ${account} before you confirm.
            </p>
            <p style="margin:0"><strong>Amount</strong><br>
                <span style="font-size:1.05rem">${escapeHtml(booking.amount_formatted)}</span>
            </p>
            <p style="margin:0.65rem 0 0"><strong>Booking</strong><br>
                ${escapeHtml(booking.code)} · ${escapeHtml(booking.customer_name)}<br>
                ${escapeHtml(courtSummary(booking))} · ${escapeHtml(scheduleSummary(booking))}
            </p>
        </div>
    `;

    const ok = await confirmAction({
        title: 'Confirm this payment?',
        html,
        confirmText: 'Yes, payment received',
        cancelText: 'Not yet',
        variant: 'success',
        icon: 'question',
    });

    if (ok) {
        submit('admin.bookings.confirm', booking);
    }
}

async function completeBooking(booking) {
    const ok = await confirmAction({
        title: 'Mark as completed?',
        html: `<strong>${escapeHtml(booking.code)}</strong> will be recorded as played and counted in reports.`,
        confirmText: 'Mark completed',
        variant: 'primary',
    });

    if (ok) {
        submit('admin.bookings.complete', booking);
    }
}

async function deleteBooking(booking) {
    if (await confirmDelete(`Booking ${booking.code}`)) {
        working.value = booking.code;

        router.delete(route('admin.bookings.destroy', booking.code), {
            preserveScroll: true,
            onFinish: () => {
                working.value = null;
            },
        });
    }
}

/* Reject — a modal, because the reason is free text and worth typing well. */

const rejecting = ref(null);
const reason = ref('');
const rejectOpen = computed({
    get: () => rejecting.value !== null,
    set: (value) => {
        if (!value) {
            rejecting.value = null;
        }
    },
});

function openReject(booking) {
    reason.value = '';
    rejecting.value = booking;
}

function submitReject() {
    const booking = rejecting.value;

    if (!booking) {
        return;
    }

    submit('admin.bookings.reject', booking, { reason: reason.value }, () => {
        rejecting.value = null;
    });
}

/* Copy the booking code straight to the clipboard. */

const copied = ref(null);

async function copy(value, label = 'Copied') {
    if (!value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(String(value));
        copied.value = value;
        toastSuccess(`${label} to clipboard`);
        window.setTimeout(() => {
            if (copied.value === value) {
                copied.value = null;
            }
        }, 1500);
    } catch {
        toastError('Your browser blocked clipboard access.');
    }
}
</script>

<template>
    <AppLayout title="Bookings">
        <PageHeader
            title="Bookings"
            subtitle="Verify payments, confirm reservations and keep the queue clear."
            :breadcrumbs="[{ label: 'Bookings' }]"
        >
            <template #actions>
                <Button
                    v-if="table.hasActiveFilters"
                    variant="secondary"
                    size="sm"
                    @click="table.reset()"
                >
                    <template #icon><RotateCcw :size="15" aria-hidden="true" /></template>
                    Clear filters
                </Button>
                <Button v-if="can.create" size="sm" :href="route('admin.bookings.create')">
                    <template #icon><Plus :size="15" aria-hidden="true" /></template>
                    New booking
                </Button>
            </template>
        </PageHeader>

        <!-- Status queue -->
        <div class="mt-6 overflow-x-auto">
            <Tabs
                :tabs="statusTabs"
                :model-value="activeStatus"
                variant="pills"
                @update:model-value="setStatus"
            />
        </div>

        <div class="mt-4">
            <DataTable
                :columns="columns"
                :rows="rows"
                :loading="table.loading"
                :sort="table.sort"
                :direction="table.direction"
                :filtered="table.hasActiveFilters"
                v-model:search="table.search"
                v-model:per-page="table.perPage"
                search-placeholder="Code, name, phone or payment reference…"
                min-width="min-w-[68rem]"
                empty-title="No bookings yet"
                empty-description="Reservations from the public site land here for verification, alongside any you key in yourself."
                :empty-icon="ClipboardList"
                @sort="table.sortBy"
            >
                <template #toolbar>
                    <div class="w-full sm:w-44">
                        <FormSelect
                            v-model="table.filters.court_id"
                            :options="courts"
                            placeholder="All courts"
                            label="Court"
                            size="sm"
                            sr-only-label
                        />
                    </div>
                    <div class="w-full sm:w-40">
                        <FormSelect
                            v-model="table.filters.source"
                            :options="sourceOptions"
                            label="Source"
                            size="sm"
                            sr-only-label
                        />
                    </div>
                    <div class="w-full sm:w-40">
                        <FormDatePicker
                            v-model="table.filters.date_from"
                            label="Play date from"
                            size="sm"
                            sr-only-label
                        />
                    </div>
                    <div class="w-full sm:w-40">
                        <FormDatePicker
                            v-model="table.filters.date_to"
                            label="Play date to"
                            size="sm"
                            sr-only-label
                        />
                    </div>
                </template>

                <!-- Booking code -->
                <template #cell-code="{ row }">
                    <div class="flex items-start gap-2">
                        <button
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-ink-100 px-2 py-1 font-mono text-xs font-semibold tracking-tight text-ink-800 transition-colors duration-150 ease-[var(--ease-out-soft)] hover:bg-brand-50 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                            :title="`Copy ${row.code}`"
                            @click="copy(row.code, 'Booking code copied')"
                        >
                            {{ row.code }}
                            <Check
                                v-if="copied === row.code"
                                :size="12"
                                class="text-success-600"
                                aria-hidden="true"
                            />
                            <Copy v-else :size="12" class="opacity-50" aria-hidden="true" />
                        </button>

                        <!-- Badged because "no payment proof" means opposite
                             things on the two sources: a missing step on a
                             public booking, and the normal state of affairs on
                             one the desk keyed in. -->
                        <Badge v-if="row.is_manual" tone="info" label="Manual" size="xs" :dot="false" />
                    </div>
                    <p class="mt-1 text-[11px] text-ink-400">{{ row.created_label }}</p>
                </template>

                <!-- Customer -->
                <template #cell-customer_name="{ row }">
                    <p class="font-medium text-ink-900">{{ row.customer_name }}</p>
                    <p class="text-xs text-ink-500">{{ row.customer_phone }}</p>
                </template>

                <!-- Court + schedule -->
                <template #cell-slot_date="{ row }">
                    <!-- A cross-court booking names every court it spans, badged
                         so a scanning verifier isn't misled into reading it as a
                         single-court reservation. Single-court is unchanged. -->
                    <div
                        v-if="row.is_multi_court"
                        class="flex flex-wrap items-center gap-x-1.5 gap-y-1"
                    >
                        <p class="font-medium text-ink-800">{{ row.court_names.join(' · ') }}</p>
                        <Badge size="xs" :dot="false" tone="brand" label="Multi-court" />
                    </div>
                    <p v-else class="font-medium text-ink-800">{{ row.court_name ?? '—' }}</p>
                    <p v-if="row.slot" class="text-xs text-ink-500">
                        {{ row.slot.date_label }} · {{ row.slot.time_range }}
                        <span v-if="row.slot_count > 1" class="text-ink-400">
                            +{{ row.slot_count - 1 }} more
                        </span>
                    </p>
                    <p v-else class="text-xs text-ink-400">Slot removed</p>
                </template>

                <!-- Amount + the app it belongs to -->
                <template #cell-amount="{ row }">
                    <p class="font-semibold text-ink-900 tabular-nums">
                        {{ row.amount_formatted }}
                    </p>
                    <div class="mt-0.5 flex flex-wrap items-center justify-end gap-x-1.5 gap-y-1">
                        <Badge
                            v-if="row.payment_method"
                            size="xs"
                            :dot="false"
                            :tone="methodTone(row)"
                            :label="methodBadge(row)"
                        />
                        <!-- Legacy only: bookings taken before the checkout
                             dropped this field may still carry a reference
                             number. No dedicated field for it any more — just a
                             quiet note, no copy button. -->
                        <span
                            v-if="row.payment_reference"
                            class="max-w-[9rem] truncate text-[11px] text-ink-400"
                            :title="row.payment_reference"
                        >
                            Ref: {{ row.payment_reference }}
                        </span>
                    </div>
                </template>

                <!-- Status + hold countdown -->
                <template #cell-status="{ row }">
                    <Badge :status="row.status" />
                    <p
                        v-if="countdown(row)"
                        :class="['mt-1 flex items-center gap-1 text-[11px] font-medium', countdownTone(row)]"
                    >
                        <Clock :size="11" class="shrink-0" aria-hidden="true" />
                        {{ countdown(row) }}
                    </p>
                    <p v-else-if="row.confirmed_by" class="mt-1 text-[11px] text-ink-400">
                        by {{ row.confirmed_by }}
                    </p>
                </template>

                <!-- Row actions -->
                <template #actions="{ row }">
                    <!-- The actionable queue gets full buttons, not icons. -->
                    <template v-if="row.can_confirm">
                        <Button
                            variant="success"
                            size="sm"
                            :loading="working === row.code"
                            @click="confirmBooking(row)"
                        >
                            <template #icon><Check :size="14" aria-hidden="true" /></template>
                            Confirm
                        </Button>
                        <Button
                            variant="secondary"
                            size="sm"
                            class="ml-1.5 text-danger-600 hover:text-danger-700"
                            :disabled="working === row.code"
                            @click="openReject(row)"
                        >
                            <template #icon><X :size="14" aria-hidden="true" /></template>
                            Reject
                        </Button>
                    </template>

                    <IconButton
                        v-else-if="row.can_reject"
                        variant="delete"
                        label="Reject booking"
                        :disabled="working === row.code"
                        @click="openReject(row)"
                    >
                        <X :size="16" aria-hidden="true" />
                    </IconButton>

                    <IconButton
                        v-if="row.can_complete"
                        variant="brand"
                        label="Mark as completed"
                        :disabled="working === row.code"
                        @click="completeBooking(row)"
                    >
                        <BadgeCheck :size="16" aria-hidden="true" />
                    </IconButton>

                    <IconButton
                        variant="view"
                        label="View booking"
                        :href="row.url"
                        class="ml-1"
                    >
                        <Eye :size="16" aria-hidden="true" />
                    </IconButton>

                    <IconButton
                        v-if="can.delete"
                        variant="delete"
                        label="Delete booking"
                        :disabled="working === row.code"
                        @click="deleteBooking(row)"
                    >
                        <Trash2 :size="16" aria-hidden="true" />
                    </IconButton>
                </template>

                <template #footer>
                    <Pagination
                        :paginator="bookings"
                        label="bookings"
                        :only="['bookings', 'statusCounts', 'filters', 'serverTime']"
                    />
                </template>
            </DataTable>
        </div>

        <!-- Reject -->
        <Modal v-model="rejectOpen" title="Reject this payment?" size="md">
            <template v-if="rejecting">
                <div
                    class="flex items-start gap-3 rounded-xl border border-warn-500/25 bg-warn-50 p-3.5 text-sm text-ink-700"
                >
                    <TriangleAlert :size="18" class="mt-0.5 shrink-0 text-warn-600" aria-hidden="true" />
                    <p>
                        <span class="font-semibold">{{ rejecting.code }}</span> will be rejected and
                        <span class="font-semibold">{{ scheduleNoun(rejecting) }}</span>
                        will go straight back on sale. The customer is notified immediately.
                    </p>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-ink-500">Customer</dt>
                        <dd class="font-medium text-ink-900">{{ rejecting.customer_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Amount</dt>
                        <dd class="font-medium text-ink-900 tabular-nums">
                            {{ rejecting.amount_formatted }}
                        </dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-xs text-ink-500">Paid via</dt>
                        <dd class="mt-0.5 flex flex-wrap items-center gap-2">
                            <Badge
                                size="xs"
                                :dot="false"
                                :tone="methodTone(rejecting)"
                                :label="methodBadge(rejecting)"
                            />
                            <!-- Legacy only — see the note in the amount column. -->
                            <span
                                v-if="rejecting.payment_reference"
                                class="font-mono text-xs text-ink-400"
                            >
                                Ref: {{ rejecting.payment_reference }}
                            </span>
                        </dd>
                    </div>
                </dl>

                <div class="mt-4">
                    <FormTextarea
                        v-model="reason"
                        label="Reason (optional)"
                        :placeholder="
                            rejecting.payment_method_label
                                ? `e.g. No matching transaction found in ${rejecting.payment_method_label} for this amount.`
                                : 'e.g. No matching transaction found for this amount.'
                        "
                        hint="Sent to the customer word for word. Leave blank to send the standard message."
                        :rows="3"
                        :maxlength="500"
                        show-count
                    />
                </div>
            </template>

            <template #footer>
                <Button variant="secondary" @click="rejectOpen = false">Cancel</Button>
                <Button
                    variant="danger"
                    :loading="working !== null"
                    @click="submitReject"
                >
                    Reject booking
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
