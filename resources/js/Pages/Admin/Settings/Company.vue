<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    Building2,
    Clock,
    Info,
    Mail,
    MapPin,
    Phone,
    RotateCcw,
    Save,
    Store,
    Trash2,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormFileUpload from '@/Components/FormFileUpload.vue';
import FormInput from '@/Components/FormInput.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SettingsNav from './Partials/SettingsNav.vue';
import { usePermissions } from '@/Composables/usePermissions';

/*
| Company settings — the identity rendered in the public site header and in
| the footer of every outbound email. The preview column shows both surfaces,
| because a logo that looks fine on a white card can still be unreadable in an
| email footer.
*/

const props = defineProps({
    settings: { type: Object, required: true },
});

const { can } = usePermissions();

const canUpdate = computed(() => can('settings.update'));

/* ------------------------------------------------------------------ */
/* Form                                                                */
/* ------------------------------------------------------------------ */

const initial = () => ({
    _method: 'put',
    company_name: props.settings.company_name ?? '',
    company_legal_name: props.settings.company_legal_name ?? '',
    company_email: props.settings.company_email ?? '',
    company_phone: props.settings.company_phone ?? '',
    company_address: props.settings.company_address ?? '',
    operating_opening_time: props.settings.operating_opening_time ?? '07:00',
    operating_closing_time: props.settings.operating_closing_time ?? '02:00',
    company_logo: null,
    remove_company_logo: false,
});

const form = useForm(initial());

const dirty = computed(() => canUpdate.value && form.isDirty);

/* ------------------------------------------------------------------ */
/* Operating hours — structured window, human label derived live       */
/* ------------------------------------------------------------------ */

/** "16:00" → "4:00 PM". Mirrors CompanySettingsController::to12h so the live
 *  preview here matches the label the server stores on save. */
function to12h(hhmm) {
    const [h, m] = String(hhmm ?? '').split(':').map((n) => Number(n));

    if (!Number.isFinite(h) || !Number.isFinite(m)) {
        return String(hhmm ?? '');
    }

    const suffix = h < 12 ? 'AM' : 'PM';
    const hour = h % 12 === 0 ? 12 : h % 12;

    return `${hour}:${String(m).padStart(2, '0')} ${suffix}`;
}

const operatingHoursLabel = computed(() => {
    const open = form.operating_opening_time;
    const close = form.operating_closing_time;

    return open && close ? `${to12h(open)} – ${to12h(close)} daily` : '';
});

/** A closing time at or before the opening time reads as "closes after midnight". */
const operatingCrossesMidnight = computed(
    () => String(form.operating_opening_time ?? '') >= String(form.operating_closing_time ?? ''),
);

function submit() {
    if (!canUpdate.value || form.processing) {
        return;
    }

    form
        .transform((data) => ({
            ...data,
            remove_company_logo: data.remove_company_logo ? 1 : 0,
        }))
        .post(route('admin.settings.company.update'), {
            forceFormData: true,
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
/* Logo preview                                                        */
/* ------------------------------------------------------------------ */

const objectUrl = ref(null);

function revoke() {
    if (objectUrl.value) {
        URL.revokeObjectURL(objectUrl.value);
        objectUrl.value = null;
    }
}

watch(
    () => form.company_logo,
    (file) => {
        revoke();

        if (file instanceof File && file.type.startsWith('image/')) {
            objectUrl.value = URL.createObjectURL(file);
            form.remove_company_logo = false;
        }
    },
);

onBeforeUnmount(revoke);

const previewLogoUrl = computed(() => {
    if (objectUrl.value) {
        return objectUrl.value;
    }

    return form.remove_company_logo ? null : (props.settings.company_logo_url ?? null);
});

const hasStoredLogo = computed(() => Boolean(props.settings.company_logo_url));

function removeLogo() {
    form.company_logo = null;
    form.remove_company_logo = true;
}

function keepLogo() {
    form.remove_company_logo = false;
}

/* ------------------------------------------------------------------ */
/* Presentation helpers                                                */
/* ------------------------------------------------------------------ */

const displayName = computed(() => form.company_name?.trim() || 'Your company');

/** Two letters for the fallback mark when no logo is uploaded. */
const monogram = computed(() =>
    displayName.value
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase(),
);

const addressLines = computed(() =>
    String(form.company_address ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean),
);
</script>

<template>
    <AppLayout title="Company settings">
        <PageHeader
            title="Company settings"
            subtitle="Your public identity — shown in the site header and at the foot of every email."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Company' }]"
            :home-href="route('admin.dashboard')"
        />

        <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-8">
            <SettingsNav :dirty="dirty" />

            <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_23rem] xl:items-start">
                <!-- ------------------------------------------------ -->
                <!-- Form                                              -->
                <!-- ------------------------------------------------ -->
                <form
                    id="company-settings-form"
                    class="min-w-0 space-y-6"
                    novalidate
                    @submit.prevent="submit"
                >
                    <Alert
                        v-if="!canUpdate"
                        variant="info"
                        title="Read only"
                        message="You can review the company profile but not change it. Ask an administrator for the settings.update permission."
                    />

                    <Card
                        title="Identity"
                        subtitle="How the club is named across the public site and receipts."
                    >
                        <div class="space-y-5">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <FormInput
                                    v-model="form.company_name"
                                    label="Display name"
                                    placeholder="The Paddle Room"
                                    required
                                    :disabled="!canUpdate"
                                    :icon="Store"
                                    :error="form.errors.company_name"
                                    hint="The short, friendly name customers see."
                                />

                                <FormInput
                                    v-model="form.company_legal_name"
                                    label="Registered legal name"
                                    placeholder="The Paddle Room Sports Ventures Inc."
                                    :disabled="!canUpdate"
                                    :icon="Building2"
                                    :error="form.errors.company_legal_name"
                                    hint="Optional. Used where a formal entity name is required."
                                />
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <FormInput
                                    v-model="form.operating_opening_time"
                                    type="time"
                                    label="Opens"
                                    :disabled="!canUpdate"
                                    :icon="Clock"
                                    :error="form.errors.operating_opening_time"
                                />
                                <FormInput
                                    v-model="form.operating_closing_time"
                                    type="time"
                                    label="Closes"
                                    :disabled="!canUpdate"
                                    :icon="Clock"
                                    :error="form.errors.operating_closing_time"
                                />
                            </div>

                            <p class="mt-2 text-xs leading-relaxed text-ink-500">
                                Shown as
                                <span class="font-semibold text-ink-700">“{{ operatingHoursLabel }}”</span>.
                                These hours are also the default opening and closing time when you generate
                                court slots under Slots.
                                <span v-if="operatingCrossesMidnight" class="text-ink-600">
                                    This window runs past midnight into the next day — that's allowed.
                                </span>
                            </p>
                        </div>
                    </Card>

                    <Card
                        title="Logo"
                        subtitle="PNG, JPG or WebP up to 2MB. A transparent PNG sits best on light headers."
                    >
                        <FormFileUpload
                            v-model="form.company_logo"
                            label="Logo image"
                            accept="image/png,image/jpeg,image/webp"
                            :max-size="2"
                            :disabled="!canUpdate"
                            :existing-url="form.remove_company_logo ? null : settings.company_logo_url"
                            :progress="form.progress?.percentage ?? null"
                            :error="form.errors.company_logo"
                            hint="Minimum 64 x 64 pixels. Wide, horizontal marks work best in the site header."
                        />

                        <div
                            v-if="canUpdate && (hasStoredLogo || form.remove_company_logo)"
                            class="mt-4 flex flex-wrap items-center gap-3"
                        >
                            <Button
                                v-if="!form.remove_company_logo && !form.company_logo"
                                variant="ghost"
                                size="sm"
                                @click="removeLogo"
                            >
                                <template #icon><Trash2 :size="14" /></template>
                                Remove current logo
                            </Button>

                            <template v-if="form.remove_company_logo">
                                <span class="text-xs font-medium text-danger-600">
                                    The stored logo will be deleted when you save.
                                </span>
                                <Button variant="ghost" size="sm" @click="keepLogo">
                                    <template #icon><RotateCcw :size="14" /></template>
                                    Keep it
                                </Button>
                            </template>
                        </div>
                    </Card>

                    <Card
                        title="Contact details"
                        subtitle="Where customers reach you when a booking needs a human."
                    >
                        <div class="space-y-5">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <FormInput
                                    v-model="form.company_email"
                                    label="Contact email"
                                    type="email"
                                    placeholder="hello@phea.ph"
                                    autocomplete="off"
                                    required
                                    :disabled="!canUpdate"
                                    :icon="Mail"
                                    :error="form.errors.company_email"
                                    hint="Printed in every email footer as the reply-to address."
                                />

                                <FormInput
                                    v-model="form.company_phone"
                                    label="Contact phone"
                                    inputmode="tel"
                                    placeholder="(02) 8123 4567"
                                    autocomplete="off"
                                    :disabled="!canUpdate"
                                    :icon="Phone"
                                    :error="form.errors.company_phone"
                                    hint="Mobile or landline."
                                />
                            </div>

                            <FormTextarea
                                v-model="form.company_address"
                                label="Address"
                                :rows="3"
                                :maxlength="400"
                                show-count
                                :disabled="!canUpdate"
                                :error="form.errors.company_address"
                                placeholder="123 Court Street, Barangay Example&#10;Quezon City, Metro Manila"
                                hint="One line per row — the preview and email footer keep your line breaks."
                            />
                        </div>
                    </Card>

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
                                    form="company-settings-form"
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
                <aside class="min-w-0 space-y-6 xl:sticky xl:top-24">
                    <Card padding="none" title="Public header" subtitle="Live preview">
                        <div class="bg-ink-50 p-4">
                            <div
                                class="flex items-center gap-3 rounded-xl border border-ink-200 bg-white px-4 py-3 shadow-card"
                            >
                                <img
                                    v-if="previewLogoUrl"
                                    :src="previewLogoUrl"
                                    alt="Company logo preview"
                                    class="h-9 w-9 shrink-0 rounded-lg border border-ink-200 bg-white object-contain"
                                />
                                <span
                                    v-else
                                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-xs font-bold text-white"
                                    aria-hidden="true"
                                >
                                    {{ monogram || 'P' }}
                                </span>

                                <span class="min-w-0 flex-1 leading-tight">
                                    <span class="block truncate text-sm font-semibold text-ink-900">
                                        {{ displayName }}
                                    </span>
                                    <span
                                        v-if="operatingHoursLabel"
                                        class="block truncate text-[11px] text-ink-500"
                                    >
                                        {{ operatingHoursLabel }}
                                    </span>
                                </span>

                                <span
                                    class="hidden shrink-0 rounded-lg bg-brand-600 px-3 py-1.5 text-[11px] font-medium text-white sm:inline"
                                    aria-hidden="true"
                                >
                                    Book a court
                                </span>
                            </div>
                        </div>
                    </Card>

                    <Card padding="none" title="Email footer" subtitle="Live preview">
                        <div class="bg-ink-50 p-4">
                            <div
                                class="rounded-xl border border-ink-200 bg-white px-4 py-5 text-center shadow-card"
                            >
                                <p class="text-sm font-semibold text-ink-900">
                                    {{ form.company_legal_name?.trim() || displayName }}
                                </p>

                                <div class="mt-2 space-y-0.5 text-xs leading-relaxed text-ink-500">
                                    <p v-for="(line, index) in addressLines" :key="index">
                                        {{ line }}
                                    </p>

                                    <p v-if="form.company_phone || form.company_email">
                                        <span v-if="form.company_phone">{{ form.company_phone }}</span>
                                        <span
                                            v-if="form.company_phone && form.company_email"
                                            aria-hidden="true"
                                        >
                                            ·
                                        </span>
                                        <span v-if="form.company_email" class="text-brand-700">
                                            {{ form.company_email }}
                                        </span>
                                    </p>
                                </div>

                                <p class="mt-3 border-t border-ink-200 pt-3 text-[11px] text-ink-400">
                                    © {{ new Date().getFullYear() }} {{ displayName }}. All rights
                                    reserved.
                                </p>
                            </div>
                        </div>

                        <template #footer>
                            <p class="flex items-start gap-2 text-xs text-ink-500">
                                <MapPin :size="14" class="mt-0.5 shrink-0" aria-hidden="true" />
                                These values are also read by the booking confirmation, rejection and
                                verification emails.
                            </p>
                        </template>
                    </Card>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
