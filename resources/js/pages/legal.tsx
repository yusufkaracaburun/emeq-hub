import { MotionConfig } from 'framer-motion';
import { SimpleFooter } from '@/components/landing/footer';
import { Nav } from '@/components/landing/nav';
import { Reveal } from '@/components/motion';
import { Seo } from '@/components/seo';
import { Eyebrow } from '@/components/ui/eyebrow';
import { type SeoMeta } from '@/lib/types';

interface LegalProps {
    title: string;
    html: string;
    updatedAt: string;
    seo: SeoMeta;
}

/**
 * Juridische pagina's volgens de "Pagina · Privacy/Voorwaarden/Verwerkers-
 * overeenkomst"-frames: kolom van 800px, genummerde secties met brand-index in
 * de linkermarge (CSS-counter op h2) en dividers tussen de secties. Een
 * blockquote bovenin rendert als de mono info-box uit het design.
 */
export default function Legal({ title, html, updatedAt, seo }: LegalProps) {
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
                        <Eyebrow>Juridisch — {title}</Eyebrow>
                        <h1 className="text-2xl font-bold leading-[1.05] tracking-[-1px] text-foreground md:text-display md:tracking-[-2px]">
                            {title}
                        </h1>
                        <p className="font-mono text-xs2 text-muted-foreground">Laatst bijgewerkt: {updatedAt}</p>
                    </Reveal>

                    <Reveal
                        delay={0.1}
                        className={
                            'prose-legal text-md leading-[1.6] text-muted-foreground [counter-reset:legal] ' +
                            '[&_a]:font-medium [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4 hover:[&_a]:text-brand ' +
                            '[&_blockquote]:rounded-md [&_blockquote]:border [&_blockquote]:border-border [&_blockquote]:bg-card [&_blockquote]:px-6 [&_blockquote]:py-5 [&_blockquote]:font-mono [&_blockquote]:text-xs2 [&_blockquote_p]:mt-0 [&_blockquote_p]:pl-0 ' +
                            '[&_h2]:relative [&_h2]:mt-6 [&_h2]:border-t [&_h2]:border-border [&_h2]:pl-10 [&_h2]:pt-6 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-foreground [&_h2]:[counter-increment:legal] ' +
                            '[&_h2:first-child]:mt-0 [&_h2:first-child]:border-t-0 [&_h2:first-child]:pt-0 ' +
                            "[&_h2]:before:absolute [&_h2]:before:left-0 [&_h2]:before:font-mono [&_h2]:before:text-xs2 [&_h2]:before:font-normal [&_h2]:before:text-brand [&_h2]:before:content-[counter(legal,decimal-leading-zero)] " +
                            '[&_h3]:mt-6 [&_h3]:pl-10 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-foreground ' +
                            '[&_li]:mt-1.5 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:pl-[60px] ' +
                            '[&_p]:mt-3 [&_p]:pl-10 [&_strong]:font-semibold [&_strong]:text-foreground ' +
                            '[&_table]:ml-10 [&_table]:mt-5 [&_table]:w-[calc(100%-40px)] [&_table]:border-collapse [&_td]:border-b [&_td]:border-border [&_td]:py-2.5 [&_td]:pr-4 [&_td]:align-top ' +
                            '[&_th]:border-b [&_th]:border-border [&_th]:py-2.5 [&_th]:pr-4 [&_th]:text-left [&_th]:font-mono [&_th]:text-2xs [&_th]:uppercase [&_th]:tracking-[1.5px] [&_th]:text-muted-foreground ' +
                            '[&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-[60px]'
                        }
                        dangerouslySetInnerHTML={{ __html: html }}
                    />
                </div>
            </main>
            <SimpleFooter />
        </MotionConfig>
    );
}
