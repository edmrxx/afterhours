<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    CalendarClock,
    ChartColumn,
    ClipboardList,
    KeyRound,
    LayoutDashboard,
    LogOut,
    MapPin,
    Menu,
    PanelLeft,
    ScrollText,
    Settings,
    ShieldCheck,
    User,
    Users,
    X,
} from '@lucide/vue';
import Avatar from '@/Components/Avatar.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownItem from '@/Components/DropdownItem.vue';
import { usePermissions } from '@/Composables/usePermissions';
import { useSwal } from '@/Composables/useSwal';

/*
| AppLayout — the admin shell.
|
| Responsibilities that live here and nowhere else:
|   · sidebar navigation, gated by permissions from the shared Inertia props
|   · flash-message consumption (pages must never handle flash themselves)
|   · validation-error surfacing
|   · logout confirmation
|   · page transitions and the navigation progress bar
|
|   <AppLayout title="Courts">
|       <PageHeader … />
|       …page…
|   </AppLayout>
*/

const props = defineProps({
    /** Document title; also the fallback breadcrumb label. */
    title: { type: String, default: null },
    /** toast | dialog | none — how server validation errors are announced. */
    validationFeedback: { type: String, default: 'toast' },
});

const page = usePage();
const { can } = usePermissions();
const { toastSuccess, toastError, toastWarning, toastInfo, showValidationErrors, confirmLogout } =
    useSwal();

/* ------------------------------------------------------------------ */
/* Navigation                                                          */
/* ------------------------------------------------------------------ */

const NAV = [
    {
        label: 'Dashboard',
        icon: LayoutDashboard,
        permission: 'dashboard.view',
        routeName: 'admin.dashboard',
        pattern: 'admin.dashboard',
    },
    {
        label: 'Courts',
        icon: MapPin,
        permission: 'courts.view',
        routeName: 'admin.courts.index',
        pattern: 'admin.courts.*',
    },
    {
        label: 'Slots',
        icon: CalendarClock,
        permission: 'slots.view',
        routeName: 'admin.slots.index',
        pattern: 'admin.slots.*',
    },
    {
        label: 'Bookings',
        icon: ClipboardList,
        permission: 'bookings.view',
        routeName: 'admin.bookings.index',
        pattern: 'admin.bookings.*',
    },
    {
        label: 'Users',
        icon: Users,
        permission: 'users.view',
        routeName: 'admin.users.index',
        pattern: 'admin.users.*',
    },
    {
        label: 'Roles',
        icon: ShieldCheck,
        permission: 'roles.view',
        routeName: 'admin.roles.index',
        pattern: 'admin.roles.*',
    },
    {
        label: 'Settings',
        icon: Settings,
        permission: 'settings.view',
        routeName: 'admin.settings.payment',
        pattern: 'admin.settings.*',
    },
    {
        label: 'Audit Trail',
        icon: ScrollText,
        permission: 'audit.view',
        routeName: 'admin.audit.index',
        pattern: 'admin.audit.*',
    },
    {
        label: 'Reports',
        icon: ChartColumn,
        permission: 'reports.view',
        routeName: 'admin.reports.bookings',
        pattern: 'admin.reports.*',
    },
];

const navigation = computed(() =>
    NAV.filter((item) => can(item.permission)).map((item) => ({
        ...item,
        href: route(item.routeName),
    })),
);

/** `page.url` is referenced so the highlight recomputes on every visit. */
const activePattern = computed(() => {
    const url = page.url;

    return navigation.value.find((item) => {
        try {
            return route().current(item.pattern) && url !== undefined;
        } catch {
            return false;
        }
    })?.pattern;
});

const isActive = (item) => activePattern.value === item.pattern;

/* ------------------------------------------------------------------ */
/* Sidebar state                                                       */
/* ------------------------------------------------------------------ */

const STORAGE_KEY = 'phea.sidebar.collapsed';

const collapsed = ref(false);
const mobileOpen = ref(false);

onMounted(() => {
    collapsed.value = window.localStorage.getItem(STORAGE_KEY) === '1';
});

watch(collapsed, (value) => {
    window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0');
});

/* ------------------------------------------------------------------ */
/* Navigation progress + page transitions                              */
/* ------------------------------------------------------------------ */

const navigating = ref(false);
const stopListening = [];

onMounted(() => {
    stopListening.push(
        router.on('start', () => {
            navigating.value = true;
        }),
        router.on('finish', () => {
            navigating.value = false;
        }),
        router.on('navigate', () => {
            mobileOpen.value = false;
        }),
    );
});

onBeforeUnmount(() => {
    stopListening.forEach((off) => off?.());
});

/* ------------------------------------------------------------------ */
/* Flash + validation — consumed centrally, never in pages             */
/* ------------------------------------------------------------------ */

watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) {
            return;
        }

        if (flash.success) toastSuccess(flash.success);
        if (flash.error) toastError(flash.error);
        if (flash.warning) toastWarning(flash.warning);
        if (flash.info) toastInfo(flash.info);
    },
    { immediate: true, deep: true },
);

watch(
    () => page.props.errors,
    (errors) => {
        const messages = Object.values(errors ?? {}).flat().filter(Boolean);

        if (messages.length === 0 || props.validationFeedback === 'none') {
            return;
        }

        if (props.validationFeedback === 'dialog') {
            showValidationErrors(errors);

            return;
        }

        // Fields already render their own inline message; the toast is the
        // "something went wrong down the page" cue.
        toastError(
            messages.length === 1
                ? messages[0]
                : `Please correct ${messages.length} highlighted fields.`,
        );
    },
    { deep: true },
);

/* ------------------------------------------------------------------ */
/* User menu                                                           */
/* ------------------------------------------------------------------ */

const user = computed(() => page.props.auth?.user ?? null);

const primaryRole = computed(() => user.value?.roles?.[0] ?? 'Member');

async function logout() {
    if (await confirmLogout()) {
        router.post(route('logout'));
    }
}
</script>

<template>
    <Head :title="title ?? undefined" />

    <div class="min-h-dvh bg-ink-50 text-ink-900">
        <!-- Navigation progress -->
        <div
            class="pointer-events-none fixed inset-x-0 top-0 z-[60] h-0.5"
            role="presentation"
            aria-hidden="true"
        >
            <div
                :class="[
                    'h-full bg-brand-600 transition-[width,opacity] duration-300 ease-[var(--ease-out-soft)]',
                    navigating ? 'w-3/4 opacity-100' : 'w-full opacity-0',
                ]"
            />
        </div>

        <!-- Mobile backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-200 ease-[var(--ease-out-soft)]"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-200 ease-[var(--ease-out-soft)]"
            leave-to-class="opacity-0"
        >
            <div
                v-if="mobileOpen"
                class="fixed inset-0 z-40 bg-ink-900/40 backdrop-blur-sm lg:hidden"
                @click="mobileOpen = false"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 flex flex-col border-r border-ink-200 bg-white',
                'transition-[width,transform] duration-300 ease-[var(--ease-out-soft)]',
                collapsed ? 'lg:w-[4.5rem]' : 'lg:w-64',
                'w-72 lg:translate-x-0',
                mobileOpen ? 'translate-x-0 shadow-float' : '-translate-x-full',
            ]"
            aria-label="Main navigation"
        >
            <!-- Brand -->
            <div
                :class="[
                    'flex h-16 shrink-0 items-center border-b border-ink-200',
                    collapsed ? 'lg:justify-center lg:px-0' : '',
                    'px-4',
                ]"
            >
                <Link
                    :href="navigation[0]?.href ?? '/'"
                    class="flex min-w-0 items-center gap-2.5 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                >
                    <!-- Light sidebar: the light-locked mark needs a navy plate. -->
                    <span
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-navy-900 shadow-card"
                    >
                        <img
                            src="/images/brand/logo-mark.png"
                            alt="The Paddle Room"
                            width="231"
                            height="178"
                            class="h-5 w-auto"
                        />
                    </span>
                    <span
                        :class="[
                            'min-w-0 leading-tight transition-opacity duration-200',
                            collapsed ? 'lg:hidden' : '',
                        ]"
                    >
                        <span class="block truncate text-sm font-semibold text-ink-900">
                            {{ page.props.appName ?? 'The Paddle Room' }}
                        </span>
                        <span class="block truncate text-[11px] text-ink-500">
                            Court bookings
                        </span>
                    </span>
                </Link>

                <button
                    type="button"
                    class="ml-auto inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 lg:hidden"
                    aria-label="Close navigation"
                    @click="mobileOpen = false"
                >
                    <X :size="18" aria-hidden="true" />
                </button>
            </div>

            <!-- Links -->
            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                <Link
                    v-for="item in navigation"
                    :key="item.label"
                    :href="item.href"
                    :aria-current="isActive(item) ? 'page' : undefined"
                    :title="collapsed ? item.label : undefined"
                    :class="[
                        'group/nav relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium',
                        'transition-colors duration-150 ease-[var(--ease-out-soft)]',
                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                        collapsed ? 'lg:justify-center lg:px-0' : '',
                        isActive(item)
                            ? 'bg-brand-50 text-brand-700'
                            : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900',
                    ]"
                >
                    <span
                        v-if="isActive(item)"
                        class="absolute inset-y-1.5 left-0 w-1 rounded-r-full bg-brand-600"
                        aria-hidden="true"
                    />
                    <component
                        :is="item.icon"
                        :size="18"
                        class="shrink-0"
                        :stroke-width="isActive(item) ? 2.25 : 1.9"
                        aria-hidden="true"
                    />
                    <span :class="['truncate', collapsed ? 'lg:hidden' : '']">
                        {{ item.label }}
                    </span>

                    <!-- Rail tooltip -->
                    <span
                        v-if="collapsed"
                        class="pointer-events-none absolute left-full z-50 ml-2 hidden whitespace-nowrap rounded-md bg-ink-900 px-2 py-1 text-[11px] font-medium text-white opacity-0 shadow-float transition-opacity duration-150 group-hover/nav:opacity-100 lg:block"
                    >
                        {{ item.label }}
                    </span>
                </Link>

                <div class="my-2 border-t border-ink-200" role="separator" aria-hidden="true" />

                <button
                    type="button"
                    :title="collapsed ? 'Sign out' : undefined"
                    :class="[
                        'group/nav relative flex w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium',
                        'text-danger-600 transition-colors duration-150 ease-[var(--ease-out-soft)] hover:bg-danger-50 hover:text-danger-700',
                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                        collapsed ? 'lg:justify-center lg:px-0' : '',
                    ]"
                    @click="logout"
                >
                    <LogOut :size="18" class="shrink-0" :stroke-width="1.9" aria-hidden="true" />
                    <span :class="['truncate', collapsed ? 'lg:hidden' : '']">Sign out</span>

                    <!-- Rail tooltip -->
                    <span
                        v-if="collapsed"
                        class="pointer-events-none absolute left-full z-50 ml-2 hidden whitespace-nowrap rounded-md bg-ink-900 px-2 py-1 text-[11px] font-medium text-white opacity-0 shadow-float transition-opacity duration-150 group-hover/nav:opacity-100 lg:block"
                    >
                        Sign out
                    </span>
                </button>
            </nav>

            <!-- Collapse toggle (desktop) -->
            <div class="hidden shrink-0 border-t border-ink-200 p-3 lg:block">
                <button
                    type="button"
                    :class="[
                        'flex w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-ink-500',
                        'transition-colors hover:bg-ink-100 hover:text-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                        collapsed ? 'justify-center px-0' : '',
                    ]"
                    :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    :aria-pressed="collapsed"
                    @click="collapsed = !collapsed"
                >
                    <PanelLeft
                        :size="18"
                        :class="['shrink-0 transition-transform duration-300', collapsed ? 'rotate-180' : '']"
                        aria-hidden="true"
                    />
                    <span :class="collapsed ? 'hidden' : ''">Collapse</span>
                </button>
            </div>
        </aside>

        <!-- Main column -->
        <div
            :class="[
                'flex min-h-dvh flex-col transition-[padding] duration-300 ease-[var(--ease-out-soft)]',
                collapsed ? 'lg:pl-[4.5rem]' : 'lg:pl-64',
            ]"
        >
            <!-- Topbar -->
            <header
                class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-ink-200 bg-white/85 px-4 backdrop-blur-md sm:px-6"
            >
                <button
                    type="button"
                    class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 lg:hidden"
                    aria-label="Open navigation"
                    @click="mobileOpen = true"
                >
                    <Menu :size="20" aria-hidden="true" />
                </button>

                <div class="min-w-0 flex-1">
                    <slot name="breadcrumbs">
                        <p v-if="title" class="truncate text-sm font-semibold text-ink-800">
                            {{ title }}
                        </p>
                    </slot>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <slot name="action" />

                    <span class="mx-1 hidden h-6 w-px bg-ink-200 sm:block" aria-hidden="true" />

                    <Dropdown align="right" width="w-60">
                        <template #trigger="{ toggle, open }">
                            <button
                                type="button"
                                :class="[
                                    'flex cursor-pointer items-center gap-2 rounded-lg py-1 pr-2 pl-1 transition-colors',
                                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                                    open ? 'bg-ink-100' : 'hover:bg-ink-100',
                                ]"
                                aria-haspopup="menu"
                                :aria-label="`Account menu for ${user?.name ?? 'user'}`"
                                @click="toggle"
                            >
                                <Avatar
                                    :name="user?.name"
                                    :initials="user?.initials"
                                    :src="user?.avatar_url"
                                    size="sm"
                                    tone="brand"
                                />
                                <span class="hidden min-w-0 text-left sm:block">
                                    <span class="block max-w-32 truncate text-xs font-semibold text-ink-800">
                                        {{ user?.name }}
                                    </span>
                                    <span class="block max-w-32 truncate text-[11px] text-ink-500 capitalize">
                                        {{ primaryRole }}
                                    </span>
                                </span>
                            </button>
                        </template>

                        <div class="border-b border-ink-200/70 px-2.5 pt-1 pb-2.5">
                            <p class="truncate text-sm font-semibold text-ink-900">
                                {{ user?.name }}
                            </p>
                            <p class="truncate text-xs text-ink-500">@{{ user?.username }}</p>
                        </div>

                        <div class="pt-1.5">
                            <DropdownItem :icon="User" :href="route('admin.profile')">
                                My profile
                            </DropdownItem>
                            <DropdownItem :icon="KeyRound" :href="route('password.change')">
                                Change password
                            </DropdownItem>
                            <DropdownItem :icon="LogOut" variant="danger" divided @click="logout">
                                Sign out
                            </DropdownItem>
                        </div>
                    </Dropdown>
                </div>
            </header>

            <!-- Page -->
            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <Transition
                    mode="out-in"
                    enter-active-class="transition duration-200 ease-[var(--ease-out-soft)]"
                    enter-from-class="opacity-0 translate-y-1.5"
                    leave-active-class="transition duration-100 ease-[var(--ease-out-soft)]"
                    leave-to-class="opacity-0"
                >
                    <div :key="page.component" class="mx-auto w-full max-w-[100rem]">
                        <slot />
                    </div>
                </Transition>
            </main>

            <footer class="shrink-0 px-4 pb-6 text-center text-xs text-ink-400 sm:px-6">
                {{ page.props.appName ?? 'The Paddle Room' }} · Pickleball court bookings
            </footer>
        </div>
    </div>
</template>
