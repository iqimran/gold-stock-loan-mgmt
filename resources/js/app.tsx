import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

// The shop name from Settings (shared `name` prop); follows changes on every visit.
let appName = import.meta.env.VITE_APP_NAME || 'Gold Stock & Loan Management';
const applyName = (name: unknown) => {
    if (typeof name === 'string' && name !== '') {
        appName = name;
    }
};
router.on('navigate', (event) => applyName(event.detail.page.props.name));

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        applyName(props.initialPage.props.name);
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    // Top loading bar for page visits slower than 250ms.
    progress: {
        delay: 250,
        color: '#4B5563',
        showSpinner: false,
    },
});

// This will set light / dark mode on load...
initializeTheme();
