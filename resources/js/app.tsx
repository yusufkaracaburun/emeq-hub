import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { type ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    // Geen title-template hier: de volledige titel (inclusief merk-suffix) komt
    // server-side uit App\Support\Seo\SeoMeta, zodat PHP de enige bron is.
    resolve: async (name) => {
        const page = await resolvePageComponent<{ default: ComponentType }>(
            `./pages/${name}.tsx`,
            import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx'),
        );

        return page.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#a23696',
    },
});
