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
| Every card on this screen is rendered from the `methods` prop, which is
| PaymentMethodService::CATALOGUE — the same list the checkout, the validator
| and the seeder read. Adding a method there makes its card appear here with no
| change to this file, which is the point: the previous version hard-coded a
| section per method and a third one would have meant a third copy of the same
| ~90 lines.
|
| Exactly one method is flagged `required` (BDO — the account the club banks
| into); it must never become unconfigurable. Every other method is optional as
| a whole but all-or-nothing within itself, so a site that leaves one blank
| behaves exactly as if it never existed.
|
| The right-hand column renders the customer-facing panel from the *live form
| state*, so the admin sees the exact QRs, numbers and copy a customer will get
| before committing.
*/

const props = defineProps({
    settings: { type: Object, required: true },
    /**
     * [{ key, label, prefix, required, number_label, number_format,
     *    number_placeholder, name_hint, number_hint, qr_hint }]
     */
    methods: { type: Array, default: () => [] },
});

const { can } = usePermissions();

const canUpdate = computed(() => can('settings.update'));

/* ------------------------------------------------------------------ */
/* Form                                                                */
/* ------------------------------------------------------------------ */

/** `_method` spoofs PUT so the multipart upload can go out as a POST. */
const initial = () => {
    const fields = {
        _method: 'put',
        payment_instructions: props.settings.payment_instructions ?? '',
    };

    for (const method of props.methods) {
        fields[`${method.prefix}_account_name`] = props.settings[`${method.prefix}_account_name`] ?? '';
        fields[`${method.prefix}_account_number`] = props.settings[`${method.prefix}_account_number`] ?? '';
        fields[`${method.prefix}_qr`] = null;
        fields[`remove_${method.prefix}_qr`] = false;
    }

    return fields;
};

const form = useForm(initial());

const dirty = computed(() => canUpdate.value && form.isDirty);

function submit() {
    if (!canUpdate.value || form.processing) {
        return;
    }

    form
        .transform((data) => {
            // Multipart carries no booleans — a raw `false` would arrive as the
            // string "false", which is truthy on the server.
            const payload = { ...data };

            for (const method of props.methods) {
                const key = `remove_${method.prefix}_qr`;
                payload[key] = data[key] ? 1 : 0;
            }

            return payload;
        })
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

/*
| One slot per method, keyed by prefix. Built once at setup — qrSlot() registers
| a watcher, so it must never run inside a computed or a render.
|
| The template reaches these through the accessor functions below rather than
| the map directly: refs nested inside a plain object are NOT unwrapped by the
| template compiler, so `slots[prefix].url` would render "[object Object]".
*/
const qrSlots = Object.fromEntries(
    props.methods.map((method) => [method.prefix, qrSlot(method.prefix)]),
);

const qrUrlFor = (prefix) => qrSlots[prefix]?.url.value ?? null;
const hasStoredQr = (prefix) => qrSlots[prefix]?.hasStored.value ?? false;
const removeQr = (prefix) => qrSlots[prefix]?.remove();
const keepQr = (prefix) => qrSlots[prefix]?.keep();

onBeforeUnmount(() => {
    for (const slot of Object.values(qrSlots)) {
        slot.revoke();
    }
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
function prettyMobile(value) {
    const digits = String(value ?? '').replace(/\D+/g, '');

    if (digits.length !== 11) {
        return value || '—';
    }

    return `${digits.slice(0, 4)} ${digits.slice(4, 7)} ${digits.slice(7)}`;
}

const numberOf = (method) => form[`${method.prefix}_account_number`];
const nameOf = (method) => form[`${method.prefix}_account_name`];

/*
| A bank account number renders verbatim: BDO and GoTyme are banks, not
| wallets, so the 0917 123 4567 grouping above would misrepresent the number
| and invite the customer to mistype it. Only a mobile-keyed wallet is grouped.
*/
function displayNumber(method) {
    return method.number_format === 'mobile'
        ? prettyMobile(numberOf(method))
        : numberOf(method) || '—';
}

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
    const cards = props.methods.map((method) => ({
        key: method.key,
        // A wallet gets the wallet glyph, a bank the bank one — the icon is a
        // property of what the method IS, not of which one it happens to be.
        icon: method.number_format === 'mobile' ? Wallet : Landmark,
        label: method.label,
        qrUrl: qrUrlFor(method.prefix),
        accountName: nameOf(method) || '—',
        accountNumber: displayNumber(method),
        accountNumberLabel: method.number_label,
        scanHint: `Scan with the ${method.label} app, or send to the account below.`,
        published: Boolean(qrUrlFor(method.prefix) || numberOf(method)),
    }));

    const published = cards.filter((card) => card.published);

    // Nothing configured at all would leave the panel with nothing to teach, so
    // it falls back to the first card, whose placeholders spell out what is
    // still missing.
    return published.length > 0 ? published : cards.slice(0, 1);
});

const previewMethodKey = ref(props.methods[0]?.key ?? null);

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

const NUMBER_PATTERNS = { bank: /^\d{6,20}$/, mobile: /^09\d{9}$/ };

const numberValid = (method) =>
    (NUMBER_PATTERNS[method.number_format] ?? NUMBER_PATTERNS.bank).test(
        String(numberOf(method) ?? '').replace(/\D+/g, ''),
    );

/** Touching any field of an optional method is a commitment to finish it. */
const started = (method) =>
    Boolean(qrUrlFor(method.prefix) || nameOf(method) || numberOf(method));

/*
| Deliberately NOT "all three fields filled". This has to agree with the server
| or the footer contradicts what the admin will actually get:
|
|   - checkout publishes a method carrying a QR *or* an account number
|     (PaymentMethodService::published()), so a QR-only optional method is
|     complete and publishable — telling the admin to add account details they
|     do not have would be wrong;
|   - the server pairs name and number with required_with, so either both
|     travel or neither does;
|   - the REQUIRED method is different: the server demands its name and number
|     outright, and its QR is nullable. So a required method with a valid
|     number and no QR genuinely is complete — the customer can still send to
|     the account.
*/
function complete(method) {
    const hasName = Boolean(nameOf(method));
    const hasNumber = Boolean(numberOf(method));

    if (method.required) {
        return hasName && hasNumber && numberValid(method);
    }

    // required_with cuts both ways — neither field may be published alone.
    if (hasName !== hasNumber) {
        return false;
    }

    if (hasNumber && !numberValid(method)) {
        return false;
    }

    // Something the customer can act on: a QR to scan or a number to send to.
    return Boolean(qrUrlFor(method.prefix)) || hasNumber;
}

/** Optional methods the admin has begun but not finished — the blockers. */
const halfFinished = computed(() =>
    props.methods.filter((method) => !method.required && started(method) && !complete(method)),
);

const requiredMethods = computed(() => props.methods.filter((method) => method.required));

const requiredIncomplete = computed(() =>
    requiredMethods.value.filter((method) => !complete(method)),
);

/*
| "Fully configured" means a customer can finish paying: every required method
| whole, instructions written, and every optional method either untouched or
| finished. A stray account name with no number behind it would fail the save
| outright, so it counts as not ready.
*/
const previewReady = computed(
    () =>
        requiredIncomplete.value.length === 0 &&
        instructionLines.value.length > 0 &&
        halfFinished.value.length === 0,
);

/** Name the half-finished method rather than sending the admin hunting. */
const previewMessage = computed(() => {
    if (previewReady.value) {
        const live = previewMethods.value.filter((card) => card.published).map((card) => card.label);

        return live.length > 1
            ? `Checkout is fully configured — customers can pay with ${live.slice(0, -1).join(', ')} or ${live.at(-1)}.`
            : 'Checkout is fully configured.';
    }

    if (requiredIncomplete.value.length === 0 && instructionLines.value.length > 0) {
        const names = halfFinished.value.map((method) => method.label).join(' and ');

        return `${names} is half-finished — it needs a QR, or an account name and number together. Clear every ${names} field to leave it switched off.`;
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

                    <!--
                        One QR card + one account card per method, rendered from
                        the catalogue. Spelling out that the optional methods
                        validate as a PAIR matters: an admin who types an account
                        name and nothing else would otherwise be baffled by an
                        error on a field the badge calls optional.
                    -->
                    <template v-for="method in methods" :key="method.prefix">
                        <Card
                            :title="`${method.label} QR code`"
                            subtitle="Scanned by the customer to pay. PNG, JPG or WebP, up to 2MB."
                        >
                            <template #actions>
                                <Badge
                                    :tone="method.required ? 'brand' : 'ink'"
                                    size="xs"
                                    :dot="false"
                                    :label="method.required ? 'Required' : 'Optional'"
                                />
                            </template>

                            <FormFileUpload
                                v-model="form[`${method.prefix}_qr`]"
                                label="QR image"
                                accept="image/png,image/jpeg,image/webp"
                                :max-size="2"
                                :square="true"
                                :disabled="!canUpdate"
                                :existing-url="
                                    form[`remove_${method.prefix}_qr`]
                                        ? null
                                        : settings[`${method.prefix}_qr_url`]
                                "
                                :progress="uploadProgress(form[`${method.prefix}_qr`])"
                                :error="form.errors[`${method.prefix}_qr`]"
                                :hint="method.qr_hint"
                            />

                            <div
                                v-if="
                                    canUpdate &&
                                    (hasStoredQr(method.prefix) || form[`remove_${method.prefix}_qr`])
                                "
                                class="mt-4 flex flex-wrap items-center gap-3"
                            >
                                <Button
                                    v-if="
                                        !form[`remove_${method.prefix}_qr`] &&
                                        !form[`${method.prefix}_qr`]
                                    "
                                    variant="ghost"
                                    size="sm"
                                    @click="removeQr(method.prefix)"
                                >
                                    <template #icon><Trash2 :size="14" /></template>
                                    Remove current QR
                                </Button>

                                <template v-if="form[`remove_${method.prefix}_qr`]">
                                    <span class="text-xs font-medium text-danger-600">
                                        The stored QR will be deleted when you save.
                                    </span>
                                    <Button variant="ghost" size="sm" @click="keepQr(method.prefix)">
                                        <template #icon><RotateCcw :size="14" /></template>
                                        Keep it
                                    </Button>
                                </template>
                            </div>
                        </Card>

                        <Card
                            :title="`${method.label} receiving account`"
                            :subtitle="
                                method.required
                                    ? 'Shown beside the QR for customers who prefer to send manually.'
                                    : `Shown beside the ${method.label} QR. Only needed if you publish ${method.label}.`
                            "
                        >
                            <template #actions>
                                <Badge
                                    :tone="method.required ? 'brand' : 'ink'"
                                    size="xs"
                                    :dot="false"
                                    :label="method.required ? 'Required' : 'Optional'"
                                />
                            </template>

                            <p v-if="!method.required" class="mb-4 text-xs text-ink-500">
                                Leave both fields and the QR above empty and checkout simply never
                                offers {{ method.label }}. Start filling them in and they become a
                                set — the account name and number are required together.
                            </p>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <FormInput
                                    v-model="form[`${method.prefix}_account_name`]"
                                    label="Account name"
                                    :label-hint="method.required ? null : 'Optional'"
                                    placeholder="Juan Dela Cruz"
                                    :required="method.required"
                                    :disabled="!canUpdate"
                                    :icon="method.number_format === 'mobile' ? Wallet : Landmark"
                                    :error="form.errors[`${method.prefix}_account_name`]"
                                    :hint="method.name_hint"
                                />

                                <FormInput
                                    v-model="form[`${method.prefix}_account_number`]"
                                    :label="method.number_label"
                                    :label-hint="method.required ? null : 'Optional'"
                                    :placeholder="method.number_placeholder"
                                    :inputmode="method.number_format === 'mobile' ? 'tel' : 'numeric'"
                                    autocomplete="off"
                                    :required="method.required"
                                    :disabled="!canUpdate"
                                    :error="form.errors[`${method.prefix}_account_number`]"
                                    :hint="method.number_hint"
                                />
                            </div>
                        </Card>
                    </template>

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
                                        class="mt-2.5 flex h-9 w-full items-center justify-center rounded-xl bg-brand-600 text-sm font-medium text-white opacity-70"
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
