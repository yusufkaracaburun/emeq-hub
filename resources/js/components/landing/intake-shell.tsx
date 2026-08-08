import { type ReactNode } from 'react';
import { SimpleFooter } from '@/components/landing/footer';
import { Nav } from '@/components/landing/nav';
import { Reveal } from '@/components/motion';
import { Eyebrow } from '@/components/ui/eyebrow';
import { cn } from '@/lib/utils';

interface IntakeStep {
    title: string;
    description: string;
}

interface IntakeShellProps {
    eyebrow: string;
    title: ReactNode;
    intro: string;
    steps: IntakeStep[];
    /** Inhoud van de form-card rechts. */
    children: ReactNode;
}

/**
 * Gedeelde shell voor de intake-pagina's (/koppelen en /demo): pitch links met
 * genummerde stappen en trust-regel, form-card rechts. Volgt de "Pagina · Start
 * met koppelen"- en "Pagina · Demo aanvragen"-frames uit landingspage.pen.
 */
export function IntakeShell({ eyebrow, title, intro, steps, children }: IntakeShellProps) {
    return (
        <>
            <Nav />
            <main className="relative overflow-hidden px-6 pb-24 pt-16 lg:px-section-x lg:pb-[112px] lg:pt-[88px]">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 top-0 h-[360px] opacity-30 [background-image:radial-gradient(circle,#17171720_1px,transparent_1px)] [background-size:24px_24px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
                />

                <div className="relative grid gap-12 lg:grid-cols-[1fr_520px] lg:gap-16">
                    <Reveal className="flex flex-col gap-6">
                        <Eyebrow>{eyebrow}</Eyebrow>
                        <h1 className="text-2xl font-bold leading-[1.05] tracking-[-1px] text-foreground md:text-display md:tracking-[-2px]">
                            {title}
                        </h1>
                        <p className="text-lg leading-[1.6] text-muted-foreground">{intro}</p>

                        <ol className="mt-2 flex flex-col">
                            {steps.map((step, index) => (
                                <li
                                    key={step.title}
                                    className={cn(
                                        'flex items-start gap-4 border-b border-border py-[18px]',
                                        index === 0 && 'border-t',
                                    )}
                                >
                                    <span className="font-mono text-xs2 text-brand">{`0${index + 1}`}</span>
                                    <div className="flex flex-col gap-1">
                                        <p className="text-base font-semibold text-foreground">{step.title}</p>
                                        <p className="text-sm leading-[1.6] text-muted-foreground">{step.description}</p>
                                    </div>
                                </li>
                            ))}
                        </ol>

                        <div className="flex items-center gap-2">
                            <span className="flex items-center justify-center rounded-xs border border-border p-1.5">
                                <img src="/img/badges/gdpr.png" alt="GDPR" className="size-[18px]" />
                            </span>
                            <span className="rounded-xs border border-border px-2.5 py-[5px] font-mono text-2xs tracking-[1.5px] text-muted-foreground">
                                Tokens encrypted at rest
                            </span>
                        </div>
                    </Reveal>

                    <Reveal delay={0.1} className="h-fit rounded-lg border border-border bg-card px-8 py-9 shadow-card">
                        {children}
                    </Reveal>
                </div>
            </main>
            <SimpleFooter />
        </>
    );
}
