import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

/*
| useDataTable — the server-side table engine behind every index screen.
|
| It owns search / sort / direction / page / per-page / arbitrary filter state,
| hydrates that state from the current URL (so a hard refresh, a back button or
| a `->withQueryString()` redirect all restore the table exactly), and pushes
| changes back to the server with a partial Inertia reload.
|
| Query string contract — controllers must read these exact names:
|
|   search      string   free-text search
|   sort        string   column key
|   direction   asc|desc
|   page        int      Laravel paginator page
|   per_page    int      rows per page (25 default)
|   <filter>    mixed    every key of `initialFilters`, by its own name
|
| Usage:
|
|   const table = useDataTable('admin.courts.index', { status: '' }, {
|       only: ['courts', 'filters'],
|       sort: 'created_at',
|   })
|
|   <DataTable
|       :loading="table.loading"
|       :sort="table.sort"
|       :direction="table.direction"
|       v-model:search="table.search"
|       v-model:per-page="table.perPage"
|       :filtered="table.hasActiveFilters"
|       @sort="table.sortBy"
|   />
|
| The returned object is `reactive`, so every member is a plain value in both
| script and template — no `.value` anywhere, and assignment still triggers a
| server round trip (`table.perPage = 50`).
*/

const DEFAULT_PER_PAGE = 25;

function currentQuery() {
    if (typeof window === 'undefined') {
        return new URLSearchParams();
    }

    return new URLSearchParams(window.location.search);
}

function isBlank(value) {
    return (
        value === null ||
        value === undefined ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

export function useDataTable(routeName, initialFilters = {}, options = {}) {
    const {
        only = [],
        except = [],
        routeParams = {},
        perPage: defaultPerPage = DEFAULT_PER_PAGE,
        sort: defaultSort = null,
        direction: defaultDirection = 'desc',
        debounce: debounceMs = 300,
        preserveScroll = true,
        onSuccess = null,
    } = options;

    const query = currentQuery();

    const fromQuery = (key, fallback) => {
        const raw = query.get(key);

        return raw === null || raw === '' ? fallback : raw;
    };

    /* ------------------------------------------------------------------ */
    /* State — hydrated from the URL so it round-trips with withQueryString */
    /* ------------------------------------------------------------------ */

    const search = ref(String(fromQuery('search', '')));
    const sort = ref(fromQuery('sort', defaultSort));
    const direction = ref(fromQuery('direction', defaultDirection) === 'asc' ? 'asc' : 'desc');
    const page = ref(Math.max(1, Number(fromQuery('page', 1)) || 1));
    const perPage = ref(Number(fromQuery('per_page', defaultPerPage)) || defaultPerPage);

    const filters = reactive(
        Object.fromEntries(
            Object.entries(initialFilters).map(([key, fallback]) => {
                const raw = query.get(key);

                if (raw === null) {
                    return [key, fallback];
                }

                // Preserve the shape of the declared default where we can.
                if (typeof fallback === 'number') {
                    return [key, raw === '' ? fallback : Number(raw)];
                }

                if (typeof fallback === 'boolean') {
                    return [key, raw === '1' || raw === 'true'];
                }

                return [key, raw];
            }),
        ),
    );

    const loading = ref(false);

    /** The exact payload sent to the server — blanks stripped so URLs stay clean. */
    const params = computed(() => {
        const payload = {
            search: search.value,
            sort: sort.value,
            direction: sort.value ? direction.value : null,
            page: page.value > 1 ? page.value : null,
            per_page: Number(perPage.value) === DEFAULT_PER_PAGE ? null : perPage.value,
            ...filters,
        };

        return Object.fromEntries(
            Object.entries(payload).filter(([, value]) => !isBlank(value)),
        );
    });

    /* ------------------------------------------------------------------ */
    /* Visiting                                                            */
    /* ------------------------------------------------------------------ */

    let cancelPrevious = null;
    let searchTimer = null;

    /*
     | While `muted` is true every watcher stands down. It is held across a tick
     | in reset() so the batch of state changes produces exactly one request
     | instead of one per watcher, and held synchronously in resetPage() so the
     | sync page watcher does not double-fire alongside its caller.
     */
    let muted = false;

    function muteSync(mutate) {
        const previous = muted;

        muted = true;

        try {
            mutate();
        } finally {
            muted = previous;
        }
    }

    function visit() {
        // Accept either a Ziggy route name ("admin.courts.index") or a raw URL.
        const target = routeName.startsWith('/') ? routeName : route(routeName, routeParams);

        cancelPrevious?.();

        router.get(target, params.value, {
            preserveState: true,
            preserveScroll,
            replace: true,
            ...(only.length ? { only } : {}),
            ...(except.length ? { except } : {}),
            onCancelToken: ({ cancel }) => {
                cancelPrevious = cancel;
            },
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
                cancelPrevious = null;
            },
            onSuccess: (visitPage) => onSuccess?.(visitPage),
        });
    }

    /** Reset to page 1 without letting the page watcher fire a second request. */
    function resetPage() {
        if (page.value === 1) {
            return;
        }

        muteSync(() => {
            page.value = 1;
        });
    }

    function refresh({ resetPage: reset = true } = {}) {
        if (reset) {
            resetPage();
        }

        visit();
    }

    /* ------------------------------------------------------------------ */
    /* Reactions                                                           */
    /* ------------------------------------------------------------------ */

    watch(search, () => {
        if (muted) {
            return;
        }

        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            resetPage();
            visit();
        }, debounceMs);
    });

    watch([sort, direction, perPage], () => {
        if (muted) {
            return;
        }

        resetPage();
        visit();
    });

    watch(
        filters,
        () => {
            if (muted) {
                return;
            }

            resetPage();
            visit();
        },
        { deep: true },
    );

    // Sync flush so `resetPage()` can mute this watcher deterministically.
    watch(
        page,
        () => {
            if (!muted) {
                visit();
            }
        },
        { flush: 'sync' },
    );

    onBeforeUnmount(() => {
        clearTimeout(searchTimer);
        cancelPrevious?.();
    });

    /* ------------------------------------------------------------------ */
    /* Public API                                                          */
    /* ------------------------------------------------------------------ */

    /** Toggle sorting on a column: new column → asc, same column → flip. */
    function sortBy(column) {
        if (!column) {
            return;
        }

        if (sort.value === column) {
            direction.value = direction.value === 'asc' ? 'desc' : 'asc';

            return;
        }

        sort.value = column;
        direction.value = 'asc';
    }

    function goToPage(target) {
        const next = Math.max(1, Number(target) || 1);

        if (next !== page.value) {
            page.value = next;
        }
    }

    /** True when the user has narrowed the table in any way. */
    const hasActiveFilters = computed(
        () =>
            !isBlank(search.value) ||
            Object.entries(filters).some(
                ([key, value]) => !isBlank(value) && value !== initialFilters[key],
            ),
    );

    /** Clear search, filters, sort and paging, then make a single request. */
    async function reset() {
        clearTimeout(searchTimer);

        muted = true;

        search.value = '';
        page.value = 1;
        perPage.value = defaultPerPage;
        sort.value = defaultSort;
        direction.value = defaultDirection;
        Object.entries(initialFilters).forEach(([key, value]) => {
            filters[key] = value;
        });

        // Let the pre-flush watchers run while still muted, so this batch of
        // changes collapses into the one visit below.
        await nextTick();

        muted = false;
        visit();
    }

    /** Direction currently applied to `column`, or null. */
    const directionFor = (column) => (sort.value === column ? direction.value : null);

    // `reactive` unwraps the refs, so consumers never write `.value` — in a
    // template `table.search` is the string and `table.search = 'x'` still
    // drives the watcher. Nested refs would NOT auto-unwrap if returned raw.
    return reactive({
        // state
        search,
        sort,
        direction,
        page,
        perPage,
        filters,
        loading,
        params,
        hasActiveFilters,
        // actions
        sortBy,
        goToPage,
        refresh,
        reset,
        directionFor,
    });
}

export default useDataTable;
