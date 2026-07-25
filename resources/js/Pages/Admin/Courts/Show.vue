<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    Ban,
    CalendarClock,
    CircleCheck,
    ClipboardList,
    Hash,
    ImageOff,
    ListOrdered,
    Pencil,
    Power,
    Ticket,
    Trash2,
    Wallet,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import { useSwal } from '@/Composables/useSwal';

const props = defineProps({
    court: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
    recentBookings: { type: Array, default: () => [] },
    canUpdate: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});

const { confirmDelete, confirmAction } = useSwal();

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
});

const longDate = new Intl.DateTimeFormat('en-PH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const dateTime = new Intl.DateTimeFormat('en-PH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

const formatDate = (value) => (value ? longDate.format(new Date(value)) : '—');
const formatDateTime = (value) => (value ? dateTime.format(new Date(value)) : '—');

const slotUsage = computed(() => {
    const total = props.stats.total_slots ?? 0;

    if (total === 0) {
        return 0;
    }

    return Math.round(((props.stats.booked_slots ?? 0) / total) * 100);
});

async function toggleStatus() {
    if (props.court.is_active) {
        const confirmed = await confirmAction({
            title: `Deactivate "${props.court.name}"?`,
            text:
                'It disappears from the public booking site straight away. ' +
                'Existing bookings and slots are left exactly as they are.',
            confirmText: 'Deactivate',
            cancelText: 'Keep it active',
            variant: 'warning',
        });

        if (!confirmed) {
            return;
        }
    }

    router.patch(
        route('admin.courts.status', props.court.slug),
        {},
        { preserveScroll: true },
    );
}

async function destroy() {
    if (!(await confirmDelete(props.court.name))) {
        return;
    }

    router.delete(route('admin.courts.destroy', props.court.slug), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout :title="court.name">
        <PageHeader
            :title="court.name"
            :subtitle="court.description || 'No description has been added for this court yet.'"
            :home-href="route('admin.dashboard')"
            :breadcrumbs="[
                { label: 'Courts', href: route('admin.courts.index') },
                { label: court.name },
            ]"
            :back-href="route('admin.courts.index')"
            back-label="Back to courts"
        >
            <template #actions>
                <Button
                    v-if="canUpdate"
                    :variant="court.is_active ? 'secondary' : 'success'"
                    @click="toggleStatus"
                >
                    <template #icon><Power :size="16" aria-hidden="true" /></template>
                    {{ court.is_active ? 'Deactivate' : 'Activate' }}
                </Button>

                <Button v-if="canUpdate" :href="route('admin.courts.edit', court.slug)">
                    <template #icon><Pencil :size="16" aria-hidden="true" /></template>
                    Edit
                </Button>

                <Button v-if="canDelete" variant="danger" @click="destroy">
                    <template #icon><Trash2 :size="16" aria-hidden="true" /></template>
                    Delete
                </Button>
            </template>
        </PageHeader>

        <!-- Headline numbers -->
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Open slots"
                :value="stats.available_slots ?? 0"
                :icon="CalendarClock"
                tone="success"
                :hint="`of ${stats.total_slots ?? 0} slots in total`"
            />
            <StatCard
                label="Total bookings"
                :value="stats.total_bookings ?? 0"
                :icon="ClipboardList"
                tone="brand"
                :hint="`${stats.pending_bookings ?? 0} awaiting action`"
            />
            <StatCard
                label="Confirmed"
                :value="stats.confirmed_bookings ?? 0"
                :icon="CircleCheck"
                tone="info"
                hint="Confirmed or completed"
            />
            <StatCard
                label="Revenue"
                :value="peso.format(stats.revenue ?? 0)"
                :icon="Wallet"
                tone="warn"
                hint="From confirmed bookings"
            />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Profile -->
            <div class="space-y-6 lg:col-span-1">
                <Card padding="none">
                    <img
                        v-if="court.photo_url"
                        :src="court.photo_url"
                        :alt="`Photo of ${court.name}`"
                        class="aspect-[16/10] w-full rounded-t-xl object-cover"
                    />
                    <div
                        v-else
                        class="flex aspect-[16/10] w-full items-center justify-center rounded-t-xl bg-ink-50 text-ink-300"
                        aria-hidden="true"
                    >
                        <ImageOff :size="34" :stroke-width="1.5" />
                    </div>

                    <div class="space-y-4 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-ink-900">
                                    {{ court.name }}
                                </p>
                                <p class="mt-0.5 font-mono text-xs text-ink-500">
                                    {{ court.code }}
                                </p>
                            </div>
                            <Badge :status="court.is_active" size="md" />
                        </div>

                        <dl class="space-y-3 border-t border-ink-200/70 pt-4 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <dt class="inline-flex items-center gap-2 text-ink-500">
                                    <Wallet :size="14" aria-hidden="true" />
                                    Pricing
                                </dt>
                                <dd class="text-ink-800">
                                    Club-wide
                                </dd>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <dt class="inline-flex items-center gap-2 text-ink-500">
                                    <ListOrdered :size="14" aria-hidden="true" />
                                    Display order
                                </dt>
                                <dd class="text-ink-800 tabular-nums">{{ court.sort_order }}</dd>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <dt class="inline-flex items-center gap-2 text-ink-500">
                                    <Hash :size="14" aria-hidden="true" />
                                    Public URL
                                </dt>
                                <dd class="min-w-0 truncate font-mono text-xs text-ink-800">
                                    /courts/{{ court.slug }}
                                </dd>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-ink-500">Added</dt>
                                <dd class="text-ink-800">{{ formatDate(court.created_at) }}</dd>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-ink-500">Last updated</dt>
                                <dd class="text-ink-800">{{ formatDate(court.updated_at) }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Slot breakdown -->
                <Card title="Slot inventory" subtitle="Across every date on the calendar.">
                    <div class="space-y-4">
                        <div>
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-xs font-medium text-ink-500">
                                    Booked capacity
                                </span>
                                <span class="text-sm font-semibold text-ink-900 tabular-nums">
                                    {{ slotUsage }}%
                                </span>
                            </div>
                            <div
                                class="mt-2 h-2 w-full overflow-hidden rounded-full bg-ink-100"
                                role="progressbar"
                                :aria-valuenow="slotUsage"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-label="Share of slots already booked"
                            >
                                <div
                                    class="h-full rounded-full bg-brand-600 transition-[width] duration-300 ease-[var(--ease-out-soft)]"
                                    :style="{ width: `${slotUsage}%` }"
                                />
                            </div>
                        </div>

                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-success-50 px-3 py-2.5">
                                <dt class="text-xs font-medium text-success-700">Available</dt>
                                <dd class="mt-0.5 text-lg font-semibold text-success-700 tabular-nums">
                                    {{ stats.available_slots ?? 0 }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-brand-50 px-3 py-2.5">
                                <dt class="text-xs font-medium text-brand-700">Booked</dt>
                                <dd class="mt-0.5 text-lg font-semibold text-brand-700 tabular-nums">
                                    {{ stats.booked_slots ?? 0 }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-ink-100 px-3 py-2.5">
                                <dt class="text-xs font-medium text-ink-600">Blocked</dt>
                                <dd class="mt-0.5 text-lg font-semibold text-ink-700 tabular-nums">
                                    {{ stats.blocked_slots ?? 0 }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-ink-50 px-3 py-2.5">
                                <dt class="text-xs font-medium text-ink-600">Total</dt>
                                <dd class="mt-0.5 text-lg font-semibold text-ink-900 tabular-nums">
                                    {{ stats.total_slots ?? 0 }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </Card>
            </div>

            <!-- Recent bookings -->
            <div class="space-y-6 lg:col-span-2">
                <Card
                    padding="none"
                    title="Recent bookings"
                    subtitle="The ten most recent reservations for this court."
                >
                    <template #actions>
                        <Button
                            size="sm"
                            variant="secondary"
                            :href="route('admin.bookings.index')"
                        >
                            View all
                        </Button>
                    </template>

                    <EmptyState
                        v-if="recentBookings.length === 0"
                        :icon="ClipboardList"
                        size="sm"
                        title="No bookings yet"
                        description="Once this court has open slots, reservations will show up here."
                    />

                    <ul v-else class="divide-y divide-ink-200/70">
                        <li
                            v-for="booking in recentBookings"
                            :key="booking.id"
                            class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 transition-colors duration-150 ease-[var(--ease-out-soft)] hover:bg-ink-50"
                        >
                            <span
                                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-500"
                                aria-hidden="true"
                            >
                                <Ticket :size="16" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <Link
                                    :href="route('admin.bookings.show', booking.code)"
                                    class="block truncate text-sm font-medium text-ink-900 transition-colors hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                                >
                                    {{ booking.customer_name }}
                                </Link>
                                <p class="mt-0.5 truncate font-mono text-xs text-ink-500">
                                    {{ booking.code }}
                                </p>
                            </div>

                            <div class="min-w-0 text-xs text-ink-500">
                                <p class="whitespace-nowrap">
                                    {{ booking.slot ? formatDate(booking.slot.slot_date) : '—' }}
                                </p>
                                <p class="truncate">
                                    {{ booking.slot ? booking.slot.time_range : 'Slot removed' }}
                                    <span v-if="booking.slot_count > 1" class="text-ink-400">
                                        +{{ booking.slot_count - 1 }} more
                                    </span>
                                </p>
                            </div>

                            <span
                                class="shrink-0 text-sm font-semibold text-ink-900 tabular-nums"
                            >
                                {{ peso.format(booking.amount) }}
                            </span>

                            <Badge :status="booking.status" />
                        </li>
                    </ul>

                    <template #footer>
                        <p class="text-xs text-ink-500">
                            Latest activity: {{ formatDateTime(recentBookings[0]?.created_at) }}
                        </p>
                    </template>
                </Card>

                <!-- Deletion guidance -->
                <Card v-if="canDelete" title="Danger zone">
                    <div class="flex flex-wrap items-start gap-4">
                        <span
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-danger-50 text-danger-600"
                            aria-hidden="true"
                        >
                            <Ban :size="18" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-ink-900">Delete this court</p>
                            <p class="mt-1 text-sm leading-relaxed text-ink-500">
                                <template v-if="(stats.live_bookings ?? 0) > 0">
                                    This court has
                                    <span class="font-semibold text-ink-800">
                                        {{ stats.live_bookings }}
                                    </span>
                                    active or upcoming booking{{
                                        stats.live_bookings === 1 ? '' : 's'
                                    }}, so it cannot be deleted yet. Deactivate it instead — that
                                    hides it from customers immediately while those reservations
                                    stand.
                                </template>
                                <template v-else>
                                    Deleting moves the court to the bin and takes it off the public
                                    site. Its booking history is kept for reporting.
                                </template>
                            </p>
                        </div>

                        <Button
                            variant="danger"
                            size="sm"
                            :disabled="(stats.live_bookings ?? 0) > 0"
                            @click="destroy"
                        >
                            <template #icon><Trash2 :size="15" aria-hidden="true" /></template>
                            Delete court
                        </Button>
                    </div>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
