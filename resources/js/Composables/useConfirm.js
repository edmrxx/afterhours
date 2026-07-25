import { router } from '@inertiajs/vue3';
import { useSwal } from './useSwal';

/*
| useConfirm — sugar over useSwal for the "ask, then hit the server" pattern
| that repeats across every admin screen. It keeps pages free of boilerplate:
|
|   const { confirmThenDelete } = useConfirm()
|   confirmThenDelete(route('admin.courts.destroy', court.id), court.name)
*/

export function useConfirm() {
    const { confirmDelete, confirmAction } = useSwal();

    /**
     * Ask for confirmation, then DELETE the given URL.
     *
     * @param {string} url
     * @param {string} name Human label shown in the dialog.
     * @param {object} options Extra Inertia visit options.
     * @returns {Promise<boolean>} whether the request was dispatched.
     */
    async function confirmThenDelete(url, name = 'this record', options = {}) {
        if (!(await confirmDelete(name))) {
            return false;
        }

        router.delete(url, {
            preserveScroll: true,
            preserveState: true,
            ...options,
        });

        return true;
    }

    /**
     * Ask for confirmation, then POST (or any method) to the given URL.
     *
     * @returns {Promise<boolean>} whether the request was dispatched.
     */
    async function confirmThenVisit(
        url,
        { method = 'post', data = {}, dialog = {}, ...options } = {},
    ) {
        if (!(await confirmAction(dialog))) {
            return false;
        }

        router.visit(url, {
            method,
            data,
            preserveScroll: true,
            preserveState: true,
            ...options,
        });

        return true;
    }

    return { confirmDelete, confirmAction, confirmThenDelete, confirmThenVisit };
}

export default useConfirm;
