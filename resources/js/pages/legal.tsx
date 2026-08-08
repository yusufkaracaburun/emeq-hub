import { MotionConfig } from 'framer-motion';
import { Footer } from '@/components/landing/footer';
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

export default function Legal({ title, html, updatedAt, seo }: LegalProps) {
    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <Nav />
            <main className="px-6 pb-24 pt-16 lg:px-section-x">
                <Reveal className="mx-auto flex max-w-[760px] flex-col gap-4">
                    <Eyebrow>Juridisch</Eyebrow>
                    <h1 className="text-2xl font-bold tracking-[-1px] text-foreground md:text-3xl">{title}</h1>
                    <p className="font-mono text-xs text-muted-foreground">Laatst bijgewerkt: {updatedAt}</p>
                </Reveal>

                <Reveal
                    delay={0.1}
                    className={
                        'prose-legal mx-auto mt-10 max-w-[760px] text-md leading-[1.6] text-muted-foreground ' +
                        '[&_a]:font-medium [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4 hover:[&_a]:text-brand ' +
                        '[&_h2]:mt-10 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-foreground ' +
                        '[&_h3]:mt-7 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-foreground ' +
                        '[&_li]:mt-1.5 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:pl-5 ' +
                        '[&_p]:mt-3 [&_strong]:font-semibold [&_strong]:text-foreground ' +
                        '[&_table]:mt-5 [&_table]:w-full [&_table]:border-collapse [&_td]:border-b [&_td]:border-border [&_td]:py-2.5 [&_td]:pr-4 [&_td]:align-top ' +
                        '[&_th]:border-b [&_th]:border-border [&_th]:py-2.5 [&_th]:pr-4 [&_th]:text-left [&_th]:font-mono [&_th]:text-2xs [&_th]:uppercase [&_th]:tracking-[1.5px] [&_th]:text-muted-foreground ' +
                        '[&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-5'
                    }
                    dangerouslySetInnerHTML={{ __html: html }}
                />
            </main>
            <Footer />
        </MotionConfig>
    );
}
