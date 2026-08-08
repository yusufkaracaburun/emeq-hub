import { Link } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';
import {
    ArrowRight,
    BookOpen,
    ExternalLink,
    FileText,
    ListChecks,
    Percent,
    Receipt,
    RefreshCw,
    Send,
    Users,
    Webhook,
    type LucideIcon,
} from 'lucide-react';
import { SimpleFooter } from '@/components/landing/footer';
import { Nav } from '@/components/landing/nav';
import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Seo } from '@/components/seo';
import { buttonVariants } from '@/components/ui/button';
import { Eyebrow } from '@/components/ui/eyebrow';
import { FeatureCard } from '@/components/ui/feature-card';
import { type ProviderDetail, type SeoMeta } from '@/lib/types';
import { cn } from '@/lib/utils';

interface PartnersShowProps {
    provider: ProviderDetail;
    seo: SeoMeta;
}

/** Lucide-iconen die de showcase-config (features[].icon) mag noemen. */
const featureIcons: Record<string, LucideIcon> = {
    'file-text': FileText,
    receipt: Receipt,
    users: Users,
    'book-open': BookOpen,
    percent: Percent,
    webhook: Webhook,
    'refresh-cw': RefreshCw,
    'list-checks': ListChecks,
    send: Send,
};

export default function PartnersShow({ provider, seo }: PartnersShowProps) {
    const features =
        provider.features ??
        provider.use_cases?.map((useCase) => ({ icon: 'list-checks', title: useCase.title, description: useCase.value })) ??
        [];
    const steps =
        provider.steps ?? provider.how_it_works?.map((paragraph) => ({ title: '', description: paragraph })) ?? [];

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
                    <ProviderHero provider={provider} />
                    {features.length > 0 && <Features provider={provider} features={features} />}
                    {steps.length > 0 && <HowItWorks provider={provider} steps={steps} />}
                    <ConnectCta provider={provider} />
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

            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                <Link href="/koppelen" className={cn(buttonVariants({ variant: 'primary', size: 'md' }), 'group')}>
                    Start met {provider.label}
                    <ArrowRight aria-hidden className="size-4 transition-transform duration-150 group-hover:translate-x-0.5" />
                </Link>
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
                    <ExternalLink aria-hidden className="size-3.5" />
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
    features: { icon: string; title: string; description: string }[];
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
                {features.map((feature) => {
                    const Icon = featureIcons[feature.icon] ?? ListChecks;

                    return (
                        <RevealItem key={feature.title}>
                            <FeatureCard icon={<Icon />} title={feature.title} className="h-full">
                                {feature.description}
                            </FeatureCard>
                        </RevealItem>
                    );
                })}
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

function ConnectCta({ provider }: { provider: ProviderDetail }) {
    return (
        <Reveal className="flex flex-col items-center gap-6 rounded-2xl bg-brand-subtle px-8 py-[60px] text-center lg:px-16">
            <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-2xl">
                Klaar om {provider.label} te koppelen?
            </h2>
            <p className="max-w-[520px] text-base leading-[1.6] text-muted-foreground">
                {provider.connect_pitch ??
                    'Vraag een koppeling aan en we richten je toegang in — binnen één werkdag persoonlijk contact.'}
            </p>
            <Link href="/koppelen" className={cn(buttonVariants({ variant: 'primary', size: 'lg' }), 'group')}>
                Start met {provider.label}
                <ArrowRight aria-hidden className="size-4 transition-transform duration-150 group-hover:translate-x-0.5" />
            </Link>
        </Reveal>
    );
}
