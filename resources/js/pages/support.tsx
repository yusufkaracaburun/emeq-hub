import { MotionConfig } from 'framer-motion';
import { Mail } from 'lucide-react';
import { Footer } from '@/components/landing/footer';
import { Nav } from '@/components/landing/nav';
import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
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
            <main className="px-6 pb-24 pt-16 lg:px-section-x">
                <Reveal className="mx-auto flex max-w-[760px] flex-col gap-4">
                    <Eyebrow>Support</Eyebrow>
                    <h1 className="text-2xl font-bold tracking-[-1px] text-foreground md:text-3xl">
                        Support die je integraties vooruithelpt.
                    </h1>
                    <p className="max-w-[620px] text-base leading-[1.6] text-muted-foreground">
                        Hulp nodig bij een koppeling, token of technische keuze? Ons team reageert binnen één werkdag met
                        een helder antwoord.
                    </p>
                </Reveal>

                <RevealGroup className="mx-auto mt-12 grid max-w-[760px] gap-6 md:grid-cols-2">
                    <RevealItem className="flex flex-col gap-3 rounded-lg border border-border bg-card p-6">
                        <div className="flex items-center gap-2.5">
                            <Mail aria-hidden className="size-[18px] text-muted-foreground" />
                            <h2 className="text-base font-semibold text-foreground">E-mail</h2>
                        </div>
                        <a
                            href="mailto:support@emeq.nl"
                            className="w-fit font-mono text-xs2 text-foreground underline underline-offset-4 transition-colors duration-150 hover:text-brand"
                        >
                            support@emeq.nl
                        </a>
                        <p className="text-sm leading-[1.6] text-muted-foreground">
                            Voor technische vragen, onboarding en administratieve ondersteuning.
                        </p>
                        <p className="font-mono text-2xs uppercase tracking-[1.5px] text-muted-foreground">
                            Reactie &lt; 1 werkdag
                        </p>
                    </RevealItem>
                </RevealGroup>

                <Reveal className="mx-auto mt-16 flex max-w-[760px] flex-col gap-2">
                    <h2 className="text-xl font-bold tracking-[-1px] text-foreground">Veelgestelde vragen</h2>
                    <div className="mt-4 flex flex-col">
                        {faq.map((item) => (
                            <div key={item.question} className="flex flex-col gap-2 border-t border-border py-5">
                                <h3 className="text-base font-semibold text-foreground">{item.question}</h3>
                                <p className="text-sm leading-[1.6] text-muted-foreground">{item.answer}</p>
                            </div>
                        ))}
                    </div>
                </Reveal>
            </main>
            <Footer />
        </MotionConfig>
    );
}
