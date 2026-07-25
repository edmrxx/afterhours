<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';

/*
| Dropdown — headless, keyboard navigable menu.
|
|   <Dropdown align="right">
|       <template #trigger="{ toggle, open }">
|           <Button variant="secondary" @click="toggle">Actions</Button>
|       </template>
|       <DropdownItem :icon="Pencil" :href="route('admin.courts.edit', court.id)">Edit</DropdownItem>
|       <DropdownItem variant="danger" :icon="Trash2" @click="destroy">Delete</DropdownItem>
|   </Dropdown>
|
| Keyboard: Enter/Space/ArrowDown open · ArrowUp/ArrowDown move · Home/End jump ·
| Esc closes and returns focus to the trigger · Tab closes.
*/

const props = defineProps({
    /** left | right — horizontal anchoring of the menu. */
    align: { type: String, default: 'right' },
    /** bottom | top */
    side: { type: String, default: 'bottom' },
    /** Tailwind width utility for the panel. */
    width: { type: String, default: 'w-56' },
    disabled: { type: Boolean, default: false },
    /** Keep the menu open after an item is activated. */
    persistent: { type: Boolean, default: false },
});

const emit = defineEmits(['open', 'close']);

const open = ref(false);
const root = ref(null);
const panel = ref(null);
const uid = useId();
const menuId = `${uid}-menu`;

function items() {
    if (!panel.value) {
        return [];
    }

    return Array.from(
        panel.value.querySelectorAll('[role="menuitem"]:not([aria-disabled="true"])'),
    );
}

function focusAt(index) {
    const list = items();

    if (list.length === 0) {
        return;
    }

    const target = ((index % list.length) + list.length) % list.length;
    list[target].focus();
}

function currentIndex() {
    return items().indexOf(document.activeElement);
}

async function show(focusFirst = false) {
    if (props.disabled || open.value) {
        return;
    }

    open.value = true;
    emit('open');

    await nextTick();

    if (focusFirst) {
        focusAt(0);
    }
}

function hide(restoreFocus = true) {
    if (!open.value) {
        return;
    }

    open.value = false;
    emit('close');

    if (restoreFocus) {
        root.value?.querySelector('[data-dropdown-trigger] button, [data-dropdown-trigger] a')?.focus?.();
    }
}

function toggle() {
    open.value ? hide() : show();
}

function onDocumentPointer(event) {
    if (open.value && root.value && !root.value.contains(event.target)) {
        hide(false);
    }
}

function onKeydown(event) {
    if (props.disabled) {
        return;
    }

    switch (event.key) {
        case 'Escape':
            if (open.value) {
                event.preventDefault();
                hide();
            }
            break;
        case 'ArrowDown':
            event.preventDefault();
            open.value ? focusAt(currentIndex() + 1) : show(true);
            break;
        case 'ArrowUp':
            event.preventDefault();
            open.value ? focusAt(currentIndex() - 1) : show(true);
            break;
        case 'Home':
            if (open.value) {
                event.preventDefault();
                focusAt(0);
            }
            break;
        case 'End':
            if (open.value) {
                event.preventDefault();
                focusAt(items().length - 1);
            }
            break;
        case 'Tab':
            if (open.value) {
                hide(false);
            }
            break;
        default:
            break;
    }
}

/** DropdownItem activations bubble here so the menu closes itself. */
function onPanelClick(event) {
    if (props.persistent) {
        return;
    }

    if (event.target.closest?.('[role="menuitem"]')) {
        hide(false);
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointer, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointer, true);
});

const panelPosition = computed(() => [
    props.align === 'left' ? 'left-0 origin-top-left' : 'right-0 origin-top-right',
    props.side === 'top' ? 'bottom-full mb-2' : 'top-full mt-2',
]);

defineExpose({ open, show, hide, toggle });
</script>

<template>
    <div ref="root" class="relative inline-block text-left" @keydown="onKeydown">
        <div data-dropdown-trigger :aria-expanded="open" :aria-controls="menuId">
            <slot name="trigger" :open="open" :toggle="toggle" :show="show" :hide="hide" />
        </div>

        <Transition
            enter-active-class="transition duration-150 ease-[var(--ease-out-soft)]"
            enter-from-class="opacity-0 scale-95 -translate-y-1"
            leave-active-class="transition duration-100 ease-[var(--ease-out-soft)]"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="open"
                :id="menuId"
                ref="panel"
                role="menu"
                aria-orientation="vertical"
                :class="[
                    'absolute z-40 overflow-hidden rounded-xl border border-ink-200/70 bg-white p-1.5 shadow-float',
                    width,
                    ...panelPosition,
                ]"
                @click="onPanelClick"
            >
                <slot :close="hide" />
            </div>
        </Transition>
    </div>
</template>
