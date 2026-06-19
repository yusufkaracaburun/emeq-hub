import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Plug } from 'lucide-react';
import { HeroShell } from '@/components/marketing/hero';
import { ProviderLogo } from '@/components/marketing/provider-logo';
import { Reveal } from '@/components/marketing/reveal';
import { TrustBar } from '@/components/marketing/trust-bar';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import ShowcaseLayout from '@/layouts/showcase-layout';
import type { ProviderSummary } from '@/types';

export default function PartnersIndex({ providers }: { providers: ProviderSummary[] }) {
    return (
        <ShowcaseLayout>
            <Head title="Integraties" />

            <HeroShell className="pb-0">
                <div className="mx-auto max-w-3xl text-center">
                    <Badge variant="secondary" className="gap-1.5">
                        <Plug className="size-3.5 text-amber-500" /> Integraties
                    </Badge>
                    <h1 className="mt-5 text-4xl font-bold tracking-tight text-balance sm:text-5xl">
                        Integraties die we <span className="text-brand-gradient">ondersteunen</span>
                    </h1>
                    <p className="mx-auto mt-4 max-w-2xl text-lg text-muted-foreground">
                        Koppel je app via één Hub aan Nederlandse boekhoud- en betaal-API's. Eén REST-API, dezelfde auth
                        en audit voor elke partner.
                    </p>
                    <TrustBar className="mt-10" />
                </div>
            </HeroShell>

            <section className="mx-auto max-w-6xl px-4 py-16 sm:py-20">
                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {providers.map((provider, i) => (
                        <Reveal key={provider.key} delay={i * 0.06}>
                            <Link
                                href={`/partners/${provider.key}`}
                                className="group flex h-full flex-col rounded-2xl border bg-card p-6 transition-all hover:-translate-y-0.5 hover:border-amber-500/50 hover:shadow-xl hover:shadow-amber-500/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                            >
                                <div className="flex items-center justify-between">
                                    <ProviderLogo provider={provider} size="md" />
                                    <Badge variant="outline">{provider.category}</Badge>
                                </div>
                                <h2 className={cn('mt-5 text-lg font-semibold', provider.logo && 'sr-only')}>
                                    {provider.label}
                                </h2>
                                <p className={cn('text-sm text-muted-foreground', provider.logo && 'mt-5')}>
                                    {provider.tagline}
                                </p>
                                <p className="mt-3 flex-1 text-sm text-muted-foreground">{provider.summary}</p>
                                <span className="mt-5 inline-flex items-center text-sm font-medium text-amber-600 dark:text-amber-400">
                                    Bekijk integratie
                                    <ArrowRight className="ml-1 size-4 transition-transform group-hover:translate-x-1" />
                                </span>
                            </Link>
                        </Reveal>
                    ))}
                </div>
            </section>
        </ShowcaseLayout>
    );
}
