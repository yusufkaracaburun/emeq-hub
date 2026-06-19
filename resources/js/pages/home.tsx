import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Boxes, FileSpreadsheet, RefreshCw, ScrollText, ShieldCheck, Webhook } from 'lucide-react';
import { CtaSection } from '@/components/marketing/cta-section';
import { FeatureCard } from '@/components/marketing/feature-card';
import { FlowDiagram } from '@/components/marketing/flow-diagram';
import { HeroShell } from '@/components/marketing/hero';
import { LogoCloud } from '@/components/marketing/logo-cloud';
import { ProviderLogo } from '@/components/marketing/provider-logo';
import { Reveal } from '@/components/marketing/reveal';
import { SectionHeading } from '@/components/marketing/section-heading';
import { TrustBar } from '@/components/marketing/trust-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import ShowcaseLayout from '@/layouts/showcase-layout';
import type { ProviderSummary } from '@/types';

const FEATURES = [
    {
        icon: Boxes,
        title: 'Eén koppeling, alle partners',
        body: 'Bouw één keer tegen de Hub-API. Exact, Mollie en SnelStart lopen via hetzelfde pad — een nieuwe provider komt erbij zonder dat jij iets verandert.',
    },
    {
        icon: ShieldCheck,
        title: 'Veilig per ontwerp',
        body: 'Tokens versleuteld at rest, alleen fingerprints in logs, en een webhook-secret per Connection. Multi-tenant scheiding is hard afgedwongen.',
    },
    {
        icon: RefreshCw,
        title: 'OAuth & token-refresh geregeld',
        body: 'De Hub doet de OAuth-flow en ververst tokens automatisch vóór verloop. Jij krijgt nooit onverwacht een 401 terug.',
    },
    {
        icon: Webhook,
        title: 'Webhook-fanout',
        body: 'Inkomende partner-events worden geverifieerd, ge-audit en doorgezet naar jouw callback-URL met een eigen HMAC-secret.',
    },
    {
        icon: ScrollText,
        title: 'Audit-log per call',
        body: 'Elke pass-through en boeking landt als één onveranderlijke rij: methode, endpoint, status, duur. Incident-triage zonder giswerk.',
    },
    {
        icon: FileSpreadsheet,
        title: 'Provider-agnostische boekhoud-sync',
        body: 'POST één canonical document; de Hub buigt het naar het juiste boekhoud-endpoint. Jij kent geen Exact-veldnamen.',
    },
];

export default function Home({ providers }: { providers: ProviderSummary[] }) {
    return (
        <ShowcaseLayout>
            <Head title="Integratieplatform voor NL boekhoud- en betaal-API's" />

            <HeroShell>
                <div className="mx-auto max-w-3xl text-center">
                    <Badge variant="secondary" className="gap-1.5">
                        <Boxes className="size-3.5 text-amber-500" /> Integratieplatform
                    </Badge>
                    <h1 className="mt-5 text-4xl font-bold tracking-tight text-balance sm:text-5xl md:text-6xl">
                        Koppel je app aan <span className="text-brand-gradient">Nederlandse</span> boekhoud- en betaal-API's
                    </h1>
                    <p className="mx-auto mt-5 max-w-2xl text-lg text-muted-foreground">
                        Eén Hub regelt OAuth, multi-tenant token-opslag, webhook-routing en audit-logging. Jij bouwt tegen
                        één REST-API — Exact, Mollie en SnelStart erachter.
                    </p>
                    <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <Button asChild size="lg">
                            <Link href="/partners">
                                Koppel je app
                                <ArrowRight />
                            </Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <a href="/docs/api">API-docs</a>
                        </Button>
                    </div>
                    <TrustBar className="mt-10" />
                </div>
            </HeroShell>

            <section className="mx-auto max-w-6xl px-4 pb-8">
                <LogoCloud providers={providers} />
            </section>

            <section className="mx-auto max-w-6xl px-4 py-20">
                <Reveal>
                    <SectionHeading
                        align="center"
                        eyebrow="Hoe het werkt"
                        title="Consumer → Hub → Partner"
                        description="Je app praat met de Hub via een Bearer-token en een Account-id. De Hub kiest de juiste Connection, injecteert het token en stuurt door."
                        className="mb-10"
                    />
                </Reveal>
                <Reveal delay={0.1}>
                    <FlowDiagram />
                </Reveal>
            </section>

            <section className="mx-auto max-w-6xl px-4 py-20">
                <Reveal>
                    <SectionHeading
                        eyebrow="Wat de Hub voor je doet"
                        title="De integratie-plumbing, één keer goed gebouwd"
                        className="mb-10"
                    />
                </Reveal>
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {FEATURES.map((feature, i) => (
                        <Reveal key={feature.title} delay={i * 0.05}>
                            <FeatureCard icon={feature.icon} title={feature.title}>
                                {feature.body}
                            </FeatureCard>
                        </Reveal>
                    ))}
                </div>
            </section>

            <section className="mx-auto max-w-6xl px-4 py-12">
                <Reveal>
                    <SectionHeading eyebrow="Beschikbare integraties" title="Live op de Hub" className="mb-8" />
                </Reveal>
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {providers.map((provider, i) => (
                        <Reveal key={provider.key} delay={i * 0.05}>
                            <Link
                                href={`/partners/${provider.key}`}
                                className="group flex h-full flex-col rounded-2xl border bg-card p-6 transition-all hover:-translate-y-0.5 hover:border-amber-500/50 hover:shadow-lg hover:shadow-amber-500/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                            >
                                <div className="flex items-center justify-between">
                                    <ProviderLogo provider={provider} size="md" />
                                    <Badge variant="outline" className="text-xs">
                                        {provider.category}
                                    </Badge>
                                </div>
                                <h3 className={cn('mt-4 font-semibold', provider.logo && 'sr-only')}>{provider.label}</h3>
                                <p className={cn('text-sm text-muted-foreground', provider.logo ? 'mt-4' : 'mt-1')}>
                                    {provider.tagline}
                                </p>
                                <span className="mt-auto inline-flex items-center pt-4 text-sm font-medium text-amber-600 dark:text-amber-400">
                                    Bekijk integratie
                                    <ArrowRight className="ml-1 size-4 transition-transform group-hover:translate-x-1" />
                                </span>
                            </Link>
                        </Reveal>
                    ))}
                </div>
            </section>

            <section className="mx-auto max-w-6xl px-4 py-20">
                <CtaSection
                    title="Klaar om te koppelen?"
                    description="Vraag een Hub-koppeling aan en bouw vandaag nog tegen één API in plaats van drie."
                    primary={{ label: 'Koppel je app', href: '/partners' }}
                    secondary={{ label: 'API-docs', href: '/docs/api' }}
                />
            </section>
        </ShowcaseLayout>
    );
}
