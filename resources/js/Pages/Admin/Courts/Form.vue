<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { Hash, ImageOff, RotateCcw, Save, Trash2 } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormFileUpload from '@/Components/FormFileUpload.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import FormToggle from '@/Components/FormToggle.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useSwal } from '@/Composables/useSwal';

/*
| One component for both create and edit. `court` being null is the only
| difference the template cares about, so the two screens can never drift.
*/

const props = defineProps({
    /** Null when creating. */
    court: { type: Object, default: null },
    /** Suggested display order for a new court. */
    nextSortOrder: { type: Number, default: 0 },
    /**
     * The court types, each with the two rates it currently charges:
     * `{ value, label, non_peak, peak }`. Comes from the server so this screen
     * never hard-codes the list, and the money shown is always live.
     */
    categories: { type: Array, default: () => [] },
});

const { confirmAction } = useSwal();

const isEdit = computed(() => props.court !== null);

const form = useForm({
    name: props.court?.name ?? '',
    code: props.court?.code ?? '',
    // A new court defaults to the first type on the list — the ordinary kind,
    // and the same default the column itself carries.
    category: props.court?.category ?? props.categories[0]?.value ?? 'normal',
    description: props.court?.description ?? '',
    is_active: props.court?.is_active ?? true,
    sort_order: props.court?.sort_order ?? props.nextSortOrder ?? 0,
    photo: null,
    remove_photo: false,
});

/* ------------------------------------------------------------------ */
/* Court type                                                          */
/* ------------------------------------------------------------------ */

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

/** The rates the currently selected type charges, for the live hint below. */
const selectedCategory = computed(
    () => props.categories.find((category) => category.value === form.category) ?? null,
);

/** True once an existing court is being moved to a different type. */
const categoryChanged = computed(
    () => isEdit.value && props.court?.category != null && form.category !== props.court.category,
);

/* ------------------------------------------------------------------ */
/* Code suggestion                                                     */
/* ------------------------------------------------------------------ */

/** Codes are printed on signage: uppercase, hyphenated, no punctuation. */
function deriveCode(name) {
    return String(name ?? '')
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 30);
}

// Editing never rewrites an established code, and typing in the field opts out.
const codeTouched = ref(isEdit.value);

watch(
    () => form.name,
    (name) => {
        if (!codeTouched.value) {
            form.code = deriveCode(name);
        }
    },
);

/* ------------------------------------------------------------------ */
/* Photo                                                               */
/* ------------------------------------------------------------------ */

const existingPhotoUrl = computed(() =>
    form.remove_photo ? null : (props.court?.photo_url ?? null),
);

const canRemovePhoto = computed(
    () => isEdit.value && Boolean(props.court?.has_photo) && !form.remove_photo && !form.photo,
);

// Choosing a replacement supersedes a pending removal.
watch(
    () => form.photo,
    (file) => {
        if (file) {
            form.remove_photo = false;
        }
    },
);

function markPhotoForRemoval() {
    form.photo = null;
    form.remove_photo = true;
}

function undoPhotoRemoval() {
    form.remove_photo = false;
}

/* ------------------------------------------------------------------ */
/* Submit                                                              */
/* ------------------------------------------------------------------ */

const submitting = ref(false);

function submit() {
    if (submitting.value) {
        return;
    }

    submitting.value = true;

    const options = {
        // The photo is optional, so without this an image-less save would go up
        // as JSON and the `_method` spoof below would never be honoured.
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            submitting.value = false;
        },
    };

    if (isEdit.value) {
        // Multipart cannot be sent as PUT, so spoof the verb.
        form
            .transform((data) => ({ ...data, _method: 'put' }))
            .post(route('admin.courts.update', props.court.slug), options);

        return;
    }

    form.transform((data) => data).post(route('admin.courts.store'), options);
}

/* ------------------------------------------------------------------ */
/* Unsaved-changes protection                                          */
/* ------------------------------------------------------------------ */

/*
| Inertia's `before` hook is synchronous and SweetAlert is not, so the visit is
| cancelled first and replayed from the dialog's resolution if the user really
| wants to leave. `bypass` stops the replayed visit tripping the guard again.
*/
let bypass = false;
let stopGuard = null;

function guard(event) {
    if (bypass || submitting.value || !form.isDirty) {
        return true;
    }

    const visit = event.detail.visit;

    confirmAction({
        title: 'Leave without saving?',
        text: 'This court has unsaved changes. They will be lost.',
        confirmText: 'Discard changes',
        cancelText: 'Keep editing',
        variant: 'danger',
    }).then((confirmed) => {
        if (!confirmed) {
            return;
        }

        bypass = true;

        router.visit(visit.url, {
            method: visit.method,
            data: visit.data,
            replace: visit.replace,
            preserveScroll: visit.preserveScroll,
            preserveState: visit.preserveState,
        });
    });

    return false;
}

function onBeforeUnload(event) {
    if (submitting.value || !form.isDirty) {
        return;
    }

    event.preventDefault();
    // Legacy browsers require a truthy returnValue to show their own prompt.
    event.returnValue = '';
}

onMounted(() => {
    stopGuard = router.on('before', guard);
    window.addEventListener('beforeunload', onBeforeUnload);
});

onBeforeUnmount(() => {
    stopGuard?.();
    window.removeEventListener('beforeunload', onBeforeUnload);
});

/* ------------------------------------------------------------------ */
/* Chrome                                                              */
/* ------------------------------------------------------------------ */

const heading = computed(() => (isEdit.value ? `Edit ${props.court.name}` : 'New court'));

const breadcrumbs = computed(() =>
    isEdit.value
        ? [
              { label: 'Courts', href: route('admin.courts.index') },
              { label: props.court.name, href: route('admin.courts.show', props.court.slug) },
              { label: 'Edit' },
          ]
        : [{ label: 'Courts', href: route('admin.courts.index') }, { label: 'New' }],
);

const backHref = computed(() =>
    isEdit.value
        ? route('admin.courts.show', props.court.slug)
        : route('admin.courts.index'),
);
</script>

<template>
    <AppLayout :title="heading">
        <PageHeader
            :title="heading"
            :subtitle="
                isEdit
                    ? 'Changes take effect on the public booking site immediately.'
                    : 'Courts are what customers pick before choosing a time slot.'
            "
            :home-href="route('admin.dashboard')"
            :breadcrumbs="breadcrumbs"
            :back-href="backHref"
            back-label="Back to court"
        />

        <form class="grid grid-cols-1 gap-6 lg:grid-cols-3" novalidate @submit.prevent="submit">
            <!-- Main details -->
            <div class="space-y-6 lg:col-span-2">
                <Card title="Court details" subtitle="Shown to customers on the booking site.">
                    <div class="space-y-5">
                        <FormInput
                            v-model="form.name"
                            label="Court name"
                            placeholder="e.g. Center Court"
                            required
                            maxlength="150"
                            autocomplete="off"
                            :error="form.errors.name"
                            hint="Customers see this name when choosing where to play."
                        />

                        <FormInput
                            v-model="form.code"
                            label="Court code"
                            placeholder="e.g. CRT-01"
                            required
                            maxlength="30"
                            autocomplete="off"
                            :icon="Hash"
                            :error="form.errors.code"
                            hint="A short unique reference used on schedules and reports."
                            @input="codeTouched = true"
                        />

                        <FormTextarea
                            v-model="form.description"
                            label="Description"
                            placeholder="Surface type, lighting, covered or open air, anything worth knowing before booking."
                            :rows="5"
                            :maxlength="2000"
                            show-count
                            :error="form.errors.description"
                        />
                    </div>
                </Card>

                <Card
                    title="Pricing"
                    subtitle="The court type decides what this court charges per hour."
                >
                    <div class="space-y-4">
                        <FormSelect
                            v-model="form.category"
                            label="Court type"
                            :options="categories"
                            required
                            :disabled="!categories.length"
                            :error="form.errors.category"
                            hint="Courts of the same type always charge the same rates."
                        />

                        <!-- The money the choice commits to, read live from the
                             rate table so it can never show a stale figure. -->
                        <div
                            v-if="selectedCategory"
                            class="rounded-xl border border-ink-200 bg-ink-50/50 p-4"
                        >
                            <p class="mb-3 text-sm font-semibold text-ink-800">
                                {{ selectedCategory.label }} charges
                            </p>
                            <dl class="space-y-2">
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-sm text-ink-600">Non-peak</dt>
                                    <dd class="text-sm font-semibold text-ink-900">
                                        {{ peso.format(selectedCategory.non_peak) }} / hr
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-sm text-ink-600">Peak</dt>
                                    <dd class="text-sm font-semibold text-ink-900">
                                        {{ peso.format(selectedCategory.peak) }} / hr
                                    </dd>
                                </div>
                            </dl>
                            <p class="mt-3 text-xs text-ink-500">
                                Change the amounts under Settings › Booking › Court pricing. They apply
                                to every court of this type.
                            </p>
                        </div>

                        <Alert
                            v-if="categoryChanged"
                            variant="warning"
                            title="Slots already generated keep their old price"
                            message="Rates are forward-only: changing the type here prices NEW slots at the new rates, but the schedule already on the books is untouched. Use Reprice on the Slots screen to move existing available slots across."
                        />
                    </div>
                </Card>

                <Card title="Ordering">
                    <FormInput
                        v-model="form.sort_order"
                        label="Display order"
                        type="number"
                        min="0"
                        step="1"
                        required
                        :error="form.errors.sort_order"
                        hint="Lower numbers appear first. Ties fall back to the name."
                    />
                </Card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <Card title="Availability">
                    <FormToggle
                        v-model="form.is_active"
                        label-right
                        label="Accept bookings"
                        :hint="
                            form.is_active
                                ? 'This court is listed on the public booking site.'
                                : 'Hidden from customers. Existing bookings are unaffected.'
                        "
                        :error="form.errors.is_active"
                    />
                </Card>

                <Card title="Photo" subtitle="JPG, PNG or WEBP · up to 2MB.">
                    <FormFileUpload
                        v-model="form.photo"
                        :existing-url="existingPhotoUrl"
                        :error="form.errors.photo"
                        :progress="form.progress?.percentage ?? null"
                        accept="image/jpeg,image/png,image/webp"
                        :max-size="2"
                        hint="A wide shot of the court works best — it is the first thing customers see."
                    />

                    <div v-if="canRemovePhoto" class="mt-3">
                        <button
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium text-danger-600 transition-colors hover:bg-danger-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                            @click="markPhotoForRemoval"
                        >
                            <Trash2 :size="14" aria-hidden="true" />
                            Remove current photo
                        </button>
                    </div>

                    <Alert
                        v-if="form.remove_photo"
                        variant="warning"
                        class="mt-3"
                        title="Photo will be removed"
                        message="The existing photo is deleted when you save."
                    >
                        <template #actions>
                            <button
                                type="button"
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-white/70 px-2 py-1 text-xs font-semibold text-warn-700 transition-colors hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                                @click="undoPhotoRemoval"
                            >
                                <RotateCcw :size="14" aria-hidden="true" />
                                Undo
                            </button>
                        </template>
                    </Alert>

                    <p
                        v-if="isEdit && !court.has_photo && !form.photo"
                        class="mt-3 inline-flex items-center gap-1.5 text-xs text-ink-500"
                    >
                        <ImageOff :size="14" aria-hidden="true" />
                        No photo yet — the public site shows a placeholder.
                    </p>
                </Card>

                <!-- Actions -->
                <div class="flex flex-col gap-2 sm:flex-row-reverse lg:flex-col-reverse">
                    <Button
                        type="submit"
                        block
                        :loading="form.processing"
                        :disabled="form.processing"
                    >
                        <template #icon><Save :size="16" aria-hidden="true" /></template>
                        {{ isEdit ? 'Save changes' : 'Create court' }}
                    </Button>

                    <Button variant="secondary" block :href="backHref" :disabled="form.processing">
                        Cancel
                    </Button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
