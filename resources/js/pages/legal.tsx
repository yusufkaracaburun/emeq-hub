import { DocPage } from '@/components/docs/doc-page';
import { type SeoMeta } from '@/lib/types';

interface LegalProps {
    title: string;
    html: string;
    updatedAt: string;
    seo: SeoMeta;
}

export default function Legal({ title, html, updatedAt, seo }: LegalProps) {
    return <DocPage eyebrow="Juridisch" title={title} html={html} updatedAt={updatedAt} seo={seo} numbered />;
}
