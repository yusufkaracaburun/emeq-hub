import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { type ComponentType } from 'react';
import ReactDOMServer from 'react-dom/server';

/**
 * SSR-entry. Zonder deze laag serveren de publieke pagina's een lege body:
 * crawlers die geen JavaScript uitvoeren (GPTBot, ClaudeBot, PerplexityBot,
 * link-unfurlers) zien dan niets van de content.
 *
 * `eager: true` — de SSR-bundel draait in één proces zonder code-splitting;
 * dynamische imports zouden per request een module-resolve kosten.
 */
createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        resolve: (name) => {
            const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx', { eager: true });

            return pages[`./pages/${name}.tsx`];
        },
        setup: ({ App, props }) => <App {...props} />,
    }),
);
