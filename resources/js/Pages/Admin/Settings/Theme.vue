<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    Check,
    Info,
    Monitor,
    Moon,
    PanelLeftClose,
    Save,
    Sun,
    SwatchBook,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormToggle from '@/Components/FormToggle.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SettingsNav from './Partials/SettingsNav.vue';
import { usePermissions } from '@/Composables/usePermissions';

/*
| Appearance settings.
|
| The stored value is always a *design-token name* — `brand`, `info`, … — never
| a hex code. The class maps below are written as complete literal strings so
| Tailwind's scanner can see them; a computed `bg-${token}-600` would be
| stripped from the build.
|
| Only shades that actually exist in `@theme` may be referenced. The status
| ramps stop at 50/500/600(/700), so the previews stay inside 50/500/600, which
| every palette in the catalogue provides.
*/

const props = defineProps({
    settings: { type: Object, required: true },
    /** [{ value, label, description }] — the curated palette from the server. */
    palettes: { type: Array, required: true },
    /** [{ value, label }] */
    colorSchemes: { type: Array, required: true },
});

const { can } = usePermissions();

const canUpdate = computed(() => can('settings.update'));

/* ------------------------------------------------------------------ */
/* Token → utility class maps (literal strings, never interpolated)    */
/* ------------------------------------------------------------------ */

const PALETTE_STYLES = {
    brand: {
        dot: 'bg-brand-600',
        soft: 'bg-brand-50',
        border: 'border-brand-500',
        ring: 'ring-brand-500/30',
        text: 'text-brand-600',
        ramp: ['bg-brand-50', 'bg-brand-500', 'bg-brand-600'],
    },
    info: {
        dot: 'bg-info-600',
        soft: 'bg-info-50',
        border: 'border-info-500',
        ring: 'ring-info-500/30',
        text: 'text-info-600',
        ramp: ['bg-info-50', 'bg-info-500', 'bg-info-600'],
    },
    success: {
        dot: 'bg-success-600',
        soft: 'bg-success-50',
        border: 'border-success-500',
        ring: 'ring-success-500/30',
        text: 'text-success-600',
        ramp: ['bg-success-50', 'bg-success-500', 'bg-success-600'],
    },
    warn: {
        dot: 'bg-warn-600',
        soft: 'bg-warn-50',
        border: 'border-warn-500',
        ring: 'ring-warn-500/30',
        text: 'text-warn-600',
        ramp: ['bg-warn-50', 'bg-warn-500', 'bg-warn-600'],
    },
    danger: {
        dot: 'bg-danger-600',
        soft: 'bg-danger-50',
        border: 'border-danger-500',
        ring: 'ring-danger-500/30',
        text: 'text-danger-600',
        ramp: ['bg-danger-50', 'bg-danger-500', 'bg-danger-600'],
    },
};

const FALLBACK_STYLE = PALETTE_STYLES.brand;

const SCHEME_ICONS = {
    light: Sun,
    dark: Moon,
    system: Monitor,
};

const SCHEME_HINTS = {
    light: 'Always the light interface.',
    dark: 'Always the dark interface.',
    system: 'Follow each visitor’s device setting.',
};

const styleFor = (token) => PALETTE_STYLES[token] ?? FALLBACK_STYLE;

/* ------------------------------------------------------------------ */
/* Form                                                                */
/* ------------------------------------------------------------------ */

const initial = () => ({
    primary_palette: props.settings.primary_palette ?? 'brand',
    color_scheme: props.settings.color_scheme ?? 'light',
    sidebar_collapsed: Boolean(props.settings.sidebar_collapsed),
});

const form = useForm(initial());

const dirty = computed(() => canUpdate.value && form.isDirty);

const active = computed(() => styleFor(form.primary_palette));

const activePalette = computed(
    () => props.palettes.find((palette) => palette.value === form.primary_palette) ?? null,
);

function submit() {
    if (!canUpdate.value || form.processing) {
        return;
    }

    form.put(route('admin.settings.theme.update'), {
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
</script>

<template>
    <AppLayout title="Appearance settings">
        <PageHeader
            title="Appearance settings"
            subtitle="Brand colour and interface defaults, expressed as design tokens rather than raw colours."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Appearance' }]"
            :home-href="route('admin.dashboard')"
        />

        <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-8">
            <SettingsNav :dirty="dirty" />

            <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_23rem] xl:items-start">
                <!-- ------------------------------------------------ -->
                <!-- Form                                              -->
                <!-- ------------------------------------------------ -->
                <form id="theme-settings-form" class="min-w-0 space-y-6" @submit.prevent="submit">
                    <Alert
                        v-if="!canUpdate"
                        variant="info"
                        title="Read only"
                        message="You can review the appearance settings but not change them. Ask an administrator for the settings.update permission."
                    />

                    <Card
                        title="Brand colour"
                        subtitle="Drives primary buttons, active navigation and focus rings."
                    >
                        <fieldset :disabled="!canUpdate">
                            <legend class="sr-only">Brand colour</legend>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <label
                                    v-for="palette in palettes"
                                    :key="palette.value"
                                    :class="[
                                        'group/palette relative flex items-start gap-3 rounded-xl border p-4',
                                        'transition-[border-color,box-shadow,background-color] duration-200 ease-[var(--ease-out-soft)]',
                                        canUpdate ? 'cursor-pointer' : 'cursor-not-allowed opacity-70',
                                        form.primary_palette === palette.value
                                            ? [
                                                  styleFor(palette.value).border,
                                                  styleFor(palette.value).soft,
                                                  'ring-2',
                                                  styleFor(palette.value).ring,
                                              ]
                                            : 'border-ink-200 bg-white hover:border-ink-300 hover:shadow-card',
                                    ]"
                                >
                                    <input
                                        v-model="form.primary_palette"
                                        type="radio"
                                        name="primary_palette"
                                        :value="palette.value"
                                        class="sr-only"
                                    />

                                    <span
                                        :class="[
                                            'mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-white shadow-card',
                                            styleFor(palette.value).dot,
                                        ]"
                                        aria-hidden="true"
                                    >
                                        <Check
                                            v-if="form.primary_palette === palette.value"
                                            :size="16"
                                            :stroke-width="3"
                                        />
                                        <SwatchBook v-else :size="16" />
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-ink-900">
                                            {{ palette.label }}
                                        </span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-ink-500">
                                            {{ palette.description }}
                                        </span>

                                        <span class="mt-2.5 flex gap-1" aria-hidden="true">
                                            <span
                                                v-for="shade in styleFor(palette.value).ramp"
                                                :key="shade"
                                                :class="[
                                                    'h-3 w-6 rounded-full ring-1 ring-ink-900/5',
                                                    shade,
                                                ]"
                                            />
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        <p v-if="form.errors.primary_palette" class="mt-3 text-xs font-medium text-danger-600" role="alert">
                            {{ form.errors.primary_palette }}
                        </p>

                        <p class="mt-4 text-xs leading-relaxed text-ink-500">
                            Colours are defined once as design tokens in the stylesheet. Choosing a
                            palette selects a token name — it never stores a hex value, so every
                            hover, ring and disabled shade stays in step.
                        </p>
                    </Card>

                    <Card
                        title="Light &amp; dark"
                        subtitle="Records the intended default for the interface."
                    >
                        <fieldset :disabled="!canUpdate">
                            <legend class="sr-only">Appearance</legend>

                            <div class="grid gap-3 sm:grid-cols-3">
                                <label
                                    v-for="scheme in colorSchemes"
                                    :key="scheme.value"
                                    :class="[
                                        'flex flex-col items-center gap-2 rounded-xl border px-3 py-4 text-center',
                                        'transition-[border-color,box-shadow,background-color] duration-200 ease-[var(--ease-out-soft)]',
                                        canUpdate ? 'cursor-pointer' : 'cursor-not-allowed opacity-70',
                                        form.color_scheme === scheme.value
                                            ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/30'
                                            : 'border-ink-200 bg-white hover:border-ink-300 hover:shadow-card',
                                    ]"
                                >
                                    <input
                                        v-model="form.color_scheme"
                                        type="radio"
                                        name="color_scheme"
                                        :value="scheme.value"
                                        class="sr-only"
                                    />

                                    <component
                                        :is="SCHEME_ICONS[scheme.value] ?? Monitor"
                                        :size="20"
                                        :class="
                                            form.color_scheme === scheme.value
                                                ? 'text-brand-600'
                                                : 'text-ink-400'
                                        "
                                        aria-hidden="true"
                                    />
                                    <span class="text-sm font-semibold text-ink-900">
                                        {{ scheme.label }}
                                    </span>
                                    <span class="text-[11px] leading-relaxed text-ink-500">
                                        {{ SCHEME_HINTS[scheme.value] }}
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        <p v-if="form.errors.color_scheme" class="mt-3 text-xs font-medium text-danger-600" role="alert">
                            {{ form.errors.color_scheme }}
                        </p>

                        <div class="mt-4">
                            <Alert
                                variant="info"
                                title="Saved, not yet rendered"
                                message="The admin and public interfaces currently render in light mode only. Your choice is stored now so it applies the moment dark mode ships — no reconfiguration needed."
                            />
                        </div>
                    </Card>

                    <Card
                        title="Interface defaults"
                        subtitle="Applied to users who have not set their own preference."
                    >
                        <FormToggle
                            v-model="form.sidebar_collapsed"
                            label="Start with the sidebar collapsed"
                            hint="Gives pages the full width on first load. Anyone can still expand it, and their choice is remembered on that device."
                            label-right
                            :disabled="!canUpdate"
                            :error="form.errors.sidebar_collapsed"
                        />
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
                                    form="theme-settings-form"
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
                    <Card padding="none" title="Interface preview" subtitle="Live">
                        <div class="bg-ink-50 p-4">
                            <div
                                class="overflow-hidden rounded-xl border border-ink-200 bg-white shadow-card"
                            >
                                <div class="flex">
                                    <!-- Mock sidebar -->
                                    <div
                                        :class="[
                                            'shrink-0 space-y-1.5 border-r border-ink-200 p-2 transition-[width] duration-300 ease-[var(--ease-out-soft)]',
                                            form.sidebar_collapsed ? 'w-12' : 'w-28',
                                        ]"
                                    >
                                        <div class="flex items-center gap-2 px-1 pb-2">
                                            <span
                                                :class="[
                                                    'inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-[10px] font-bold text-white',
                                                    active.dot,
                                                ]"
                                                aria-hidden="true"
                                            >
                                                P
                                            </span>
                                            <span
                                                v-if="!form.sidebar_collapsed"
                                                class="h-2 w-12 rounded-full bg-ink-200"
                                                aria-hidden="true"
                                            />
                                        </div>

                                        <div
                                            :class="[
                                                'flex items-center gap-2 rounded-md px-1.5 py-1.5',
                                                active.soft,
                                            ]"
                                            aria-hidden="true"
                                        >
                                            <span :class="['h-2 w-2 rounded-sm', active.dot]" />
                                            <span
                                                v-if="!form.sidebar_collapsed"
                                                :class="[
                                                    'h-1.5 w-11 rounded-full opacity-70',
                                                    active.dot,
                                                ]"
                                            />
                                        </div>

                                        <div
                                            v-for="row in 3"
                                            :key="row"
                                            class="flex items-center gap-2 rounded-md px-1.5 py-1.5"
                                            aria-hidden="true"
                                        >
                                            <span class="h-2 w-2 rounded-sm bg-ink-300" />
                                            <span
                                                v-if="!form.sidebar_collapsed"
                                                class="h-1.5 w-10 rounded-full bg-ink-200"
                                            />
                                        </div>
                                    </div>

                                    <!-- Mock content -->
                                    <div class="min-w-0 flex-1 space-y-3 bg-ink-50/70 p-3">
                                        <div
                                            class="flex items-center justify-between gap-2"
                                            aria-hidden="true"
                                        >
                                            <span class="h-2.5 w-20 rounded-full bg-ink-300" />
                                            <span
                                                :class="[
                                                    'rounded-md px-2.5 py-1 text-[10px] font-medium text-white',
                                                    active.dot,
                                                ]"
                                            >
                                                New booking
                                            </span>
                                        </div>

                                        <div
                                            class="space-y-2 rounded-lg border border-ink-200 bg-white p-3"
                                        >
                                            <span
                                                class="block h-1.5 w-24 rounded-full bg-ink-200"
                                                aria-hidden="true"
                                            />
                                            <span
                                                class="block h-1.5 w-16 rounded-full bg-ink-200"
                                                aria-hidden="true"
                                            />
                                            <div class="flex items-center gap-2 pt-1">
                                                <span
                                                    :class="[
                                                        'rounded-full px-2 py-0.5 text-[10px] font-medium',
                                                        active.soft,
                                                        active.text,
                                                    ]"
                                                >
                                                    Confirmed
                                                </span>
                                                <span
                                                    class="h-1.5 w-10 rounded-full bg-ink-200"
                                                    aria-hidden="true"
                                                />
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <span
                                                :class="[
                                                    'rounded-lg px-2.5 py-1 text-[10px] font-medium text-white',
                                                    active.dot,
                                                ]"
                                            >
                                                Primary
                                            </span>
                                            <span
                                                class="rounded-lg border border-ink-200 bg-white px-2.5 py-1 text-[10px] font-medium text-ink-600"
                                            >
                                                Secondary
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <template #footer>
                            <dl class="space-y-1.5 text-xs">
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-ink-500">Palette token</dt>
                                    <dd :class="['font-semibold', active.text]">
                                        {{ activePalette?.label ?? '—' }}
                                        <span class="font-mono text-[11px] text-ink-400">
                                            ({{ form.primary_palette }})
                                        </span>
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-ink-500">Sidebar default</dt>
                                    <dd class="flex items-center gap-1.5 font-medium text-ink-700">
                                        <PanelLeftClose
                                            :size="13"
                                            class="shrink-0 text-ink-400"
                                            aria-hidden="true"
                                        />
                                        {{ form.sidebar_collapsed ? 'Collapsed' : 'Expanded' }}
                                    </dd>
                                </div>
                            </dl>
                        </template>
                    </Card>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
