import { MotionConfig } from 'framer-motion';
import { SimpleFooter } from '@/components/landing/footer';
import { Nav } from '@/components/landing/nav';
import { Reveal } from '@/components/motion';
import { Seo } from '@/components/seo';
import { Eyebrow } from '@/components/ui/eyebrow';
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
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <Nav />
            <main className="relative overflow-hidden px-page pb-24 pt-16 lg:pt-20">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 top-0 h-[360px] opacity-30 [background-image:radial-gradient(circle,#17171720_1px,transparent_1px)] [background-size:24px_24px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
                />

                <div className="relative flex max-w-[800px] flex-col gap-10">
                    <Reveal className="flex flex-col gap-4">
                        <Eyebrow>Documentatie · {title}</Eyebrow>
                        <h1 className="text-2xl font-bold leading-[1.05] tracking-[-1px] text-foreground md:text-display md:tracking-[-2px]">
                            {title}
                        </h1>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 font-mono text-xs2 text-muted-foreground">
                            <span>Laatst bijgewerkt: {updatedAt}</span>
                            <a href={apiReferenceUrl} target="_blank" rel="noreferrer" className="font-medium text-foreground underline underline-offset-4 hover:text-brand">
                                API-reference (OpenAPI) →
                            </a>
                        </div>
                    </Reveal>

                    <Reveal
                        delay={0.1}
                        className={
                            'prose-docs text-md leading-[1.6] text-muted-foreground ' +
                            '[&_a]:font-medium [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4 hover:[&_a]:text-brand ' +
                            '[&_blockquote]:my-4 [&_blockquote]:rounded-md [&_blockquote]:border [&_blockquote]:border-border [&_blockquote]:bg-card [&_blockquote]:px-6 [&_blockquote]:py-5 [&_blockquote]:font-mono [&_blockquote]:text-xs2 [&_blockquote_p]:mt-0 ' +
                            '[&_h2]:mt-10 [&_h2]:border-t [&_h2]:border-border [&_h2]:pt-6 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-foreground ' +
                            '[&_h2:first-child]:mt-0 [&_h2:first-child]:border-t-0 [&_h2:first-child]:pt-0 ' +
                            '[&_h3]:mt-6 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-foreground ' +
                            '[&_li]:mt-1.5 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:pl-6 ' +
                            '[&_p]:mt-3 [&_strong]:font-semibold [&_strong]:text-foreground ' +
                            '[&_pre]:my-4 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:border [&_pre]:border-border [&_pre]:bg-card [&_pre]:p-4 [&_pre]:text-xs2 [&_pre]:leading-[1.5] ' +
                            '[&_code]:font-mono [&_code]:text-[0.85em] [&_pre_code]:text-foreground ' +
                            '[&_:not(pre)>code]:rounded [&_:not(pre)>code]:border [&_:not(pre)>code]:border-border [&_:not(pre)>code]:bg-card [&_:not(pre)>code]:px-1.5 [&_:not(pre)>code]:py-0.5 [&_:not(pre)>code]:text-foreground ' +
                            '[&_table]:mt-5 [&_table]:w-full [&_table]:border-collapse [&_td]:border-b [&_td]:border-border [&_td]:py-2.5 [&_td]:pr-4 [&_td]:align-top ' +
                            '[&_th]:border-b [&_th]:py-2.5 [&_th]:pr-4 [&_th]:text-left [&_th]:font-mono [&_th]:text-2xs [&_th]:uppercase [&_th]:tracking-[1.5px] [&_th]:text-muted-foreground ' +
                            '[&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-6'
                        }
                        dangerouslySetInnerHTML={{ __html: html }}
                    />
                </div>
            </main>
            <SimpleFooter />
        </MotionConfig>
    );
}
