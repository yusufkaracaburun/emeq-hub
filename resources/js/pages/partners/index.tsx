import { Link } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';
import { ArrowRight, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { SimpleFooter } from '@/components/landing/footer';
import { Nav } from '@/components/landing/nav';
import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Seo } from '@/components/seo';
import { buttonVariants } from '@/components/ui/button';
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
    const [query, setQuery] = useState('');

    const visible = providers
        .filter((p) => active === 'Alle' || p.category === active)
        .filter((p) => p.label.toLowerCase().includes(query.trim().toLowerCase()));

    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <Nav />
            <main className="relative overflow-hidden px-6 pb-24 pt-16 lg:px-section-x lg:pt-20">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 top-0 h-[360px] opacity-30 [background-image:radial-gradient(circle,#17171720_1px,transparent_1px)] [background-size:24px_24px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
                />

                <div className="relative flex flex-col gap-16">
                    <Reveal className="mx-auto flex max-w-[900px] flex-col items-center gap-4 text-center">
                        <Eyebrow>Partner-integraties</Eyebrow>
                        <h1 className="text-2xl font-bold leading-[1.05] tracking-[-1px] text-foreground md:text-display md:tracking-[-2px]">
                            Eén API voor al je productintegraties.
                        </h1>
                        <p className="max-w-[660px] text-lg leading-[1.6] text-muted-foreground">
                            emeq is de integratiehub achter je product. Start vandaag met Exact Online en voeg nieuwe
                            systemen toe zonder telkens je architectuur te verbouwen.
                        </p>
                    </Reveal>

                    <Reveal delay={0.1} className="flex flex-col items-center gap-4">
                        <label className="flex w-full max-w-[460px] items-center gap-2 rounded-pill bg-card px-[18px] py-[13px] shadow-btn">
                            <Search aria-hidden className="size-[18px] shrink-0 text-muted-foreground" />
                            <input
                                type="search"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Zoek een koppeling…"
                                className="w-full bg-transparent text-md text-foreground placeholder:text-muted-foreground focus:outline-none"
                            />
                        </label>
                        <div className="flex flex-wrap items-center justify-center gap-2">
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
                        </div>
                    </Reveal>

                    <RevealGroup className="mx-auto grid w-full max-w-[1144px] gap-6 md:grid-cols-2">
                        {visible.map((provider) => (
                            <ProviderCard key={provider.key} provider={provider} />
                        ))}
                    </RevealGroup>

                    <Reveal className="flex flex-col items-center gap-6 text-center">
                        <h2 className="text-2xl font-bold tracking-[-1px] text-foreground">Ontdek Exact Online</h2>
                        <Link href="/koppelen" className={cn(buttonVariants({ variant: 'primary', size: 'md' }), 'group')}>
                            Start met koppelen
                            <ArrowRight aria-hidden className="size-4 transition-transform duration-150 group-hover:translate-x-0.5" />
                        </Link>
                    </Reveal>
                </div>
            </main>
            <SimpleFooter />
        </MotionConfig>
    );
}

function ProviderCard({ provider }: { provider: ProviderSummary }) {
    return (
        <RevealItem className="flex flex-col gap-3 rounded-lg bg-card p-6 shadow-card">
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
