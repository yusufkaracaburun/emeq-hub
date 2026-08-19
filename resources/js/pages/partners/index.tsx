import { Link } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';
import { useMemo, useState } from 'react';
import { Footer } from '@/components/landing/footer';
import { IntakeStepList, intakeSteps } from '@/components/landing/intake-steps';
import { KoppelForm } from '@/components/landing/koppel-form';
import { Nav } from '@/components/landing/nav';
import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Seo } from '@/components/seo';
import { Breadcrumbs } from '@/components/ui/breadcrumbs';
import { TextGlyph } from '@/components/ui/glyphs';
import { type ProviderSummary, type SeoMeta } from '@/lib/types';
import { cn } from '@/lib/utils';

interface PartnersIndexProps {
    providers: ProviderSummary[];
    seo: SeoMeta;
}

export default function PartnersIndex({ providers, seo }: PartnersIndexProps) {
    const categories = useMemo(
        () => ['Alle', ...Array.from(new Set(providers.map((p) => p.category)))],
        [providers],
    );
    const [active, setActive] = useState('Alle');

    const visible = providers.filter((p) => active === 'Alle' || p.category === active);

    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <Nav />
            <main className="relative overflow-hidden px-page pb-24 pt-10 lg:pt-12">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 top-0 h-[360px] opacity-30 [background-image:radial-gradient(circle,#17171720_1px,transparent_1px)] [background-size:24px_24px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
                />

                <div className="relative flex flex-col gap-16">
                    <div className="flex flex-col gap-6">
                        <Reveal>
                            <Breadcrumbs items={[{ label: 'Home', href: '/' }, { label: 'Integraties' }]} />
                        </Reveal>

                        <Reveal className="mx-auto flex max-w-[900px] flex-col items-center gap-4 text-center">
                            <h1 className="text-2xl font-bold leading-[1.05] tracking-[-1px] text-foreground md:text-display md:tracking-[-2px]">
                                Integraties
                            </h1>
                            <p className="max-w-[660px] text-lg leading-[1.6] text-muted-foreground">
                                Exact Online is live. Komt er een partner bij, dan gebruik je dezelfde API en verandert er niets
                                in je code.
                            </p>
                        </Reveal>
                    </div>

                    <Reveal delay={0.1} className="flex flex-wrap items-center justify-center gap-2">
                        {categories.map((category) => (
                            <button
                                key={category}
                                type="button"
                                onClick={() => setActive(category)}
                                className={cn(
                                    'rounded-pill px-4 py-2 text-sm font-medium transition-colors duration-150',
                                    active === category
                                        ? 'bg-primary text-background'
                                        : 'bg-secondary text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {category}
                            </button>
                        ))}
                    </Reveal>

                    <RevealGroup className="mx-auto grid w-full max-w-[1200px] gap-6 md:grid-cols-2">
                        {visible.map((provider) => (
                            <ProviderCard key={provider.key} provider={provider} />
                        ))}
                    </RevealGroup>

                    <Reveal id="koppelen" className="rounded-xl bg-brand-subtle p-6 md:p-12">
                        <div className="grid gap-10 lg:grid-cols-[1fr_520px] lg:gap-12">
                            <div className="flex flex-col gap-4">
                                <h2 className="text-xl font-bold leading-[1.2] tracking-[-0.5px] text-foreground">
                                    Klaar om te koppelen?
                                </h2>
                                <p className="text-base leading-[1.6] text-muted-foreground">
                                    Vertel kort wat je wilt koppelen. Wij regelen de omgeving, het token en de onboarding.
                                </p>
                                <div className="mt-2">
                                    <IntakeStepList steps={intakeSteps} />
                                </div>
                            </div>

                            <div className="h-fit rounded-lg border border-border bg-card px-8 py-9 shadow-card">
                                <KoppelForm providers={providers.map(({ key, label }) => ({ key, label }))} />
                            </div>
                        </div>
                    </Reveal>
                </div>
            </main>
            <Footer />
        </MotionConfig>
    );
}

function ProviderCard({ provider }: { provider: ProviderSummary }) {
    return (
        <RevealItem className="flex flex-col gap-3 rounded-lg border border-border bg-card p-6 shadow-btn">
            <div className="flex items-center justify-between gap-4">
                {provider.logo ? (
                    <img src={provider.logo} alt={provider.label} className="h-6" />
                ) : (
                    <span className="text-lg font-semibold text-foreground">{provider.label}</span>
                )}
                <span
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-pill px-2.5 py-1 font-mono text-2xs uppercase tracking-[1.5px]',
                        provider.live ? 'bg-success-soft text-success' : 'bg-muted text-muted-foreground',
                    )}
                >
                    {provider.live && <span aria-hidden className="size-1.5 rounded-pill bg-success" />}
                    {provider.live ? 'Live' : 'Binnenkort'}
                </span>
            </div>

            <p className="font-mono text-2xs uppercase tracking-[1.5px] text-muted-foreground">{provider.category}</p>
            <p className="text-sm leading-[1.6] text-foreground">{provider.summary}</p>

            {provider.live && (
                <Link
                    href={`/partners/${provider.key}`}
                    className="group mt-1 inline-flex items-center gap-1.5 text-sm font-semibold text-brand"
                >
                    Bekijk integratie
                    <TextGlyph glyph="→" className="text-[15px] transition-transform duration-150 group-hover:translate-x-0.5" />
                </Link>
            )}
        </RevealItem>
    );
}
