<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { Building2, CreditCard, Palette, Timer } from '@lucide/vue';
import { useSwal } from '@/Composables/useSwal';

/*
| SettingsNav — the shared left rail every settings screen renders.
|
|   <SettingsNav :dirty="form.isDirty" />
|
| Two jobs, both of which belong to navigation and therefore live here rather
| than being copy-pasted into each page:
|
|   1. List the settings sections and highlight the current one. Adding a tab
|      later means one entry in SECTIONS plus a controller and a page — the
|      pages themselves need no change.
|   2. Guard against losing unsaved edits. Every Inertia GET visit — sidebar
|      link, browser back, or one of the links below — is intercepted while
|      `dirty` is true, and a hard browser unload triggers the native prompt.
*/

const props = defineProps({
    /** True while the page's form has unsaved changes. */
    dirty: { type: Boolean, default: false },
    /**
     * Override the catalogue if a deployment ever needs a subset.
     * [{ key, label, description, icon, routeName, pattern }]
     */
    sections: { type: Array, default: null },
});

const page = usePage();
const { confirmAction } = useSwal();

/* ------------------------------------------------------------------ */
/* Catalogue                                                           */
/* ------------------------------------------------------------------ */

const SECTIONS = [
    {
        key: 'payment',
        label: 'Payment',
        description: 'Payment QRs, accounts and checkout copy',
        icon: CreditCard,
        routeName: 'admin.settings.payment',
        pattern: 'admin.settings.payment*',
    },
    {
        key: 'company',
        label: 'Company',
        description: 'Name, logo and contact details',
        icon: Building2,
        routeName: 'admin.settings.company',
        pattern: 'admin.settings.company*',
    },
    {
        key: 'theme',
        label: 'Appearance',
        description: 'Brand colour and interface defaults',
        icon: Palette,
        routeName: 'admin.settings.theme',
        pattern: 'admin.settings.theme*',
    },
    {
        key: 'system',
        label: 'Booking',
        description: 'Hold windows, booking codes and court pricing',
        icon: Timer,
        routeName: 'admin.settings.system',
        pattern: 'admin.settings.system*',
    },
];

/**
 * A section whose route is missing from Ziggy (a module not deployed yet) is
 * skipped rather than throwing on `route()`.
 */
const items = computed(() =>
    (props.sections ?? SECTIONS).flatMap((section) => {
        try {
            return [{ ...section, href: route(section.routeName) }];
        } catch {
            return [];
        }
    }),
);

/** `page.url` is read so the highlight recomputes after every visit. */
const activeKey = computed(() => {
    const url = page.url;

    return items.value.find((item) => {
        try {
            return route().current(item.pattern) && url !== undefined;
        } catch {
            return false;
        }
    })?.key;
});

/* ------------------------------------------------------------------ */
/* Unsaved-changes protection                                          */
/* ------------------------------------------------------------------ */

/**
 * Set while we re-dispatch a visit the user has explicitly approved, so the
 * `before` hook lets that one through instead of asking a second time.
 */
const bypass = ref(false);

let stopBefore = null;

function onBeforeUnload(event) {
    if (!props.dirty) {
        return;
    }

    // Browsers ignore custom copy here; both lines are required for the
    // native prompt to appear across engines.
    event.preventDefault();
    event.returnValue = '';
}

async function confirmThenLeave(url) {
    const leave = await confirmAction({
        title: 'Leave without saving?',
        text: 'Your changes to this page have not been saved yet.',
        confirmText: 'Discard changes',
        cancelText: 'Stay on this page',
        variant: 'danger',
        icon: 'warning',
    });

    if (!leave) {
        return;
    }

    bypass.value = true;

    router.visit(url, {
        onFinish: () => {
            bypass.value = false;
        },
    });
}

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);

    stopBefore = router.on('before', (event) => {
        const visit = event.detail?.visit;

        if (!visit || bypass.value || !props.dirty) {
            return;
        }

        // Only reads are intercepted. A POST/PUT is the user deliberately
        // acting — most often the save that clears the dirty flag.
        if (String(visit.method ?? 'get').toLowerCase() !== 'get') {
            return;
        }

        const url = visit.url instanceof URL ? visit.url.href : String(visit.url ?? '');

        // The Swal cannot be awaited synchronously, so cancel now and
        // re-dispatch once the user has answered.
        confirmThenLeave(url);

        return false;
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    stopBefore?.();
});
</script>

<template>
    <nav aria-label="Settings sections" class="lg:sticky lg:top-24">
        <!-- Mobile / tablet: a horizontal, scrollable pill rail. -->
        <div class="-mx-4 overflow-x-auto px-4 pb-1 lg:hidden">
            <ul class="flex w-max gap-2">
                <li v-for="item in items" :key="item.key">
                    <Link
                        :href="item.href"
                        :aria-current="activeKey === item.key ? 'page' : undefined"
                        :class="[
                            'inline-flex items-center gap-2 rounded-full border px-3.5 py-2 text-sm font-medium',
                            'transition-colors duration-150 ease-[var(--ease-out-soft)]',
                            activeKey === item.key
                                ? 'border-brand-200 bg-brand-50 text-brand-700'
                                : 'border-ink-200 bg-white text-ink-600 hover:bg-ink-50 hover:text-ink-900',
                        ]"
                    >
                        <component :is="item.icon" :size="16" aria-hidden="true" />
                        {{ item.label }}
                    </Link>
                </li>
            </ul>
        </div>

        <!-- Desktop: a vertical rail with descriptions. -->
        <ul class="hidden lg:block lg:space-y-1">
            <li v-for="item in items" :key="item.key">
                <Link
                    :href="item.href"
                    :aria-current="activeKey === item.key ? 'page' : undefined"
                    :class="[
                        'group/section flex items-start gap-3 rounded-xl border px-3 py-3',
                        'transition-[background-color,border-color,box-shadow] duration-200 ease-[var(--ease-out-soft)]',
                        activeKey === item.key
                            ? 'border-brand-200 bg-brand-50 shadow-card'
                            : 'border-transparent hover:border-ink-200 hover:bg-white hover:shadow-card',
                    ]"
                >
                    <span
                        :class="[
                            'mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors duration-200',
                            activeKey === item.key
                                ? 'bg-brand-600 text-white'
                                : 'bg-ink-100 text-ink-500 group-hover/section:bg-ink-200 group-hover/section:text-ink-700',
                        ]"
                        aria-hidden="true"
                    >
                        <component :is="item.icon" :size="16" />
                    </span>

                    <span class="min-w-0">
                        <span
                            :class="[
                                'block text-sm font-semibold',
                                activeKey === item.key ? 'text-brand-700' : 'text-ink-800',
                            ]"
                        >
                            {{ item.label }}
                        </span>
                        <span class="mt-0.5 block text-xs leading-relaxed text-ink-500">
                            {{ item.description }}
                        </span>
                    </span>
                </Link>
            </li>
        </ul>
    </nav>
</template>
