<script setup>
import { computed } from 'vue';
import { Ban, Lock, Pencil, Trash2, Unlock } from '@lucide/vue';
import IconButton from '@/Components/IconButton.vue';
import Tooltip from '@/Components/Tooltip.vue';
import { statusLabel, statusTone } from '@/Composables/useStatus';

/*
| SlotChip — one court slot inside a day column.
|
| Status is carried by an accent rail rather than by tinting the whole chip, so
| a dense week of forty slots still reads as a list instead of a colour field.
| Held and booked slots are visibly locked: their selection checkbox and their
| destructive actions are gone, not merely disabled-looking, because the server
| refuses those operations anyway.
*/

const props = defineProps({
    slot: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    canUpdate: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'edit', 'block', 'remove']);

const RAIL = {
    success: 'bg-success-500',
    warn: 'bg-warn-500',
    brand: 'bg-brand-500',
    danger: 'bg-danger-500',
    info: 'bg-info-500',
    ink: 'bg-ink-400',
};

const DOT = {
    success: 'text-success-700',
    warn: 'text-warn-700',
    brand: 'text-brand-700',
    danger: 'text-danger-700',
    info: 'text-info-600',
    ink: 'text-ink-500',
};

const tone = computed(() => statusTone(props.slot.status));
const label = computed(() => statusLabel(props.slot.status));
const locked = computed(() => props.slot.is_locked === true);
const selectable = computed(() => props.canDelete && !locked.value);
const blocked = computed(() => props.slot.status === 'blocked');

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

const price = computed(() => peso.format(Number(props.slot.price ?? 0)));

/** "08:00" → "8:00 AM" without dragging in a date library. */
function clock(value) {
    const [rawHour, rawMinute] = String(value ?? '').split(':');
    const hour = Number(rawHour);

    if (Number.isNaN(hour)) {
        return '—';
    }

    const suffix = hour >= 12 ? 'PM' : 'AM';
    const display = hour % 12 === 0 ? 12 : hour % 12;

    return `${display}:${rawMinute ?? '00'} ${suffix}`;
}

const timeRange = computed(() => `${clock(props.slot.start_time)} – ${clock(props.slot.end_time)}`);

const duration = computed(() => {
    const minutes = Number(props.slot.duration_minutes ?? 0);

    if (minutes <= 0) {
        return null;
    }

    if (minutes < 60) {
        return `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
});

const holder = computed(() => props.slot.held_booking ?? null);

function onActivate() {
    if (props.canUpdate && !locked.value) {
        emit('edit', props.slot);
    }
}
</script>

<template>
    <div
        :class="[
            'group relative flex items-stretch gap-0 overflow-hidden rounded-lg border bg-white',
            'transition-all duration-150 ease-[var(--ease-out-soft)]',
            selected
                ? 'border-brand-400 ring-2 ring-brand-500/25'
                : 'border-ink-200/80 hover:border-ink-300 hover:shadow-card',
            slot.is_past ? 'opacity-60' : '',
        ]"
    >
        <span :class="['w-1 shrink-0', RAIL[tone] ?? RAIL.ink]" aria-hidden="true" />

        <div class="flex min-w-0 flex-1 items-center gap-2 py-2 pr-2 pl-2">
            <label
                v-if="selectable"
                class="flex shrink-0 cursor-pointer items-center"
                :title="`Select ${timeRange}`"
            >
                <input
                    type="checkbox"
                    :checked="selected"
                    class="h-4 w-4 cursor-pointer rounded border-ink-300 text-brand-600 accent-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                    @change="emit('toggle', slot)"
                />
                <span class="sr-only">Select slot {{ timeRange }}</span>
            </label>

            <Tooltip v-else-if="locked" :text="`${label} — cannot be changed`">
                <span class="flex h-4 w-4 shrink-0 items-center justify-center text-ink-400">
                    <Lock :size="13" aria-hidden="true" />
                </span>
            </Tooltip>

            <button
                type="button"
                :class="[
                    'min-w-0 flex-1 rounded text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                    canUpdate && !locked ? 'cursor-pointer' : 'cursor-default',
                ]"
                :aria-label="canUpdate && !locked ? `Edit slot ${timeRange}` : `Slot ${timeRange}`"
                @click="onActivate"
            >
                <span class="flex items-baseline gap-1.5">
                    <span class="truncate text-sm font-semibold tabular-nums text-ink-900">
                        {{ timeRange }}
                    </span>
                    <span v-if="duration" class="shrink-0 text-[11px] text-ink-400">
                        {{ duration }}
                    </span>
                </span>

                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span class="text-xs font-medium tabular-nums text-ink-600">{{ price }}</span>
                    <span :class="['text-[11px] font-medium', DOT[tone] ?? DOT.ink]">
                        {{ label }}
                    </span>
                </span>

                <span
                    v-if="holder"
                    class="mt-1 flex min-w-0 items-center gap-1 text-[11px] text-ink-500"
                >
                    <span class="truncate">{{ holder.customer_name }}</span>
                    <span class="shrink-0 font-mono text-ink-400">{{ holder.code }}</span>
                </span>
            </button>

            <div
                v-if="!locked && (canUpdate || canDelete)"
                class="flex shrink-0 items-center opacity-0 transition-opacity duration-150 ease-[var(--ease-out-soft)] group-focus-within:opacity-100 group-hover:opacity-100 max-sm:opacity-100"
            >
                <IconButton
                    v-if="canUpdate"
                    variant="edit"
                    size="sm"
                    label="Edit slot"
                    @click="emit('edit', slot)"
                >
                    <Pencil :size="14" />
                </IconButton>

                <IconButton
                    v-if="canUpdate"
                    variant="warn"
                    size="sm"
                    :label="blocked ? 'Unblock slot' : 'Block slot'"
                    @click="emit('block', slot)"
                >
                    <component :is="blocked ? Unlock : Ban" :size="14" />
                </IconButton>

                <IconButton
                    v-if="canDelete"
                    variant="delete"
                    size="sm"
                    label="Delete slot"
                    @click="emit('remove', slot)"
                >
                    <Trash2 :size="14" />
                </IconButton>
            </div>
        </div>
    </div>
</template>
