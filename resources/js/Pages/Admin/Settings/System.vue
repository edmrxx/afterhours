<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { Clock, Coins, Hash, Hourglass, Info, Mail, Moon, Save, ShieldCheck, Sun, Timer } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormInput from '@/Components/FormInput.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SettingsNav from './Partials/SettingsNav.vue';
import { usePermissions } from '@/Composables/usePermissions';

/*
| Booking settings — the two timers behind the auto-release feature: how long
| an unpaid reservation holds its slot, and how much longer that hold is
| extended once a payment reference has been submitted so an admin has time to
| verify it. `bookings:release-holds` (running every minute) is what actually
| acts on these values.
*/

const props = defineProps({
    settings: { type: Object, required: true },
    // One entry per court category, each naming the two form fields its row
    // renders. Supplied by the server so this screen never hard-codes a
    // category list that could drift from Court::CATEGORIES.
    pricingCategories: { type: Array, default: () => [] },
});

const { can } = usePermissions();

const canUpdate = computed(() => can('settings.update'));

/* ------------------------------------------------------------------ */
/* Form                                                                */
/* ------------------------------------------------------------------ */

const initial = () => {
    const values = {
        booking_hold_minutes: Number(props.settings.booking_hold_minutes ?? 30),
        booking_verification_hold_minutes: Number(props.settings.booking_verification_hold_minutes ?? 720),
        booking_code_prefix: String(props.settings.booking_code_prefix ?? 'AH'),
        owner_notification_email: String(props.settings.owner_notification_email ?? ''),
        // The peak window is the only clock control, and every category shares
        // it — non-peak is every hour outside it, so there is no non-peak
        // window to type here.
        pricing_peak_start: String(props.settings.pricing_peak_start ?? '17:00'),
        pricing_peak_end: String(props.settings.pricing_peak_end ?? '00:00'),
    };

    // One money field per (category, tier) cell. The field NAMES come from the
    // server, so the form can never post a key the validator is not expecting.
    for (const category of props.pricingCategories) {
        values[category.non_peak_field] = Number(props.settings[category.non_peak_field] ?? 0);
        values[category.peak_field] = Number(props.settings[category.peak_field] ?? 0);
    }

    return values;
};

const form = useForm(initial());

const dirty = computed(() => canUpdate.value && form.isDirty);

function submit() {
    if (!canUpdate.value || form.processing) {
        return;
    }

    form.put(route('admin.settings.system.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(initial());
            form.reset();
            form.clearErrors();
        },
    });
}

function discard() {
    form.reset();
    form.clearErrors();
}

/* ------------------------------------------------------------------ */
/* Preview                                                             */
/* ------------------------------------------------------------------ */

function formatDuration(minutes) {
    const total = Math.max(0, Number(minutes) || 0);

    if (total < 60) {
        return `${total} min`;
    }

    const days = Math.floor(total / 1440);
    const hours = Math.floor((total % 1440) / 60);
    const mins = total % 60;

    return [days && `${days}d`, hours && `${hours}h`, mins && `${mins}m`].filter(Boolean).join(' ');
}

const holdDisplay = computed(() => formatDuration(form.booking_hold_minutes));
const verificationDisplay = computed(() => formatDuration(form.booking_verification_hold_minutes));

/** Uppercased live, matching how the server stores it — a customer never
 *  sees a lowercase code regardless of how the admin typed the prefix. */
const codePrefixDisplay = computed(() => String(form.booking_code_prefix ?? '').toUpperCase() || 'AH');
const sampleCode = computed(() => `${codePrefixDisplay.value}-K7M4XQ9B`);

/* ------------------------------------------------------------------ */
/* Pricing preview                                                     */
/* ------------------------------------------------------------------ */

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

/** "16:00" → "4:00 PM" for the read-only preview. */
function to12h(hhmm) {
    const [h, m] = String(hhmm ?? '').split(':').map((n) => Number(n));

    if (!Number.isFinite(h) || !Number.isFinite(m)) {
        return String(hhmm ?? '');
    }

    const suffix = h < 12 ? 'AM' : 'PM';
    const hour = h % 12 === 0 ? 12 : h % 12;

    return `${hour}:${String(m).padStart(2, '0')} ${suffix}`;
}

/**
 * The rate grid as the form and the live preview both read it: one row per
 * court category, each carrying its field names and its two current values.
 */
const rateRows = computed(() =>
    props.pricingCategories.map((category) => ({
        key: category.key,
        label: category.label,
        nonPeakField: category.non_peak_field,
        peakField: category.peak_field,
        nonPeak: Math.max(0, Number(form[category.non_peak_field]) || 0),
        peak: Math.max(0, Number(form[category.peak_field]) || 0),
    })),
);

// Non-peak is every hour outside peak, so its window is peak's exact complement.
const nonPeakWindow = computed(
    () => `${to12h(form.pricing_peak_end)} – ${to12h(form.pricing_peak_start)}`,
);
const peakWindow = computed(
    () => `${to12h(form.pricing_peak_start)} – ${to12h(form.pricing_peak_end)}`,
);

/**
 * A window whose start is later in the day than its end runs past midnight.
 * "17:00" > "00:00" is true, so a 5PM–midnight evening band counts as
 * crossing — which is exactly right: the band ends ON the midnight boundary.
 */
const peakCrossesMidnight = computed(
    () => String(form.pricing_peak_start ?? '') > String(form.pricing_peak_end ?? ''),
);

/** The cheapest hour on offer anywhere — what the public site advertises. */
const fromRate = computed(() => {
    const all = rateRows.value.flatMap((row) => [row.nonPeak, row.peak]);

    return all.length ? Math.min(...all) : 0;
});
</script>

<template>
    <AppLayout title="Booking settings">
        <PageHeader
            title="Booking settings"
            subtitle="How long an unpaid reservation holds its slot before it's released automatically."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Booking' }]"
            :home-href="route('admin.dashboard')"
        />

        <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-8">
            <SettingsNav :dirty="dirty" />

            <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_23rem] xl:items-start">
                <!-- ------------------------------------------------ -->
                <!-- Form                                              -->
                <!-- ------------------------------------------------ -->
                <form id="system-settings-form" class="min-w-0 space-y-6" @submit.prevent="submit">
                    <Alert
                        v-if="!canUpdate"
                        variant="info"
                        title="Read only"
                        message="You can review the booking settings but not change them. Ask an administrator for the settings.update permission."
                    />

                    <Card
                        title="Reservation hold"
                        subtitle="Counted from the moment a customer reserves a slot."
                    >
                        <FormInput
                            v-model="form.booking_hold_minutes"
                            type="number"
                            label="Hold time"
                            suffix="minutes"
                            min="5"
                            max="1440"
                            :disabled="!canUpdate"
                            :icon="Timer"
                            :error="form.errors.booking_hold_minutes"
                            hint="If the customer hasn't submitted a payment reference by the time this lapses, the slot is released automatically."
                        />
                    </Card>

                    <Card
                        title="Payment verification hold"
                        subtitle="Starts once the customer submits their payment reference."
                    >
                        <FormInput
                            v-model="form.booking_verification_hold_minutes"
                            type="number"
                            label="Verification hold time"
                            suffix="minutes"
                            min="30"
                            max="10080"
                            :disabled="!canUpdate"
                            :icon="Hourglass"
                            :error="form.errors.booking_verification_hold_minutes"
                            hint="Extra time given to check the payment before the slot is released. Must be at least as long as the reservation hold above."
                        />
                    </Card>

                    <Card
                        title="Booking code prefix"
                        subtitle="The letters at the front of every booking code, e.g. AH-K7M4XQ9B."
                    >
                        <FormInput
                            v-model="form.booking_code_prefix"
                            label="Prefix"
                            placeholder="AH"
                            :maxlength="10"
                            :disabled="!canUpdate"
                            :icon="Hash"
                            :error="form.errors.booking_code_prefix"
                            hint="Letters and numbers only, 2–10 characters. Shown in caps regardless of how you type it."
                        />
                    </Card>

                    <Card
                        title="Owner booking alerts"
                        subtitle="A personal heads-up email whenever a booking is placed or confirmed."
                    >
                        <FormInput
                            v-model="form.owner_notification_email"
                            type="email"
                            label="Notification email"
                            placeholder="you@example.com"
                            autocomplete="off"
                            :maxlength="150"
                            :disabled="!canUpdate"
                            :icon="Mail"
                            :error="form.errors.owner_notification_email"
                            hint="A short 'someone booked' reminder sent only to you — separate from the public contact email and from the customer's own confirmation. Leave blank to turn these alerts off."
                        />
                    </Card>

                    <Card
                        title="Court pricing"
                        subtitle="Each court type charges its own rates. Set the peak window once — it applies to every type — then the two rates each type charges."
                    >
                        <div class="space-y-6">
                            <!-- The single window every category shares. It sorts
                                 an hour into a tier; the rates below decide what
                                 that tier costs on each kind of court. -->
                            <div class="rounded-xl border border-ink-200 p-4">
                                <div class="mb-4 flex items-center gap-2">
                                    <span
                                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-noir-100 text-noir-700"
                                        aria-hidden="true"
                                    >
                                        <Moon :size="15" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink-800">Peak window</p>
                                        <p class="text-xs text-ink-500">Shared by every court type</p>
                                    </div>
                                </div>

                                <div class="grid gap-5 sm:grid-cols-2">
                                    <FormInput
                                        v-model="form.pricing_peak_start"
                                        label="Peak starts"
                                        type="time"
                                        :disabled="!canUpdate"
                                        :icon="Clock"
                                        :error="form.errors.pricing_peak_start"
                                    />
                                    <FormInput
                                        v-model="form.pricing_peak_end"
                                        label="Peak ends"
                                        type="time"
                                        :disabled="!canUpdate"
                                        :icon="Clock"
                                        :error="form.errors.pricing_peak_end"
                                    />
                                </div>

                                <p class="mt-3 inline-flex items-center gap-1.5 text-xs text-ink-500">
                                    <Sun :size="13" aria-hidden="true" />
                                    Non-peak is every hour outside that · {{ nonPeakWindow }}
                                </p>

                                <p
                                    v-if="peakCrossesMidnight"
                                    class="mt-2 inline-flex items-center gap-1.5 text-xs text-ink-500"
                                >
                                    <Moon :size="13" aria-hidden="true" />
                                    This window runs to or past midnight — that's allowed.
                                </p>
                            </div>

                            <!-- One block per court category. Rendered from the
                                 server's list, so a new category appears here
                                 without this template being touched. -->
                            <div
                                v-for="row in rateRows"
                                :key="row.key"
                                class="rounded-xl border border-ink-200 p-4"
                            >
                                <div class="mb-4 flex items-center gap-2">
                                    <span
                                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-graphite-50 text-graphite-600"
                                        aria-hidden="true"
                                    >
                                        <Coins :size="15" />
                                    </span>
                                    <p class="text-sm font-semibold text-ink-800">{{ row.label }}</p>
                                </div>

                                <div class="grid gap-5 sm:grid-cols-2">
                                    <FormInput
                                        v-model="form[row.nonPeakField]"
                                        label="Non-peak rate"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        prefix="₱"
                                        :disabled="!canUpdate"
                                        :icon="Sun"
                                        :error="form.errors[row.nonPeakField]"
                                        :hint="nonPeakWindow"
                                    />
                                    <FormInput
                                        v-model="form[row.peakField]"
                                        label="Peak rate"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        prefix="₱"
                                        :disabled="!canUpdate"
                                        :icon="Moon"
                                        :error="form.errors[row.peakField]"
                                        :hint="peakWindow"
                                    />
                                </div>
                            </div>

                            <p class="text-xs text-ink-500">
                                Which rates a court charges is set on the court itself — Courts › Edit ›
                                Court type. Adding another court of an existing type needs no change here.
                            </p>
                        </div>
                    </Card>

                    <Alert
                        variant="info"
                        title="Saving new pricing updates the whole schedule"
                        message="Changing any rate — or moving the peak window, which re-sorts hours between peak and non-peak — immediately reprices every available slot on every court, from today onward, each court at its own type's rates, so the public booking site always matches. Slots already on hold or booked keep the price the customer agreed to, and past dates are never touched. Hold times and the code prefix still apply to new bookings only."
                    />

                    <!-- Sticky save bar -->
                    <Transition
                        enter-active-class="transition duration-200 ease-[var(--ease-out-soft)]"
                        enter-from-class="opacity-0 translate-y-2"
                        leave-active-class="transition duration-150 ease-[var(--ease-out-soft)]"
                        leave-to-class="opacity-0 translate-y-2"
                    >
                        <div v-if="dirty" class="sticky bottom-4 z-30">
                            <div
                                class="flex flex-wrap items-center gap-3 rounded-xl border border-ink-200 bg-white/95 p-3 shadow-float backdrop-blur-md sm:px-4"
                            >
                                <span
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-warn-50 text-warn-600"
                                    aria-hidden="true"
                                >
                                    <Info :size="16" />
                                </span>
                                <p class="min-w-0 flex-1 text-sm font-medium text-ink-700">
                                    You have unsaved changes.
                                </p>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="form.processing"
                                    @click="discard"
                                >
                                    Discard
                                </Button>
                                <Button
                                    type="submit"
                                    size="sm"
                                    form="system-settings-form"
                                    :loading="form.processing"
                                >
                                    <template #icon><Save :size="14" /></template>
                                    Save changes
                                </Button>
                            </div>
                        </div>
                    </Transition>
                </form>

                <!-- ------------------------------------------------ -->
                <!-- Preview                                           -->
                <!-- ------------------------------------------------ -->
                <aside class="min-w-0 xl:sticky xl:top-24">
                    <Card padding="none" title="Timeline" subtitle="How a reservation plays out">
                        <div class="space-y-4 p-4">
                            <div class="flex items-start gap-3">
                                <span
                                    class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-warn-50 text-warn-600"
                                    aria-hidden="true"
                                >
                                    <Timer :size="16" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-ink-900">Slot reserved</p>
                                    <p class="mt-0.5 text-xs leading-relaxed text-ink-500">
                                        Held for <span class="font-semibold text-ink-700">{{ holdDisplay }}</span>
                                        while awaiting a payment reference.
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3">
                                <span
                                    class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-info-50 text-info-600"
                                    aria-hidden="true"
                                >
                                    <Hourglass :size="16" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-ink-900">Reference submitted</p>
                                    <p class="mt-0.5 text-xs leading-relaxed text-ink-500">
                                        Extended for
                                        <span class="font-semibold text-ink-700">{{ verificationDisplay }}</span>
                                        while an admin verifies it.
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3">
                                <span
                                    class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-success-50 text-success-600"
                                    aria-hidden="true"
                                >
                                    <ShieldCheck :size="16" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-ink-900">Confirmed or released</p>
                                    <p class="mt-0.5 text-xs leading-relaxed text-ink-500">
                                        The admin confirms the payment, or the slot returns to the market once
                                        the hold lapses.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <template #footer>
                            <p class="flex items-start gap-2 text-xs text-ink-500">
                                <Info :size="14" class="mt-0.5 shrink-0" aria-hidden="true" />
                                <span>Automatic release runs every minute — a lapsed hold rarely sits idle for long.</span>
                            </p>
                        </template>
                    </Card>

                    <Card class="mt-6" padding="none" title="Sample booking code">
                        <div class="flex items-center gap-3 p-4">
                            <span
                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-700"
                                aria-hidden="true"
                            >
                                <Hash :size="16" />
                            </span>
                            <p class="min-w-0 truncate font-mono text-sm font-semibold text-ink-900">
                                {{ sampleCode }}
                            </p>
                        </div>

                        <template #footer>
                            <p class="flex items-start gap-2 text-xs text-ink-500">
                                <Info :size="14" class="mt-0.5 shrink-0" aria-hidden="true" />
                                <span>New bookings only — codes already handed to customers keep their original prefix.</span>
                            </p>
                        </template>
                    </Card>

                    <Card class="mt-6" padding="none" title="Pricing" subtitle="What customers pay">
                        <div class="divide-y divide-ink-100">
                            <!-- One block per court type, so the two rate rows
                                 read as the published rate card does. -->
                            <div v-for="row in rateRows" :key="row.key" class="space-y-3 p-4">
                                <p class="text-sm font-semibold text-ink-800">{{ row.label }}</p>

                                <div class="flex items-center justify-between gap-3">
                                    <span class="inline-flex items-center gap-2 text-sm text-ink-700">
                                        <span
                                            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-graphite-50 text-graphite-600"
                                            aria-hidden="true"
                                        >
                                            <Sun :size="15" />
                                        </span>
                                        Non-peak
                                    </span>
                                    <span class="text-sm font-semibold text-ink-900">{{ peso.format(row.nonPeak) }}</span>
                                </div>
                                <p class="pl-9 text-xs text-ink-500">{{ nonPeakWindow }}</p>

                                <div class="flex items-center justify-between gap-3">
                                    <span class="inline-flex items-center gap-2 text-sm text-ink-700">
                                        <span
                                            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-noir-100 text-noir-700"
                                            aria-hidden="true"
                                        >
                                            <Moon :size="15" />
                                        </span>
                                        Peak
                                    </span>
                                    <span class="text-sm font-semibold text-ink-900">{{ peso.format(row.peak) }}</span>
                                </div>
                                <p class="pl-9 text-xs text-ink-500">{{ peakWindow }}</p>
                            </div>
                        </div>

                        <template #footer>
                            <p class="flex items-start gap-2 text-xs text-ink-500">
                                <Info :size="14" class="mt-0.5 shrink-0" aria-hidden="true" />
                                <span
                                    >The public site shows
                                    <span class="font-semibold text-ink-700">From {{ peso.format(fromRate) }}</span
                                    >, the cheapest hour on offer. Each court's own card shows the cheapest
                                    rate for its type.</span
                                >
                            </p>
                        </template>
                    </Card>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
