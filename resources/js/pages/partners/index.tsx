import { Link } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';
import { ArrowRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Footer } from '@/components/landing/footer';
import { Nav } from '@/components/landing/nav';
import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Seo } from '@/components/seo';
import { Eyebrow } from '@/components/ui/eyebrow';
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

    const visible = active === 'Alle' ? providers : providers.filter((p) => p.category === active);

    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <Nav />
            <main>
                <section className="px-6 pb-24 pt-16 lg:px-section-x">
                    <Reveal className="mx-auto flex max-w-[760px] flex-col items-center gap-5 text-center">
                        <Eyebrow>Ons ecosysteem</Eyebrow>
                        <h1 className="text-2xl font-bold tracking-[-1px] text-foreground md:text-3xl">
                            Eén API voor al je productintegraties.
                        </h1>
                        <p className="max-w-[620px] text-base leading-[1.6] text-muted-foreground">
                            emeq is de integratielaag achter je product. Start vandaag met Exact Online en voeg nieuwe
                            systemen toe zonder iedere integratie-architectuur te verzwaren.
                        </p>
                    </Reveal>

                    <Reveal delay={0.1} className="mt-10 flex flex-wrap items-center justify-center gap-2.5">
                        {categories.map((category) => (
                            <button
                                key={category}
                                type="button"
                                onClick={() => setActive(category)}
                                className={cn(
                                    'rounded-pill border px-4 py-2 text-sm font-medium transition-colors duration-150',
                                    active === category
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border bg-card text-muted-foreground hover:border-brand hover:text-foreground',
                                )}
                            >
                                {category}
                            </button>
                        ))}
                    </Reveal>

                    <RevealGroup className="mx-auto mt-12 grid max-w-[1160px] gap-6 md:grid-cols-2">
                        {visible.map((provider) => (
                            <ProviderCard key={provider.key} provider={provider} />
                        ))}
                    </RevealGroup>
                </section>
            </main>
            <Footer />
        </MotionConfig>
    );
}

function ProviderCard({ provider }: { provider: ProviderSummary }) {
    return (
        <RevealItem className="flex flex-col gap-3 rounded-lg border border-border bg-card p-6 transition-colors duration-150 hover:border-brand">
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

            {provider.live ? (
                <Link
                    href={`/partners/${provider.key}`}
                    className="group mt-1 inline-flex items-center gap-1.5 text-sm font-semibold text-brand"
                >
                    Bekijk integratie
                    <ArrowRight aria-hidden className="size-[15px] transition-transform duration-150 group-hover:translate-x-0.5" />
                </Link>
            ) : (
                <p className="mt-1 text-sm font-medium text-muted-foreground">{provider.tagline}</p>
            )}
        </RevealItem>
    );
}
