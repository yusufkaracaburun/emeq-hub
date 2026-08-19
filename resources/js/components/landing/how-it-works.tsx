import { Fragment } from 'react';
import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Eyebrow } from '@/components/ui/eyebrow';
import { TextGlyph } from '@/components/ui/glyphs';

const steps = [
    {
        title: 'Maak je API-token aan',
        body: 'Vraag een API-token aan en je kunt bouwen. Meer configuratie heb je niet nodig.',
    },
    {
        title: 'Laat je klant veilig autoriseren',
        body: 'Je stuurt je klant één autorisatielink. Die logt in bij het eigen pakket; Emeq Hub bewaart de tokens versleuteld en ververst ze automatisch.',
    },
    {
        title: 'Bouw tegen één vaste API',
        body: 'Dezelfde endpoints en dezelfde foutcodes voor elke partner. Wat je voor de eerste koppeling bouwt, werkt ook voor de volgende.',
    },
];

export function HowItWorks() {
    return (
        <section id="hoe-het-werkt" className="bg-card px-page py-24 lg:py-section-x">
            <Reveal className="flex max-w-[760px] flex-col gap-4">
                <Eyebrow>02 · Zo werkt het</Eyebrow>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-3xl">
                    Je eerste koppeling draait in drie stappen.
                </h2>
            </Reveal>

            <RevealGroup className="mt-12 flex flex-col gap-10 lg:flex-row lg:items-start lg:gap-0">
                {steps.map((step, index) => (
                    <Fragment key={step.title}>
                        {index > 0 && (
                            <div aria-hidden className="hidden w-16 shrink-0 items-center justify-center self-start pt-[18px] lg:flex">
                                <TextGlyph glyph="›" className="text-2xl text-border" />
                            </div>
                        )}
                        <RevealItem className="flex flex-1 flex-col gap-4">
                            <div className="flex items-center gap-4">
                                <span aria-hidden className="text-display font-bold tracking-[-2px] text-border">
                                    {`0${index + 1}`}
                                </span>
                                <span className="font-mono text-xs uppercase tracking-[1.5px] text-muted-foreground">
                                    {`Stap 0${index + 1}`}
                                </span>
                            </div>
                            <h3 className="text-lg font-semibold text-foreground">{step.title}</h3>
                            <p className="text-md leading-[1.6] text-muted-foreground">{step.body}</p>
                        </RevealItem>
                    </Fragment>
                ))}
            </RevealGroup>
        </section>
    );
}
