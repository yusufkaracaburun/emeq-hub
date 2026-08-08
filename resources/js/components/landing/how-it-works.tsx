import { ChevronRight } from 'lucide-react';
import { Fragment } from 'react';
import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Eyebrow } from '@/components/ui/eyebrow';

const steps = [
    {
        title: 'Maak je API-token aan',
        body: 'Je start met één token. Geen complexe configuratie, wel direct een veilige basis.',
    },
    {
        title: 'Laat je klant veilig autoriseren',
        body: 'De gebruiker rondt OAuth af; emeq slaat tokens veilig op en houdt ze automatisch actueel.',
    },
    {
        title: 'Bouw op één betrouwbare API',
        body: 'Gebruik dezelfde endpoint-structuur voor elke partner. Snel ontwikkelen, veilig schalen.',
    },
];

export function HowItWorks() {
    return (
        <section id="hoe-het-werkt" className="bg-card px-6 py-24 lg:px-section-x lg:py-section-x">
            <Reveal className="flex max-w-[760px] flex-col gap-4">
                <Eyebrow>02 — Zo werkt het</Eyebrow>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-3xl">
                    Van idee naar live integratie in drie stappen.
                </h2>
            </Reveal>

            <RevealGroup className="mt-12 flex flex-col gap-10 lg:flex-row lg:items-start lg:gap-0">
                {steps.map((step, index) => (
                    <Fragment key={step.title}>
                        {index > 0 && (
                            <div aria-hidden className="hidden w-16 shrink-0 items-center justify-center self-start pt-[18px] lg:flex">
                                <ChevronRight className="size-6 text-border" />
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
