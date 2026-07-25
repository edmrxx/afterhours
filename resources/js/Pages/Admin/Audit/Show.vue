<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    ArrowRight,
    Globe,
    Hash,
    Link2,
    Monitor,
    ScrollText,
    Server,
    Clock,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';

/*
| Audit trail detail.
|
| The point of this screen is the diff: "who changed what, from what, to what".
| Everything else is context that supports it, so the comparison gets the wide
| column and the metadata sits alongside.
*/

const props = defineProps({
    entry: { type: Object, required: true },
    diff: { type: Array, default: () => [] },
});

const ACTION_TONES = {
    create: 'success',
    update: 'info',
    delete: 'danger',
    login: 'ink',
    logout: 'ink',
    activate: 'success',
    deactivate: 'warn',
    view: 'ink',
};

const actionTone = computed(() => ACTION_TONES[props.entry.action] ?? 'ink');

const actionLabel = computed(() =>
    String(props.entry.action ?? '')
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' '),
);

const changedCount = computed(() => props.diff.filter((row) => row.changed).length);

/* A create has no "before" and a delete has no "after" — label the panel for
   what the entry actually captured rather than always saying "changes". */
const diffHeading = computed(() => {
    if (props.entry.action === 'create') {
        return 'Recorded values';
    }

    if (props.entry.action === 'delete') {
        return 'Values at deletion';
    }

    return 'Field changes';
});

const meta = computed(() => [
    { label: 'Module', value: props.entry.module, icon: ScrollText },
    { label: 'Record', value: props.entry.subject, icon: Hash, href: props.entry.subject_url },
    { label: 'IP address', value: props.entry.ip_address, icon: Server, mono: true },
    { label: 'Browser', value: props.entry.browser, icon: Monitor },
    { label: 'Platform', value: props.entry.platform, icon: Monitor },
    { label: 'Request', value: props.entry.method, icon: Globe },
]);
</script>

<template>
    <AppLayout title="Audit entry">
        <PageHeader
            :title="`Audit entry #${entry.id}`"
            :subtitle="entry.description || 'No description was recorded for this event.'"
            :back-href="route('admin.audit.index')"
            back-label="Back to audit trail"
            :breadcrumbs="[
                { label: 'Audit trail', href: route('admin.audit.index') },
                { label: `#${entry.id}` },
            ]"
        >
            <template #actions>
                <Badge size="md" :tone="actionTone" :label="actionLabel" />
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <!-- Diff -->
            <div class="min-w-0 space-y-5 xl:col-span-2">
                <Card
                    :title="diffHeading"
                    :subtitle="
                        diff.length
                            ? `${changedCount} of ${diff.length} field${diff.length === 1 ? '' : 's'} differ`
                            : null
                    "
                    padding="none"
                >
                    <EmptyState
                        v-if="!diff.length"
                        :icon="ScrollText"
                        size="sm"
                        title="No field-level values recorded"
                        description="Sign-in, sign-out and view events describe an action rather than a data change, so there is nothing to compare."
                    />

                    <div v-else class="w-full overflow-x-auto">
                        <table class="w-full min-w-[44rem] border-collapse">
                            <thead class="bg-ink-50 text-ink-500">
                                <tr class="border-b border-ink-200/70">
                                    <th
                                        scope="col"
                                        class="w-48 px-5 py-3 text-left text-xs font-semibold tracking-wide uppercase"
                                    >
                                        Field
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-5 py-3 text-left text-xs font-semibold tracking-wide uppercase"
                                    >
                                        Before
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-5 py-3 text-left text-xs font-semibold tracking-wide uppercase"
                                    >
                                        After
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-ink-200/70 text-sm">
                                <tr
                                    v-for="row in diff"
                                    :key="row.key"
                                    :class="row.changed ? '' : 'opacity-60'"
                                >
                                    <th
                                        scope="row"
                                        class="px-5 py-3 text-left align-top font-medium text-ink-800"
                                    >
                                        {{ row.label }}
                                        <span class="mt-0.5 block font-mono text-[11px] font-normal text-ink-400">
                                            {{ row.key }}
                                        </span>
                                    </th>

                                    <td class="px-5 py-3 align-top">
                                        <pre
                                            v-if="row.old"
                                            class="max-w-xs overflow-x-auto rounded-lg bg-danger-50 px-2.5 py-1.5 font-mono text-xs leading-relaxed whitespace-pre-wrap text-danger-700 ring-1 ring-danger-500/20 ring-inset"
                                        >{{ row.old }}</pre>
                                        <span v-else class="text-xs text-ink-400 italic">empty</span>
                                    </td>

                                    <td class="px-5 py-3 align-top">
                                        <div class="flex items-start gap-2">
                                            <ArrowRight
                                                :size="14"
                                                class="mt-1.5 hidden shrink-0 text-ink-300 sm:block"
                                                aria-hidden="true"
                                            />
                                            <pre
                                                v-if="row.new"
                                                class="max-w-xs overflow-x-auto rounded-lg bg-success-50 px-2.5 py-1.5 font-mono text-xs leading-relaxed whitespace-pre-wrap text-success-700 ring-1 ring-success-500/20 ring-inset"
                                            >{{ row.new }}</pre>
                                            <span v-else class="text-xs text-ink-400 italic">empty</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <!-- Request fingerprint -->
                <Card title="Request" subtitle="Where the action came from.">
                    <dl class="space-y-4">
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium text-ink-500">
                                <Link2 :size="13" aria-hidden="true" />
                                URL
                            </dt>
                            <dd
                                class="mt-1.5 rounded-lg bg-ink-50 px-3 py-2 font-mono text-xs leading-relaxed break-all text-ink-700"
                            >
                                {{ entry.url || '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium text-ink-500">
                                <Monitor :size="13" aria-hidden="true" />
                                User agent
                            </dt>
                            <dd
                                class="mt-1.5 rounded-lg bg-ink-50 px-3 py-2 font-mono text-xs leading-relaxed break-all text-ink-700"
                            >
                                {{ entry.user_agent || '—' }}
                            </dd>
                        </div>
                    </dl>
                </Card>
            </div>

            <!-- Context -->
            <div class="min-w-0 space-y-5">
                <Card title="Actor">
                    <div class="space-y-3">
                        <div>
                            <p class="text-xs font-medium text-ink-500">User</p>
                            <p class="mt-0.5 text-sm font-semibold text-ink-900">
                                {{ entry.user_name }}
                            </p>
                            <p v-if="entry.user_id && !entry.user_exists" class="text-xs text-ink-400">
                                This account has since been removed — the name above is the snapshot
                                taken when the action happened.
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-medium text-ink-500">Role</p>
                            <p class="mt-0.5 text-sm text-ink-800 capitalize">
                                {{ entry.role_name }}
                            </p>
                        </div>

                        <div>
                            <p class="flex items-center gap-1.5 text-xs font-medium text-ink-500">
                                <Clock :size="13" aria-hidden="true" />
                                When
                            </p>
                            <p class="mt-0.5 text-sm text-ink-800 tabular-nums">
                                {{ entry.created_at_label }}
                            </p>
                            <p class="text-xs text-ink-400">{{ entry.created_at_human }}</p>
                        </div>
                    </div>
                </Card>

                <Card title="Context">
                    <dl class="space-y-3.5">
                        <div v-for="item in meta" :key="item.label" class="flex items-start gap-2.5">
                            <component
                                :is="item.icon"
                                :size="14"
                                class="mt-0.5 shrink-0 text-ink-400"
                                aria-hidden="true"
                            />
                            <div class="min-w-0 flex-1">
                                <dt class="text-xs font-medium text-ink-500">{{ item.label }}</dt>
                                <dd
                                    :class="[
                                        'mt-0.5 text-sm break-words text-ink-800',
                                        item.mono ? 'font-mono text-xs tabular-nums' : '',
                                    ]"
                                >
                                    <Link
                                        v-if="item.href && item.value"
                                        :href="item.href"
                                        class="font-medium text-brand-600 underline-offset-2 transition-colors hover:text-brand-700 hover:underline"
                                    >
                                        {{ item.value }}
                                    </Link>
                                    <template v-else>{{ item.value || '—' }}</template>
                                </dd>
                            </div>
                        </div>
                    </dl>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
