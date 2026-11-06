import '../css/app.css';
import './bootstrap';

import { applyTheme, Theme } from '@/theme';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const themeOf = (page: { props: Record<string, unknown> }) =>
            (page.props.auth as { user?: { theme?: Theme } } | undefined)?.user?.theme;

        applyTheme(themeOf(props.initialPage));
        router.on('success', (event) => applyTheme(themeOf(event.detail.page)));

        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
