import { MotionConfig } from 'framer-motion';
import { type ReactNode } from 'react';
import { SimpleFooter } from '@/components/landing/footer';
import { IntakeStepList, intakeSteps } from '@/components/landing/intake-steps';
import { KoppelForm } from '@/components/landing/koppel-form';
import { Nav } from '@/components/landing/nav';
import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Seo } from '@/components/seo';
import { Breadcrumbs } from '@/components/ui/breadcrumbs';
import { buttonVariants } from '@/components/ui/button';
import { Eyebrow } from '@/components/ui/eyebrow';
import { FeatureCard } from '@/components/ui/feature-card';
import {
    BroadcastGlyph,
    DocGlyph,
    DocStampGlyph,
    GridGlyph,
    PctGlyph,
    TextGlyph,
    UsersGlyph,
} from '@/components/ui/glyphs';
import { type ProviderDetail, type ProviderSummary, type SeoMeta } from '@/lib/types';
import { cn } from '@/lib/utils';

interface PartnersShowProps {
    provider: ProviderDetail;
    providers: ProviderSummary[];
    seo: SeoMeta;
}

/** Bespoke glyphs die de showcase-config (features[].icon) mag noemen. */
const featureGlyphs: Record<string, ReactNode> = {
    'file-text': <DocGlyph />,
    receipt: <DocStampGlyph />,
    users: <UsersGlyph />,
    'book-open': <GridGlyph />,
    percent: <PctGlyph />,
    webhook: <BroadcastGlyph className="size-[18px]" dotClassName="text-brand" />,
};

export default function PartnersShow({ provider, providers, seo }: PartnersShowProps) {
    const features = provider.features ?? [];
    const steps = provider.steps ?? [];

    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <Nav />
            <main className="relative overflow-hidden px-page pb-24 pt-16 lg:pt-20">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 top-0 h-[360px] opacity-30 [background-image:radial-gradient(circle,#17171720_1px,transparent_1px)] [background-size:24px_24px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
                />

                <div className="relative flex flex-col gap-16">
                    <Reveal>
                        <Breadcrumbs
                            items={[
                                { label: 'Home', href: '/' },
                                { label: 'Integraties', href: '/partners' },
                                { label: provider.label },
                            ]}
                        />
                    </Reveal>
                    <ProviderHero provider={provider} />
                    {features.length > 0 && <Features provider={provider} features={features} />}
                    {steps.length > 0 && <HowItWorks provider={provider} steps={steps} />}
                    <ConnectSection provider={provider} providers={providers} />
                </div>
            </main>
            <SimpleFooter />
        </MotionConfig>
    );
}

function ProviderHero({ provider }: { provider: ProviderDetail }) {
    return (
        <Reveal className="mx-auto flex max-w-[840px] flex-col items-center gap-6 text-center">
            <Eyebrow>Integratie · {provider.category}</Eyebrow>

            <div className="flex items-center gap-4">
                {provider.logo && <img src={provider.logo} alt={provider.label} className="h-11" />}
                <span
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-pill px-3 py-[5px] font-mono text-2xs font-bold uppercase tracking-[1.5px]',
                        provider.live ? 'bg-success-soft text-success' : 'bg-muted text-muted-foreground',
                    )}
                >
                    {provider.live && <span aria-hidden className="size-1.5 rounded-pill bg-success" />}
                    {provider.live ? 'Live' : 'Binnenkort'}
                </span>
            </div>

            <h1 className="text-2xl font-bold leading-[1.05] tracking-[-1px] text-foreground md:text-display md:tracking-[-2px]">
                {provider.headline ?? `${provider.label} koppelen via één API`}
            </h1>
            <p className="max-w-[660px] text-lg leading-[1.6] text-muted-foreground">
                {provider.intro ?? provider.summary}
            </p>

            <div className="flex w-full flex-col gap-4 sm:w-auto sm:flex-row sm:items-center">
                <a href="#koppelen" className={cn(buttonVariants({ variant: 'primary', size: 'md' }), 'group')}>
                    Start met {provider.label}
                    <TextGlyph glyph="→" className="transition-transform duration-150 group-hover:translate-x-0.5" />
                </a>
                {provider.docs_url && (
                    <a
                        href={provider.docs_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className={buttonVariants({ variant: 'outline', size: 'md' })}
                    >
                        Bekijk de docs
                    </a>
                )}
            </div>

            <ResourceLinks provider={provider} />
        </Reveal>
    );
}

/** Officiële partner-links — config-gedreven (website_url / docs_url / support_url). */
function ResourceLinks({ provider }: { provider: ProviderDetail }) {
    const resources = [
        { label: provider.website_url ? new URL(provider.website_url).hostname.replace(/^www\./, '') : '', href: provider.website_url },
        { label: 'Developer-docs', href: provider.docs_url },
        { label: `${provider.label.split(' ')[0]}-support`, href: provider.support_url },
    ].filter((r): r is { label: string; href: string } => Boolean(r.href && r.label));

    if (resources.length === 0) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-col items-center gap-3 sm:flex-row sm:gap-6">
            {resources.map((resource) => (
                <a
                    key={resource.label}
                    href={resource.href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center gap-1.5 font-mono text-xs2 text-muted-foreground transition-colors duration-150 hover:text-foreground"
                >
                    <TextGlyph glyph="↗" />
                    {resource.label}
                </a>
            ))}
        </div>
    );
}

function Features({
    provider,
    features,
}: {
    provider: ProviderDetail;
    features: { icon: string; tag?: string; title: string; description: string }[];
}) {
    return (
        <section className="flex flex-col gap-8">
            <Reveal className="mx-auto flex max-w-[760px] flex-col items-center gap-3 text-center">
                <Eyebrow>Mogelijkheden</Eyebrow>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-2xl">
                    Wat je met {provider.label} automatiseert
                </h2>
            </Reveal>

            <RevealGroup className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                {features.map((feature) => (
                    <RevealItem key={feature.title}>
                        <FeatureCard
                            glyph={featureGlyphs[feature.icon] ?? <GridGlyph />}
                            tag={feature.tag}
                            title={feature.title}
                            className="h-full"
                        >
                            {feature.description}
                        </FeatureCard>
                    </RevealItem>
                ))}
            </RevealGroup>
        </section>
    );
}

function HowItWorks({
    provider,
    steps,
}: {
    provider: ProviderDetail;
    steps: { title: string; description: string }[];
}) {
    return (
        <section id="zo-werkt-het" className="flex flex-col gap-8">
            <Reveal className="mx-auto flex max-w-[760px] flex-col items-center gap-3 text-center">
                <Eyebrow>Zo werkt het</Eyebrow>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-2xl">
                    Live met {provider.label} in drie stappen
                </h2>
            </Reveal>

            <RevealGroup className="grid gap-10 lg:grid-cols-3 lg:gap-6">
                {steps.map((step, index) => (
                    <RevealItem key={index} className="flex flex-col gap-4">
                        <span
                            aria-hidden
                            className="flex size-11 items-center justify-center rounded-pill border border-border bg-card text-lg font-bold tracking-[-0.5px] text-muted-foreground"
                        >
                            {index + 1}
                        </span>
                        {step.title && <h3 className="text-lg font-semibold text-foreground">{step.title}</h3>}
                        <p className="text-sm leading-[1.6] text-muted-foreground">{step.description}</p>
                    </RevealItem>
                ))}
            </RevealGroup>
        </section>
    );
}

/** Koppelen-sectie: uitleg + intake-stappen links, het /koppelen-formulier rechts met deze partner voorgeselecteerd. */
function ConnectSection({ provider, providers }: { provider: ProviderDetail; providers: ProviderSummary[] }) {
    return (
        <Reveal id="koppelen" className="rounded-xl bg-brand-subtle p-6 md:p-12">
            <div className="grid gap-10 lg:grid-cols-[1fr_520px] lg:gap-12">
                <div className="flex flex-col gap-4">
                    <h2 className="text-xl font-bold leading-[1.2] tracking-[-0.5px] text-foreground">
                        Klaar om {provider.label} te koppelen?
                    </h2>
                    <p className="text-base leading-[1.6] text-muted-foreground">
                        Vertel kort wat je wilt koppelen. Wij regelen de omgeving, het token en de onboarding.
                    </p>
                    <div className="mt-2">
                        <IntakeStepList steps={intakeSteps} />
                    </div>
                </div>

                <div className="h-fit rounded-lg border border-border bg-card px-8 py-9 shadow-card">
                    <KoppelForm
                        providers={providers.map(({ key, label }) => ({ key, label }))}
                        preselect={provider.key}
                    />
                </div>
            </div>
        </Reveal>
    );
}
