<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    CircleCheck,
    CloudUpload,
    Copy,
    ImageOff,
    Info,
    Landmark,
    QrCode,
    RotateCcw,
    Save,
    Trash2,
    Wallet,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/Alert.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormFileUpload from '@/Components/FormFileUpload.vue';
import FormInput from '@/Components/FormInput.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SettingsNav from './Partials/SettingsNav.vue';
import { usePermissions } from '@/Composables/usePermissions';

/*
| Payment settings.
|
| Checkout offers two ways to pay. GCash is mandatory — it is the live method
| and must never become unconfigurable. GoTyme is a second QR the customer may
| scan instead, and it is entirely optional: a site that leaves every GoTyme
| field blank behaves exactly as it did before the method existed.
|
| The right-hand column renders the customer-facing panel from the *live form
| state*, so the admin sees the exact QRs, numbers and copy a customer will get
| before committing.
*/

const props = defineProps({
    settings: { type: Object, required: true },
});

const { can } = usePermissions();

const canUpdate = computed(() => can('settings.update'));

/* ------------------------------------------------------------------ */
/* Form                                                                */
/* ------------------------------------------------------------------ */

/** `_method` spoofs PUT so the multipart upload can go out as a POST. */
const initial = () => ({
    _method: 'put',
    gcash_account_name: props.settings.gcash_account_name ?? '',
    gcash_account_number: props.settings.gcash_account_number ?? '',
    gotyme_account_name: props.settings.gotyme_account_name ?? '',
    gotyme_account_number: props.settings.gotyme_account_number ?? '',
    payment_instructions: props.settings.payment_instructions ?? '',
    gcash_qr: null,
    remove_gcash_qr: false,
    gotyme_qr: null,
    remove_gotyme_qr: false,
});

const form = useForm(initial());

const dirty = computed(() => canUpdate.value && form.isDirty);

function submit() {
    if (!canUpdate.value || form.processing) {
        return;
    }

    form
        .transform((data) => ({
            ...data,
            remove_gcash_qr: data.remove_gcash_qr ? 1 : 0,
            remove_gotyme_qr: data.remove_gotyme_qr ? 1 : 0,
        }))
        .post(route('admin.settings.payment.update'), {
            forceFormData: true,
            preserveScroll: true,
            // Props are already the freshly saved values here, so rebasing the
            // defaults is what clears the dirty flag and the pending upload.
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
/* QR handling + live preview                                          */
/* ------------------------------------------------------------------ */

/*
| Both methods need the same pending-upload preview, so the object-URL
| bookkeeping is built once and instantiated per method rather than copied.
| Each slot owns at most one live URL: `revoke()` runs before every
| replacement, on every clear, and again on unmount, so neither slot can leak
| one when the admin swaps files back and forth or navigates away mid-edit.
*/
function qrSlot(prefix) {
    const fileKey = `${prefix}_qr`;
    const removeKey = `remove_${prefix}_qr`;
    const storedKey = `${prefix}_qr_url`;

    const objectUrl = ref(null);

    function revoke() {
        if (objectUrl.value) {
            URL.revokeObjectURL(objectUrl.value);
            objectUrl.value = null;
        }
    }

    watch(
        () => form[fileKey],
        (file) => {
            revoke();

            if (file instanceof File && file.type.startsWith('image/')) {
                objectUrl.value = URL.createObjectURL(file);
                form[removeKey] = false;
            }
        },
    );

    return {
        revoke,
        /** The URL the preview should paint: pending upload → stored → nothing. */
        url: computed(() => {
            if (objectUrl.value) {
                return objectUrl.value;
            }

            return form[removeKey] ? null : (props.settings[storedKey] ?? null);
        }),
        hasStored: computed(() => Boolean(props.settings[storedKey])),
        remove() {
            form[fileKey] = null;
            form[removeKey] = true;
        },
        keep() {
            form[removeKey] = false;
        },
    };
}

// Destructured flat so the template reads the computeds directly — refs nested
// inside a plain object are not unwrapped by the template compiler.
const {
    revoke: revokeGcashQr,
    url: gcashQrUrl,
    hasStored: hasStoredGcashQr,
    remove: removeGcashQr,
    keep: keepGcashQr,
} = qrSlot('gcash');

const {
    revoke: revokeGotymeQr,
    url: gotymeQrUrl,
    hasStored: hasStoredGotymeQr,
    remove: removeGotymeQr,
    keep: keepGotymeQr,
} = qrSlot('gotyme');

onBeforeUnmount(() => {
    revokeGcashQr();
    revokeGotymeQr();
});

/*
| Inertia reports a single progress figure for the whole multipart request, so
| handing it to both uploads would animate a bar on the untouched card too.
| Only the slot holding a pending file gets to show it.
*/
function uploadProgress(file) {
    return file instanceof File ? (form.progress?.percentage ?? null) : null;
}

/* ------------------------------------------------------------------ */
/* Presentation helpers                                                */
/* ------------------------------------------------------------------ */

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
});

/** A representative amount so the preview reads like a real checkout. */
const SAMPLE_AMOUNT = 450;

/** 09171234567 → 0917 123 4567, which is how customers read it back. */
const gcashPrettyNumber = computed(() => {
    const digits = String(form.gcash_account_number ?? '').replace(/\D+/g, '');

    if (digits.length !== 11) {
        return form.gcash_account_number || '—';
    }

    return `${digits.slice(0, 4)} ${digits.slice(4, 7)} ${digits.slice(7)}`;
});

/*
| GoTyme is a digital bank, not a wallet: its account number is not a
| Philippine mobile number, so the 0917 123 4567 grouping above would
| misrepresent it and invite the customer to mistype it. It renders verbatim.
*/
const gotymeDisplayNumber = computed(() => form.gotyme_account_number || '—');

/** Instruction copy split into steps, with any "1." prefix stripped. */
const instructionLines = computed(() =>
    String(form.payment_instructions ?? '')
        .split('\n')
        .map((line) => line.trim().replace(/^\d+[.)]\s*/, ''))
        .filter(Boolean),
);

/*
| One entry per method the customer will actually be offered, GCash first,
| using the same "has a QR or an account number" test the checkout controller
| applies — a half-filled GoTyme section must never paint an empty card.
|
| With nothing configured at all that list would be empty and the panel would
| have nothing left to teach, so it falls back to the bare GCash card whose
| placeholders spell out what is still missing.
*/
const previewMethods = computed(() => {
    const gcash = {
        key: 'gcash',
        label: 'GCash',
        icon: Wallet,
        qrUrl: gcashQrUrl.value,
        accountName: form.gcash_account_name || '—',
        accountNumber: gcashPrettyNumber.value,
        accountNumberLabel: 'GCash number',
        scanHint: 'Scan with the GCash app, or send to the account below.',
        published: Boolean(gcashQrUrl.value || form.gcash_account_number),
    };

    const gotyme = {
        key: 'gotyme',
        label: 'GoTyme',
        icon: Landmark,
        qrUrl: gotymeQrUrl.value,
        accountName: form.gotyme_account_name || '—',
        accountNumber: gotymeDisplayNumber.value,
        accountNumberLabel: 'Account number',
        scanHint: 'Scan with the GoTyme app, or send to the account below.',
        published: Boolean(gotymeQrUrl.value || form.gotyme_account_number),
    };

    const published = [gcash, gotyme].filter((method) => method.published);

    return published.length > 0 ? published : [gcash];
});

const previewMethodKey = ref('gcash');

/*
| Falling back to the first entry instead of watching the list keeps the panel
| honest the moment an admin clears the GoTyme section while previewing it —
| there is never a selected key pointing at a method that no longer exists.
*/
const activeMethod = computed(
    () =>
        previewMethods.value.find((method) => method.key === previewMethodKey.value) ??
        previewMethods.value[0],
);

const gcashComplete = computed(
    () =>
        Boolean(gcashQrUrl.value) &&
        Boolean(form.gcash_account_name) &&
        /^09\d{9}$/.test(String(form.gcash_account_number ?? '').replace(/\D+/g, '')),
);

/** Touching any GoTyme field is a commitment to finish the method. */
const gotymeStarted = computed(() =>
    Boolean(gotymeQrUrl.value || form.gotyme_account_name || form.gotyme_account_number),
);

/*
| Deliberately NOT "all three fields filled". Two independent rules decide
| whether a GoTyme section is finished, and this has to agree with both or the
| footer contradicts what the admin will actually get:
|
|   - the checkout publishes a method that carries a QR *or* an account number
|     (PublicBookingController::paymentMethods()), so a QR-only GoTyme is a
|     complete, publishable method — telling the admin to add account details
|     they do not have would be wrong;
|   - the server pairs the name and number with required_with, so either both
|     travel or neither does.
|
| `previewMethods` above already applies the first rule; this keeps the footer
| from calling the same configuration broken.
*/
const gotymeComplete = computed(() => {
    const hasName = Boolean(form.gotyme_account_name);
    const hasNumber = Boolean(form.gotyme_account_number);

    // required_with cuts both ways — neither field may be published alone.
    if (hasName !== hasNumber) {
        return false;
    }

    if (hasNumber && !/^\d{6,20}$/.test(String(form.gotyme_account_number ?? '').replace(/\D+/g, ''))) {
        return false;
    }

    // Something the customer can act on: a QR to scan or a number to send to.
    return Boolean(gotymeQrUrl.value) || hasNumber;
});

/*
| "Fully configured" still means a customer can finish paying: GCash whole,
| instructions written, and GoTyme either untouched or finished. A stray GoTyme
| account name with no number behind it would fail the save outright.
*/
const previewReady = computed(
    () =>
        gcashComplete.value &&
        instructionLines.value.length > 0 &&
        (!gotymeStarted.value || gotymeComplete.value),
);

/** Name the half-finished method rather than sending the admin hunting. */
const previewMessage = computed(() => {
    if (previewReady.value) {
        return gotymeStarted.value
            ? 'Checkout is fully configured — customers can pay with GCash or GoTyme.'
            : 'Checkout is fully configured.';
    }

    if (gcashComplete.value && instructionLines.value.length > 0) {
        return 'GoTyme is half-finished — it needs a QR, or an account name and number together. Clear every GoTyme field to publish GCash only.';
    }

    return 'Add a QR, account details and instructions to complete checkout.';
});
</script>

<template>
    <AppLayout title="Payment settings">
        <PageHeader
            title="Payment settings"
            subtitle="The payment details and instructions every customer sees at checkout."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Payment' }]"
            :home-href="route('admin.dashboard')"
        />

        <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-8">
            <SettingsNav :dirty="dirty" />

            <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_23rem] xl:items-start">
                <!-- ------------------------------------------------ -->
                <!-- Form                                              -->
                <!-- ------------------------------------------------ -->
                <form
                    id="payment-settings-form"
                    class="min-w-0 space-y-6"
                    novalidate
                    @submit.prevent="submit"
                >
                    <Alert
                        v-if="!canUpdate"
                        variant="info"
                        title="Read only"
                        message="You can review the payment configuration but not change it. Ask an administrator for the settings.update permission."
                    />

                    <Card
                        title="GCash QR code"
                        subtitle="Scanned by the customer to pay. PNG, JPG or WebP, up to 2MB."
                    >
                        <template #actions>
                            <Badge tone="brand" size="xs" :dot="false" label="Required" />
                        </template>

                        <FormFileUpload
                            v-model="form.gcash_qr"
                            label="QR image"
                            accept="image/png,image/jpeg,image/webp"
                            :max-size="2"
                            :square="true"
                            :disabled="!canUpdate"
                            :existing-url="form.remove_gcash_qr ? null : settings.gcash_qr_url"
                            :progress="uploadProgress(form.gcash_qr)"
                            :error="form.errors.gcash_qr"
                            hint="Export the QR straight from the GCash app so the code stays crisp. Minimum 200 x 200 pixels."
                        />

                        <div
                            v-if="canUpdate && (hasStoredGcashQr || form.remove_gcash_qr)"
                            class="mt-4 flex flex-wrap items-center gap-3"
                        >
                            <Button
                                v-if="!form.remove_gcash_qr && !form.gcash_qr"
                                variant="ghost"
                                size="sm"
                                @click="removeGcashQr"
                            >
                                <template #icon><Trash2 :size="14" /></template>
                                Remove current QR
                            </Button>

                            <template v-if="form.remove_gcash_qr">
                                <span class="text-xs font-medium text-danger-600">
                                    The stored QR will be deleted when you save.
                                </span>
                                <Button variant="ghost" size="sm" @click="keepGcashQr">
                                    <template #icon><RotateCcw :size="14" /></template>
                                    Keep it
                                </Button>
                            </template>
                        </div>
                    </Card>

                    <Card
                        title="GCash receiving account"
                        subtitle="Shown beside the QR for customers who prefer to send manually."
                    >
                        <template #actions>
                            <Badge tone="brand" size="xs" :dot="false" label="Required" />
                        </template>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <FormInput
                                v-model="form.gcash_account_name"
                                label="Account name"
                                placeholder="Juan Dela Cruz"
                                required
                                :disabled="!canUpdate"
                                :icon="Wallet"
                                :error="form.errors.gcash_account_name"
                                hint="Must match the name registered to the GCash wallet."
                            />

                            <FormInput
                                v-model="form.gcash_account_number"
                                label="GCash number"
                                placeholder="0917 123 4567"
                                inputmode="tel"
                                autocomplete="off"
                                required
                                :disabled="!canUpdate"
                                :error="form.errors.gcash_account_number"
                                hint="Philippine mobile number. +63 and 63 prefixes are accepted and normalised."
                            />
                        </div>
                    </Card>

                    <!--
                        Spelled out because the account fields validate as a
                        pair: an admin who types a GoTyme account name and
                        nothing else would otherwise be baffled by an error on a
                        field they believed was optional.
                    -->
                    <Alert variant="brand" title="GoTyme is optional">
                        Leave the two cards below completely empty and checkout keeps offering
                        GCash only, exactly as it does today. Start filling them in and they
                        become a set: the account name and number are required together, and the
                        preview calls checkout complete once GoTyme has a QR, or an account name
                        and number together.
                    </Alert>

                    <Card
                        title="GoTyme QR code"
                        subtitle="A second QR the customer can scan instead. PNG, JPG or WebP, up to 2MB."
                    >
                        <template #actions>
                            <Badge tone="ink" size="xs" :dot="false" label="Optional" />
                        </template>

                        <FormFileUpload
                            v-model="form.gotyme_qr"
                            label="QR image"
                            accept="image/png,image/jpeg,image/webp"
                            :max-size="2"
                            :square="true"
                            :disabled="!canUpdate"
                            :existing-url="form.remove_gotyme_qr ? null : settings.gotyme_qr_url"
                            :progress="uploadProgress(form.gotyme_qr)"
                            :error="form.errors.gotyme_qr"
                            hint="Export the QR straight from the GoTyme app. Leave this empty to keep GCash as the only method."
                        />

                        <div
                            v-if="canUpdate && (hasStoredGotymeQr || form.remove_gotyme_qr)"
                            class="mt-4 flex flex-wrap items-center gap-3"
                        >
                            <Button
                                v-if="!form.remove_gotyme_qr && !form.gotyme_qr"
                                variant="ghost"
                                size="sm"
                                @click="removeGotymeQr"
                            >
                                <template #icon><Trash2 :size="14" /></template>
                                Remove current QR
                            </Button>

                            <template v-if="form.remove_gotyme_qr">
                                <span class="text-xs font-medium text-danger-600">
                                    The stored QR will be deleted when you save.
                                </span>
                                <Button variant="ghost" size="sm" @click="keepGotymeQr">
                                    <template #icon><RotateCcw :size="14" /></template>
                                    Keep it
                                </Button>
                            </template>
                        </div>
                    </Card>

                    <Card
                        title="GoTyme receiving account"
                        subtitle="Shown beside the GoTyme QR. Only needed if you publish GoTyme."
                    >
                        <template #actions>
                            <Badge tone="ink" size="xs" :dot="false" label="Optional" />
                        </template>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <FormInput
                                v-model="form.gotyme_account_name"
                                label="Account name"
                                label-hint="Optional"
                                placeholder="Juan Dela Cruz"
                                :disabled="!canUpdate"
                                :icon="Landmark"
                                :error="form.errors.gotyme_account_name"
                                hint="Must match the name on the GoTyme bank account."
                            />

                            <FormInput
                                v-model="form.gotyme_account_number"
                                label="Account number"
                                label-hint="Optional"
                                placeholder="1234567890"
                                inputmode="numeric"
                                autocomplete="off"
                                :disabled="!canUpdate"
                                :error="form.errors.gotyme_account_number"
                                hint="GoTyme is a bank, not a wallet — this is the account number, 6 to 20 digits, never a mobile number."
                            />
                        </div>
                    </Card>

                    <Card
                        title="Payment instructions"
                        subtitle="Rendered as a numbered checklist on the payment page, for both methods."
                    >
                        <FormTextarea
                            v-model="form.payment_instructions"
                            label="Instructions"
                            :rows="7"
                            :maxlength="2000"
                            show-count
                            required
                            :disabled="!canUpdate"
                            :error="form.errors.payment_instructions"
                            placeholder="One step per line."
                            hint="One step per line. Leading numbers are added automatically, so you can drop them."
                        />
                    </Card>

                    <!-- Sticky save bar — only while there is something to save -->
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
                                    form="payment-settings-form"
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
                <!-- Customer preview                                  -->
                <!-- ------------------------------------------------ -->
                <aside class="min-w-0 xl:sticky xl:top-24">
                    <Card padding="none">
                        <template #header>
                            <div class="min-w-0">
                                <h3 class="flex items-center gap-2 text-sm font-semibold text-ink-900">
                                    <QrCode :size="16" class="text-brand-600" aria-hidden="true" />
                                    Customer preview
                                </h3>
                                <p class="mt-0.5 text-xs text-ink-500">
                                    Live — exactly what the checkout renders.
                                </p>
                            </div>
                        </template>

                        <div class="bg-ink-50 p-4">
                            <!-- The panel below mirrors the public payment page -->
                            <div class="rounded-xl border border-ink-200 bg-white p-5 shadow-card">
                                <p class="text-xs font-medium tracking-wide text-ink-500 uppercase">
                                    Amount due
                                </p>
                                <p class="mt-1 text-2xl font-semibold text-ink-900">
                                    {{ peso.format(SAMPLE_AMOUNT) }}
                                </p>
                                <p class="mt-1 text-xs text-ink-400">
                                    Sample amount · booking AH-7K4M2QX9
                                </p>

                                <!-- The switcher only appears once there is a real choice -->
                                <div
                                    v-if="previewMethods.length > 1"
                                    class="mt-4 grid grid-cols-2 gap-1 rounded-xl bg-ink-100 p-1"
                                    role="tablist"
                                    aria-label="Preview payment method"
                                >
                                    <button
                                        v-for="method in previewMethods"
                                        :key="method.key"
                                        type="button"
                                        role="tab"
                                        :aria-selected="method.key === activeMethod.key"
                                        :class="[
                                            'inline-flex h-8 cursor-pointer items-center justify-center gap-1.5 rounded-lg text-xs font-semibold transition-colors duration-150 ease-[var(--ease-out-soft)] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500',
                                            method.key === activeMethod.key
                                                ? 'bg-white text-ink-900 shadow-card'
                                                : 'text-ink-500 hover:text-ink-800',
                                        ]"
                                        @click="previewMethodKey = method.key"
                                    >
                                        <component :is="method.icon" :size="13" aria-hidden="true" />
                                        {{ method.label }}
                                    </button>
                                </div>

                                <div class="mt-5 flex flex-col items-center">
                                    <img
                                        v-if="activeMethod.qrUrl"
                                        :src="activeMethod.qrUrl"
                                        :alt="`${activeMethod.label} QR code preview`"
                                        class="h-44 w-44 rounded-xl border border-ink-200 bg-white object-contain p-2"
                                    />
                                    <div
                                        v-else
                                        class="flex h-44 w-44 flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-ink-300 bg-ink-50 text-center"
                                    >
                                        <ImageOff :size="22" class="text-ink-400" aria-hidden="true" />
                                        <span class="px-4 text-xs font-medium text-ink-500">
                                            No QR uploaded yet
                                        </span>
                                    </div>

                                    <p class="mt-3 text-center text-xs text-ink-500">
                                        {{ activeMethod.scanHint }}
                                    </p>
                                </div>

                                <dl class="mt-5 space-y-2.5 rounded-lg bg-ink-50 p-3.5">
                                    <div class="flex items-start justify-between gap-3">
                                        <dt class="text-xs text-ink-500">Account name</dt>
                                        <dd
                                            class="min-w-0 text-right text-sm font-medium break-words text-ink-900"
                                        >
                                            {{ activeMethod.accountName }}
                                        </dd>
                                    </div>
                                    <div class="flex items-start justify-between gap-3">
                                        <dt class="text-xs text-ink-500">
                                            {{ activeMethod.accountNumberLabel }}
                                        </dt>
                                        <dd
                                            class="flex min-w-0 items-center gap-1.5 text-right text-sm font-semibold break-all text-ink-900 tabular-nums"
                                        >
                                            {{ activeMethod.accountNumber }}
                                            <Copy
                                                :size="13"
                                                class="shrink-0 text-ink-400"
                                                aria-hidden="true"
                                            />
                                        </dd>
                                    </div>
                                </dl>

                                <div class="mt-5">
                                    <p class="text-xs font-semibold text-ink-700">How to pay</p>
                                    <ol
                                        v-if="instructionLines.length"
                                        class="mt-2 space-y-2 text-xs leading-relaxed text-ink-600"
                                    >
                                        <li
                                            v-for="(line, index) in instructionLines"
                                            :key="index"
                                            class="flex gap-2.5"
                                        >
                                            <span
                                                class="mt-px inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-[10px] font-bold text-brand-700"
                                                aria-hidden="true"
                                            >
                                                {{ index + 1 }}
                                            </span>
                                            <span class="min-w-0">{{ line }}</span>
                                        </li>
                                    </ol>
                                    <p v-else class="mt-2 text-xs text-ink-400 italic">
                                        No instructions yet — customers will be left guessing.
                                    </p>
                                </div>

                                <div class="mt-5 border-t border-ink-200 pt-4">
                                    <p class="text-xs font-medium text-ink-700">
                                        Screenshot of your receipt
                                    </p>
                                    <div
                                        class="mt-1.5 flex h-16 w-full cursor-not-allowed items-center justify-center gap-2 rounded-lg border-2 border-dashed border-ink-200 bg-ink-50 text-xs text-ink-400"
                                        aria-hidden="true"
                                    >
                                        <CloudUpload :size="16" class="shrink-0" aria-hidden="true" />
                                        Click to upload
                                    </div>
                                    <div
                                        class="mt-2.5 flex h-9 w-full items-center justify-center rounded-xl bg-noir-900 text-sm font-medium text-white opacity-70"
                                        aria-hidden="true"
                                    >
                                        Submit payment
                                    </div>
                                </div>
                            </div>
                        </div>

                        <template #footer>
                            <p
                                :class="[
                                    'flex items-center gap-2 text-xs font-medium',
                                    previewReady ? 'text-success-700' : 'text-warn-700',
                                ]"
                            >
                                <component
                                    :is="previewReady ? CircleCheck : Info"
                                    :size="14"
                                    class="shrink-0"
                                    aria-hidden="true"
                                />
                                {{ previewMessage }}
                            </p>
                        </template>
                    </Card>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
