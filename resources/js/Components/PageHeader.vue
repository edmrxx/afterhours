<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import Breadcrumbs from './Breadcrumbs.vue';

/*
| PageHeader — the title block that opens every page.
|
|   <PageHeader title="Courts" subtitle="Everything bookable at the club"
|               :breadcrumbs="[{ label: 'Courts' }]">
|       <template #actions>
|           <Button :href="route('admin.courts.create')"><template #icon><Plus :size="16" /></template>New court</Button>
|       </template>
|   </PageHeader>
*/

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    /** [{ label, href? }] — rendered through <Breadcrumbs>. */
    breadcrumbs: { type: Array, default: () => [] },
    /** Href for the home crumb; omit to hide it. */
    homeHref: { type: String, default: null },
    /** Show a back link to this URL beside the title. */
    backHref: { type: String, default: null },
    backLabel: { type: String, default: 'Back' },
    /** Add a hairline under the header. */
    divider: { type: Boolean, default: false },
});
</script>

<template>
    <header :class="['mb-6', divider ? 'border-b border-ink-200 pb-5' : '']">
        <Breadcrumbs
            v-if="breadcrumbs.length || homeHref"
            :items="breadcrumbs"
            :home="Boolean(homeHref)"
            :home-href="homeHref"
            class="mb-2"
        />

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <Link
                    v-if="backHref"
                    :href="backHref"
                    :aria-label="backLabel"
                    class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-500 shadow-card transition-colors hover:bg-ink-50 hover:text-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                >
                    <ArrowLeft :size="16" aria-hidden="true" />
                </Link>

                <div class="min-w-0">
                    <h1 class="truncate text-xl font-semibold tracking-tight text-ink-900 sm:text-2xl">
                        <slot name="title">{{ title }}</slot>
                    </h1>
                    <p
                        v-if="subtitle || $slots.subtitle"
                        class="mt-1 text-sm leading-relaxed text-ink-500"
                    >
                        <slot name="subtitle">{{ subtitle }}</slot>
                    </p>
                </div>
            </div>

            <div
                v-if="$slots.actions"
                class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end"
            >
                <slot name="actions" />
            </div>
        </div>

        <div v-if="$slots.default" class="mt-5">
            <slot />
        </div>
    </header>
</template>
