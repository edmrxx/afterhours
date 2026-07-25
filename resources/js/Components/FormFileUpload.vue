<script setup>
import { computed, ref, useId, watch } from 'vue';
import { CloudUpload, FileText, X } from '@lucide/vue';
import FormField from './FormField.vue';

/*
| FormFileUpload — drag-and-drop upload with image preview and progress.
|
|   <FormFileUpload v-model="form.photo" label="Court photo"
|                   :error="form.errors.photo" :progress="form.progress?.percentage"
|                   :existing-url="court.photo_url" accept="image/*" :max-size="2" />
|
| The model value is the raw File (or null), ready to hand to an Inertia form
| with forceFormData.
*/

const props = defineProps({
    /** File | null */
    modelValue: { type: [Object, String], default: null },
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** Accept attribute, e.g. "image/png,image/jpeg" or "image/*". */
    accept: { type: String, default: 'image/png,image/jpeg,image/webp' },
    /** Max size in megabytes — enforced client-side as a courtesy only. */
    maxSize: { type: Number, default: 2 },
    /** Already-stored file URL shown until a new one is chosen. */
    existingUrl: { type: String, default: null },
    /** 0–100 while the Inertia form is uploading. */
    progress: { type: Number, default: null },
    /** Show a square image preview instead of a wide one. */
    square: { type: Boolean, default: false },
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'error', 'clear']);

const uid = useId();
const inputId = computed(() => props.id ?? `file-${uid}`);
const input = ref(null);
const dragging = ref(false);
const localError = ref(null);
const previewUrl = ref(null);

const file = computed(() => (props.modelValue instanceof File ? props.modelValue : null));

const isImage = computed(() => {
    if (file.value) {
        return file.value.type.startsWith('image/');
    }

    return Boolean(props.existingUrl);
});

const displayUrl = computed(() => previewUrl.value ?? props.existingUrl);

const shownError = computed(() => props.error ?? localError.value);

const acceptHint = computed(() => {
    const types = props.accept
        .split(',')
        .map((type) => type.trim().replace('image/', '').replace('*', 'any').toUpperCase())
        .filter(Boolean)
        .join(', ');

    return `${types} · up to ${props.maxSize}MB`;
});

function humanSize(bytes) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

function matchesAccept(candidate) {
    const patterns = props.accept
        .split(',')
        .map((type) => type.trim().toLowerCase())
        .filter(Boolean);

    if (patterns.length === 0) {
        return true;
    }

    return patterns.some((pattern) => {
        if (pattern.endsWith('/*')) {
            return candidate.type.toLowerCase().startsWith(pattern.slice(0, -1));
        }

        if (pattern.startsWith('.')) {
            return candidate.name.toLowerCase().endsWith(pattern);
        }

        return candidate.type.toLowerCase() === pattern;
    });
}

function revoke() {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
}

function accept(candidate) {
    localError.value = null;

    if (!candidate) {
        return;
    }

    if (!matchesAccept(candidate)) {
        localError.value = 'That file type is not allowed.';
        emit('error', localError.value);

        return;
    }

    if (candidate.size > props.maxSize * 1024 * 1024) {
        localError.value = `That file is ${humanSize(candidate.size)}. The limit is ${props.maxSize}MB.`;
        emit('error', localError.value);

        return;
    }

    revoke();

    if (candidate.type.startsWith('image/')) {
        previewUrl.value = URL.createObjectURL(candidate);
    }

    emit('update:modelValue', candidate);
}

function onSelect(event) {
    accept(event.target.files?.[0] ?? null);
}

function onDrop(event) {
    dragging.value = false;

    if (props.disabled) {
        return;
    }

    accept(event.dataTransfer?.files?.[0] ?? null);
}

function clear() {
    revoke();
    localError.value = null;

    if (input.value) {
        input.value.value = '';
    }

    emit('update:modelValue', null);
    emit('clear');
}

// The parent resetting the form must also clear our preview.
watch(
    () => props.modelValue,
    (value) => {
        if (!value) {
            revoke();
        }
    },
);

const uploading = computed(
    () => props.progress !== null && props.progress >= 0 && props.progress < 100,
);
</script>

<template>
    <FormField
        :label="label"
        :input-id="inputId"
        :hint="hint"
        :error="shownError"
        :required="required"
    >
        <div
            :class="[
                'relative rounded-xl border-2 border-dashed transition-colors duration-150 ease-[var(--ease-out-soft)]',
                dragging
                    ? 'border-brand-500 bg-brand-50'
                    : shownError
                      ? 'border-danger-500 bg-danger-50/40'
                      : 'border-ink-200 bg-ink-50/60 hover:border-ink-300',
                disabled ? 'pointer-events-none opacity-60' : '',
            ]"
            @dragover.prevent="dragging = !disabled"
            @dragenter.prevent="dragging = !disabled"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <input
                :id="inputId"
                ref="input"
                type="file"
                class="sr-only"
                :accept="accept"
                :required="required && !file && !existingUrl"
                :disabled="disabled"
                :aria-invalid="shownError ? 'true' : undefined"
                @change="onSelect"
            />

            <!-- Chosen / existing file -->
            <div v-if="file || existingUrl" class="flex items-center gap-4 p-4">
                <img
                    v-if="isImage && displayUrl"
                    :src="displayUrl"
                    alt="Selected file preview"
                    :class="[
                        'shrink-0 rounded-lg border border-ink-200 bg-white object-cover',
                        square ? 'h-20 w-20' : 'h-16 w-24',
                    ]"
                />
                <span
                    v-else
                    class="inline-flex h-16 w-16 shrink-0 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-400"
                    aria-hidden="true"
                >
                    <FileText :size="22" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-ink-800">
                        {{ file ? file.name : 'Current file' }}
                    </p>
                    <p class="mt-0.5 text-xs text-ink-500">
                        {{ file ? humanSize(file.size) : 'Choose a new file to replace it' }}
                    </p>

                    <div v-if="uploading" class="mt-2">
                        <div
                            class="h-1.5 w-full overflow-hidden rounded-full bg-ink-200"
                            role="progressbar"
                            :aria-valuenow="Math.round(progress)"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="Upload progress"
                        >
                            <div
                                class="h-full rounded-full bg-brand-600 transition-[width] duration-200 ease-[var(--ease-out-soft)]"
                                :style="{ width: `${progress}%` }"
                            />
                        </div>
                        <p class="mt-1 text-xs text-ink-500">Uploading… {{ Math.round(progress) }}%</p>
                    </div>

                    <div v-else class="mt-2 flex flex-wrap gap-2">
                        <label
                            :for="inputId"
                            class="cursor-pointer rounded-md px-2 py-1 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-50"
                        >
                            Replace
                        </label>
                    </div>
                </div>

                <button
                    v-if="file"
                    type="button"
                    class="shrink-0 cursor-pointer rounded-lg p-1.5 text-ink-400 transition-colors hover:bg-ink-100 hover:text-danger-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                    aria-label="Remove selected file"
                    @click="clear"
                >
                    <X :size="16" aria-hidden="true" />
                </button>
            </div>

            <!-- Empty dropzone -->
            <label
                v-else
                :for="inputId"
                class="flex cursor-pointer flex-col items-center px-6 py-8 text-center"
            >
                <span
                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-white text-brand-600 shadow-card"
                    aria-hidden="true"
                >
                    <CloudUpload :size="20" />
                </span>
                <span class="mt-3 text-sm font-medium text-ink-800">
                    <span class="text-brand-700">Click to upload</span>
                    <span class="hidden sm:inline"> or drag and drop</span>
                </span>
                <span class="mt-1 text-xs text-ink-500">{{ acceptHint }}</span>
            </label>
        </div>
    </FormField>
</template>
