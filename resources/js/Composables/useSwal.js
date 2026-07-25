import Swal from 'sweetalert2';

/*
| useSwal — the single entry point for SweetAlert2 in the whole application.
|
| No page, component or layout may import `sweetalert2` directly. Everything
| routes through here so the `phea-swal` / `phea-swal-toast` skins declared in
| resources/css/app.css are applied consistently, and so button colours are read
| from the Tailwind `@theme` tokens instead of being hardcoded.
*/

const POPUP_CLASS = 'phea-swal';
const TOAST_CLASS = 'phea-swal-toast';

/** Resolved design tokens, read once from the document and memoised. */
let tokenCache = null;

function tokens() {
    if (tokenCache) {
        return tokenCache;
    }

    const read = (name) => {
        if (typeof window === 'undefined' || typeof document === 'undefined') {
            return undefined;
        }

        const value = getComputedStyle(document.documentElement)
            .getPropertyValue(name)
            .trim();

        return value === '' ? undefined : value;
    };

    tokenCache = {
        brand: read('--color-brand-600'),
        danger: read('--color-danger-600'),
        success: read('--color-success-600'),
        warn: read('--color-warn-600'),
        info: read('--color-info-600'),
        neutral: read('--color-ink-200'),
        ink: read('--color-ink-900'),
    };

    return tokenCache;
}

/** Confirm-button colour per semantic variant. */
function variantColor(variant) {
    const t = tokens();

    return {
        primary: t.brand,
        brand: t.brand,
        danger: t.danger,
        success: t.success,
        warning: t.warn,
        warn: t.warn,
        info: t.info,
    }[variant] ?? t.brand;
}

/** Default SweetAlert icon per semantic variant. */
function variantIcon(variant) {
    return {
        primary: 'question',
        brand: 'question',
        danger: 'warning',
        success: 'success',
        warning: 'warning',
        warn: 'warning',
        info: 'info',
    }[variant] ?? 'question';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/** Toast preset: top-right, 3s, auto-dismiss, no backdrop, pauses on hover. */
const toaster = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    showCloseButton: false,
    timer: 3000,
    timerProgressBar: true,
    backdrop: false,
    customClass: { popup: TOAST_CLASS },
    didOpen: (el) => {
        el.addEventListener('mouseenter', Swal.stopTimer);
        el.addEventListener('mouseleave', Swal.resumeTimer);
    },
});

function toast(icon, message) {
    if (!message) {
        return Promise.resolve();
    }

    return toaster.fire({ icon, title: String(message) });
}

export function useSwal() {
    const toastSuccess = (message) => toast('success', message);
    const toastError = (message) => toast('error', message);
    const toastInfo = (message) => toast('info', message);
    const toastWarning = (message) => toast('warning', message);

    /**
     * Generic confirmation dialog.
     *
     * @returns {Promise<boolean>} true when the user confirmed.
     */
    async function confirmAction({
        title = 'Are you sure?',
        text = '',
        html = null,
        confirmText = 'Confirm',
        cancelText = 'Cancel',
        variant = 'primary',
        icon = null,
        input = null,
        inputLabel = '',
        inputPlaceholder = '',
        inputValidator = null,
    } = {}) {
        const result = await Swal.fire({
            title,
            text: html ? undefined : text,
            html: html ?? undefined,
            icon: icon ?? variantIcon(variant),
            input: input ?? undefined,
            inputLabel: input ? inputLabel : undefined,
            inputPlaceholder: input ? inputPlaceholder : undefined,
            inputValidator: input ? inputValidator ?? undefined : undefined,
            showCancelButton: true,
            reverseButtons: true,
            focusCancel: variant === 'danger',
            confirmButtonText: confirmText,
            cancelButtonText: cancelText,
            confirmButtonColor: variantColor(variant),
            cancelButtonColor: tokens().neutral,
            customClass: { popup: POPUP_CLASS },
        });

        return result.isConfirmed === true;
    }

    /**
     * Destructive confirmation used by every delete action in the admin.
     *
     * @param {string} name Human label of the record being removed.
     * @returns {Promise<boolean>}
     */
    async function confirmDelete(name = 'this record') {
        return confirmAction({
            title: 'Delete this record?',
            html:
                `<strong>${escapeHtml(name)}</strong> will be removed. ` +
                'This action cannot be undone from this screen.',
            confirmText: 'Yes, delete it',
            cancelText: 'Keep it',
            variant: 'danger',
            icon: 'warning',
        });
    }

    /** Renders an Inertia `errors` object as a readable bullet list. */
    function showValidationErrors(errors = {}) {
        const messages = Object.values(errors ?? {})
            .flat()
            .filter(Boolean);

        if (messages.length === 0) {
            return Promise.resolve();
        }

        const list = messages
            .map((message) => `<li style="margin:0.25rem 0">${escapeHtml(message)}</li>`)
            .join('');

        return Swal.fire({
            icon: 'error',
            title: 'Please check the form',
            html: `<ul style="text-align:left;padding-left:1.1rem;margin:0">${list}</ul>`,
            confirmButtonText: 'Got it',
            confirmButtonColor: variantColor('danger'),
            customClass: { popup: POPUP_CLASS },
        });
    }

    /** @returns {Promise<boolean>} */
    async function confirmLogout() {
        return confirmAction({
            title: 'Sign out?',
            text: 'You will need to sign in again to return to the dashboard.',
            confirmText: 'Sign out',
            cancelText: 'Stay signed in',
            variant: 'danger',
            icon: 'question',
        });
    }

    /** Terminal dialog shown when the session is no longer valid. */
    function sessionExpired() {
        return Swal.fire({
            icon: 'warning',
            title: 'Session expired',
            text: 'For your security you have been signed out. Please sign in again to continue.',
            confirmButtonText: 'Sign in',
            confirmButtonColor: variantColor('primary'),
            allowOutsideClick: false,
            allowEscapeKey: false,
            customClass: { popup: POPUP_CLASS },
        }).then(() => {
            if (typeof window !== 'undefined') {
                window.location.href = '/login';
            }
        });
    }

    /** Blocking spinner for long running, non-cancellable work. */
    function showLoading(title = 'Working on it…') {
        Swal.fire({
            title,
            allowOutsideClick: false,
            allowEscapeKey: false,
            customClass: { popup: POPUP_CLASS },
            didOpen: () => Swal.showLoading(),
        });
    }

    function closeLoading() {
        Swal.close();
    }

    return {
        toastSuccess,
        toastError,
        toastInfo,
        toastWarning,
        confirmDelete,
        confirmAction,
        showValidationErrors,
        confirmLogout,
        sessionExpired,
        showLoading,
        closeLoading,
    };
}

export default useSwal;
