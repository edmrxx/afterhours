import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/*
| usePermissions — UI gating.
|
| Permissions are the only access primitive (see docs/ARCHITECTURE.md §2).
| Never gate on a role name: new roles must work without touching code.
|
| Guests (auth.user === null) get `false` for everything and never throw, so the
| same components can be reused on the public site.
*/

function toArray(value) {
    if (Array.isArray(value)) {
        return value;
    }

    // Laravel Collections serialise to arrays, but be defensive about objects.
    if (value && typeof value === 'object') {
        return Object.values(value);
    }

    return [];
}

export function usePermissions() {
    let page = null;

    try {
        page = usePage();
    } catch {
        page = null;
    }

    const user = computed(() => page?.props?.auth?.user ?? null);

    const permissions = computed(() => toArray(user.value?.permissions).map(String));

    const roles = computed(() => toArray(user.value?.roles).map(String));

    const isAuthenticated = computed(() => user.value !== null);

    /** @param {string} permission */
    const can = (permission) =>
        typeof permission === 'string' &&
        permission !== '' &&
        permissions.value.includes(permission);

    /** @param {string} permission */
    const cannot = (permission) => !can(permission);

    /** @param {string[]} list — true when the user holds at least one. */
    const canAny = (list) => toArray(list).some((permission) => can(permission));

    /** @param {string[]} list — true only when the user holds every one. */
    const canAll = (list) => {
        const wanted = toArray(list);

        return wanted.length > 0 && wanted.every((permission) => can(permission));
    };

    /** @param {string} role — for display/labelling only, never for gating. */
    const hasRole = (role) =>
        typeof role === 'string' && role !== '' && roles.value.includes(role);

    /** @param {string[]} list */
    const hasAnyRole = (list) => toArray(list).some((role) => hasRole(role));

    const primaryRole = computed(() => roles.value[0] ?? null);

    return {
        user,
        permissions,
        roles,
        primaryRole,
        isAuthenticated,
        can,
        cannot,
        canAny,
        canAll,
        hasRole,
        hasAnyRole,
    };
}

export default usePermissions;
