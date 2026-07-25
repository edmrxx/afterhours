<script setup>
import { computed, ref, watch } from 'vue';

/*
| Avatar — image with a deterministic initials fallback.
|
|   <Avatar :name="user.name" :initials="user.initials" :src="user.avatar_url" />
*/

const props = defineProps({
    name: { type: String, default: '' },
    /** Server-provided monogram; derived from `name` when absent. */
    initials: { type: String, default: null },
    src: { type: String, default: null },
    /** xs | sm | md | lg | xl */
    size: { type: String, default: 'md' },
    /** Force a tone instead of deriving one from the name. */
    tone: { type: String, default: null },
    /** Small presence dot in the corner. */
    online: { type: Boolean, default: null },
});

const SIZES = {
    xs: 'h-6 w-6 text-[10px]',
    sm: 'h-8 w-8 text-xs',
    md: 'h-9 w-9 text-xs',
    lg: 'h-12 w-12 text-sm',
    xl: 'h-16 w-16 text-lg',
};

const TONES = {
    brand: 'bg-brand-100 text-brand-700',
    success: 'bg-success-50 text-success-700',
    warn: 'bg-warn-50 text-warn-700',
    danger: 'bg-danger-50 text-danger-700',
    info: 'bg-info-50 text-info-600',
    ink: 'bg-ink-200 text-ink-700',
};

const TONE_CYCLE = ['brand', 'info', 'success', 'warn', 'ink'];

const failed = ref(false);

watch(
    () => props.src,
    () => {
        failed.value = false;
    },
);

const monogram = computed(() => {
    if (props.initials) {
        return props.initials.slice(0, 2).toUpperCase();
    }

    const parts = String(props.name ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    const first = parts[0].charAt(0);
    const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';

    return (first + last).toUpperCase();
});

/** Stable per-person tone so the same user always looks the same. */
const resolvedTone = computed(() => {
    if (props.tone) {
        return props.tone;
    }

    const seed = String(props.name || monogram.value);
    let hash = 0;

    for (let i = 0; i < seed.length; i += 1) {
        hash = (hash * 31 + seed.charCodeAt(i)) % 9973;
    }

    return TONE_CYCLE[hash % TONE_CYCLE.length];
});

const showImage = computed(() => Boolean(props.src) && !failed.value);
</script>

<template>
    <span class="relative inline-flex shrink-0">
        <img
            v-if="showImage"
            :src="src"
            :alt="name || 'User avatar'"
            :class="['rounded-full object-cover ring-1 ring-ink-200', SIZES[size] ?? SIZES.md]"
            @error="failed = true"
        />

        <span
            v-else
            :class="[
                'inline-flex items-center justify-center rounded-full font-semibold tracking-wide select-none ring-1 ring-black/5',
                SIZES[size] ?? SIZES.md,
                TONES[resolvedTone] ?? TONES.brand,
            ]"
            :title="name || undefined"
            :aria-label="name ? `${name} avatar` : 'Avatar'"
            role="img"
        >
            {{ monogram }}
        </span>

        <span
            v-if="online !== null"
            :class="[
                'absolute right-0 bottom-0 block rounded-full ring-2 ring-white',
                size === 'xs' || size === 'sm' ? 'h-2 w-2' : 'h-2.5 w-2.5',
                online ? 'bg-success-500' : 'bg-ink-300',
            ]"
            :aria-label="online ? 'Active' : 'Inactive'"
        />
    </span>
</template>
