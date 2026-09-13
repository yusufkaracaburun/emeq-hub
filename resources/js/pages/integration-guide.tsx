import { DocPage } from '@/components/docs/doc-page';
import { type SeoMeta } from '@/lib/types';

interface IntegrationGuideProps {
    title: string;
    html: string;
    updatedAt: string;
    apiReferenceUrl: string;
    seo: SeoMeta;
}

export default function IntegrationGuide({ title, html, updatedAt, apiReferenceUrl, seo }: IntegrationGuideProps) {
    return (
        <DocPage
            eyebrow="Documentatie"
            title={title}
            html={html}
            updatedAt={updatedAt}
            seo={seo}
            headerAction={{ label: 'API-reference (OpenAPI)', href: apiReferenceUrl }}
            toc
        />
    );
}
