import '../css/app.css';
import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'The Paddle Room';

/*
| ApexCharts is deliberately NOT registered globally. It is a ~500kB dependency
| and only a handful of pages draw charts, so Components/ChartCard.vue pulls it
| in through defineAsyncComponent and Vite code-splits it into its own chunk.
| Registering the plugin here would drag it straight back into the entry bundle.
*/

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },

    progress: {
        color: '#4f46e5',
        showSpinner: false,
    },
});
