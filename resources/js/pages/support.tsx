import { MotionConfig } from 'framer-motion';
import { SimpleFooter } from '@/components/landing/footer';
import { Nav } from '@/components/landing/nav';
import { Reveal } from '@/components/motion';
import { Seo } from '@/components/seo';
import { Eyebrow } from '@/components/ui/eyebrow';
import { type SeoMeta } from '@/lib/types';

interface SupportProps {
    /** Server-side, gedeeld met de FAQPage-structured-data (SupportController). */
    faq: { question: string; answer: string }[];
    seo: SeoMeta;
}

export default function Support({ faq, seo }: SupportProps) {
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
                        <Eyebrow>Support</Eyebrow>
                        <h1 className="text-2xl font-bold leading-[1.05] tracking-[-1px] text-foreground md:text-display md:tracking-[-2px]">
                            Support die je integraties vooruithelpt.
                        </h1>
                        <p className="text-base leading-[1.6] text-muted-foreground">
                            Hulp nodig bij een koppeling, token of technische keuze? Ons team reageert binnen één werkdag met
                            een helder antwoord.
                        </p>
                    </Reveal>

                    <Reveal className="flex flex-col gap-2 rounded-lg border border-border bg-card px-[26px] py-7">
                        <h2 className="text-lg font-semibold text-foreground">E-mail</h2>
                        <a
                            href="mailto:support@emeq.nl"
                            className="w-fit font-mono text-md text-brand transition-colors duration-150 hover:text-brand-deep"
                        >
                            support@emeq.nl
                        </a>
                        <p className="text-sm leading-[1.6] text-muted-foreground">
                            Voor technische vragen, onboarding en administratieve ondersteuning.
                        </p>
                        <p className="font-mono text-2xs uppercase tracking-[1.5px] text-muted-foreground">
                            Reactie &lt; 1 werkdag
                        </p>
                    </Reveal>

                    <Reveal className="flex flex-col gap-6">
                        <h2 className="text-2xl font-bold tracking-[-1px] text-foreground">Veelgestelde vragen</h2>
                        <div className="flex flex-col">
                            {faq.map((item, index) => (
                                <div
                                    key={item.question}
                                    className={
                                        index === 0
                                            ? 'flex flex-col gap-2 border-y border-border py-5'
                                            : 'flex flex-col gap-2 border-b border-border py-5'
                                    }
                                >
                                    <h3 className="text-base font-semibold text-foreground">{item.question}</h3>
                                    <p className="text-md leading-[1.6] text-muted-foreground">{item.answer}</p>
                                </div>
                            ))}
                        </div>
                    </Reveal>
                </div>
            </main>
            <SimpleFooter />
        </MotionConfig>
    );
}
