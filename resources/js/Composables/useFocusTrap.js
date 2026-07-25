import { nextTick, onBeforeUnmount, watch } from 'vue';

/*
| useFocusTrap — keyboard containment for overlays (Modal, Drawer).
|
| Locks body scroll, moves focus into the panel when it opens, cycles Tab inside
| it, and restores focus to the element that triggered it on close.
*/

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

/** Tracks how many overlays are open so nested ones do not fight over scroll. */
let lockCount = 0;

function lockScroll() {
    if (typeof document === 'undefined') {
        return;
    }

    lockCount += 1;

    if (lockCount === 1) {
        document.body.style.overflow = 'hidden';
    }
}

function unlockScroll() {
    if (typeof document === 'undefined' || lockCount === 0) {
        return;
    }

    lockCount -= 1;

    if (lockCount === 0) {
        document.body.style.overflow = '';
    }
}

/**
 * @param {import('vue').Ref<HTMLElement|null>} containerRef
 * @param {import('vue').Ref<boolean>|(() => boolean)} isOpen
 * @param {{ onEscape?: () => void, autoFocus?: boolean }} options
 */
export function useFocusTrap(containerRef, isOpen, options = {}) {
    const { onEscape = null, autoFocus = true } = options;

    let previouslyFocused = null;
    let locked = false;

    function focusable() {
        const root = containerRef.value;

        if (!root) {
            return [];
        }

        return Array.from(root.querySelectorAll(FOCUSABLE)).filter(
            (el) => el.offsetParent !== null || el === document.activeElement,
        );
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            event.stopPropagation();
            onEscape?.();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const items = focusable();

        if (items.length === 0) {
            event.preventDefault();
            containerRef.value?.focus?.();

            return;
        }

        const first = items[0];
        const last = items[items.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && (active === first || !containerRef.value?.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    async function activate() {
        if (locked || typeof document === 'undefined') {
            return;
        }

        locked = true;
        previouslyFocused = document.activeElement;
        lockScroll();
        document.addEventListener('keydown', onKeydown, true);

        if (!autoFocus) {
            return;
        }

        await nextTick();

        const [first] = focusable();
        (first ?? containerRef.value)?.focus?.({ preventScroll: true });
    }

    function deactivate() {
        if (!locked || typeof document === 'undefined') {
            return;
        }

        locked = false;
        document.removeEventListener('keydown', onKeydown, true);
        unlockScroll();
        previouslyFocused?.focus?.({ preventScroll: true });
        previouslyFocused = null;
    }

    watch(
        typeof isOpen === 'function' ? isOpen : () => isOpen.value,
        (open) => (open ? activate() : deactivate()),
        { immediate: true },
    );

    // A modal unmounted while still open must not leave the body scroll-locked.
    onBeforeUnmount(deactivate);

    return { activate, deactivate };
}

export default useFocusTrap;
