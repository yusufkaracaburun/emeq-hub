import { Head } from '@inertiajs/react';
import { type SeoMeta } from '@/lib/types';

/**
 * Rendert de server-side opgebouwde SEO-payload. De inhoud komt volledig uit
 * PHP (App\Support\Seo\SeoMeta) — hier gebeurt alleen formattering, zodat
 * titel, meta en structured data één bron houden.
 *
 * De JSON-LD staat bewust buiten <Head>: Inertia serialiseert Head-children
 * zelf en gaat niet betrouwbaar om met scriptinhoud. Crawlers accepteren
 * JSON-LD overal in het document, dus body is prima.
 */
export function Seo({ seo }: { seo: SeoMeta }) {
    return (
        <>
            <Head title={seo.title}>
                <meta head-key="description" name="description" content={seo.description} />
                <link head-key="canonical" rel="canonical" href={seo.canonical} />

                <meta head-key="og:type" property="og:type" content={seo.type} />
                <meta head-key="og:title" property="og:title" content={seo.title} />
                <meta head-key="og:description" property="og:description" content={seo.description} />
                <meta head-key="og:url" property="og:url" content={seo.canonical} />
                <meta head-key="og:image" property="og:image" content={seo.image} />
                <meta head-key="og:site_name" property="og:site_name" content={seo.siteName} />
                <meta head-key="og:locale" property="og:locale" content={seo.locale} />

                <meta head-key="tw:card" name="twitter:card" content="summary_large_image" />
                <meta head-key="tw:title" name="twitter:title" content={seo.title} />
                <meta head-key="tw:description" name="twitter:description" content={seo.description} />
                <meta head-key="tw:image" name="twitter:image" content={seo.image} />
            </Head>

            <script
                type="application/ld+json"
                // `<` escapen zodat een `</script>` in de data het blok niet kan sluiten.
                dangerouslySetInnerHTML={{ __html: JSON.stringify(seo.jsonLd).replace(/</g, '\\u003c') }}
            />
        </>
    );
}
