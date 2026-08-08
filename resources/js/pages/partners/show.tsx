import { Link } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';
import { ArrowRight, ChevronRight, ExternalLink, FileText, ListChecks, RefreshCw, Send } from 'lucide-react';
import { Footer } from '@/components/landing/footer';
import { KoppelForm } from '@/components/landing/koppel-form';
import { Nav } from '@/components/landing/nav';
import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Seo } from '@/components/seo';
import { Eyebrow } from '@/components/ui/eyebrow';
import { buttonVariants } from '@/components/ui/button';
import { FeatureCard } from '@/components/ui/feature-card';
import { type ProviderDetail, type ProviderSummary, type SeoMeta } from '@/lib/types';
import { cn } from '@/lib/utils';

interface PartnersShowProps {
    provider: ProviderDetail;
    providers: ProviderSummary[];
    seo: SeoMeta;
}

const useCaseIcons = [RefreshCw, ListChecks, FileText, Send];

export default function PartnersShow({ provider, providers, seo }: PartnersShowProps) {
    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <Nav />
            <main>
                <Breadcrumbs label={provider.label} />
                <ProviderHero provider={provider} />
                {provider.use_cases && provider.use_cases.length > 0 && <UseCases provider={provider} />}
                {provider.how_it_works && provider.how_it_works.length > 0 && <HowItWorks provider={provider} />}
                {provider.endpoints && provider.endpoints.length > 0 && <Endpoints provider={provider} />}
                <ConnectSection provider={provider} providers={providers} />
            </main>
            <Footer />
        </MotionConfig>
    );
}

/** Zichtbare tegenhanger van de BreadcrumbList-structured-data (PartnersController). */
function Breadcrumbs({ label }: { label: string }) {
    return (
        <nav aria-label="Kruimelpad" className="px-6 pt-8 lg:px-section-x">
            <ol className="mx-auto flex max-w-[1160px] items-center gap-1.5 font-mono text-2xs text-muted-foreground">
                <li>
                    <Link href="/" className="transition-colors duration-150 hover:text-foreground">
                        Home
                    </Link>
                </li>
                <ChevronRight aria-hidden className="size-3" />
                <li>
                    <Link href="/partners" className="transition-colors duration-150 hover:text-foreground">
                        Integraties
                    </Link>
                </li>
                <ChevronRight aria-hidden className="size-3" />
                <li aria-current="page" className="text-foreground">
                    {label}
                </li>
            </ol>
        </nav>
    );
}

function ProviderHero({ provider }: { provider: ProviderDetail }) {
    return (
        <section className="px-6 pb-20 pt-10 lg:px-section-x">
            <Reveal className="mx-auto flex max-w-[760px] flex-col items-center gap-6 text-center">
                <div className="flex items-center gap-3.5">
                    {provider.logo && <img src={provider.logo} alt="" className="h-8" />}
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

                <h1 className="text-2xl font-bold tracking-[-1px] text-foreground md:text-3xl">
                    {provider.label} koppelen via één API
                </h1>
                <p className="max-w-[640px] text-base leading-[1.6] text-muted-foreground">{provider.summary}</p>

                <div className="flex flex-col gap-3.5 sm:flex-row sm:items-center">
                    <a href="#koppelen" className={cn(buttonVariants({ variant: 'primary', size: 'md' }), 'group')}>
                        Start met {provider.label}
                        <ArrowRight aria-hidden className="size-4 transition-transform duration-150 group-hover:translate-x-0.5" />
                    </a>
                    <a href="#zo-werkt-het" className={buttonVariants({ variant: 'outline', size: 'md' })}>
                        Bekijk de werking
                    </a>
                </div>

                <ResourceLinks provider={provider} />
            </Reveal>
        </section>
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
        <div className="flex flex-col items-center gap-3 sm:flex-row sm:gap-6">
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

function UseCases({ provider }: { provider: ProviderDetail }) {
    return (
        <section className="border-t border-border bg-card px-6 py-20 lg:px-section-x">
            <Reveal className="mx-auto flex max-w-[760px] flex-col items-center gap-4 text-center">
                <Eyebrow>Mogelijkheden</Eyebrow>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-2xl">
                    Wat je met {provider.label} automatiseert
                </h2>
            </Reveal>

            <RevealGroup className="mx-auto mt-12 grid max-w-[1160px] gap-6 md:grid-cols-2">
                {provider.use_cases!.map((useCase, index) => {
                    const Icon = useCaseIcons[index % useCaseIcons.length];

                    return (
                        <RevealItem key={useCase.title}>
                            <FeatureCard icon={<Icon />} title={useCase.title} className="h-full">
                                {useCase.value}
                            </FeatureCard>
                        </RevealItem>
                    );
                })}
            </RevealGroup>
        </section>
    );
}

function HowItWorks({ provider }: { provider: ProviderDetail }) {
    return (
        <section id="zo-werkt-het" className="px-6 py-20 lg:px-section-x">
            <Reveal className="mx-auto flex max-w-[760px] flex-col items-center gap-4 text-center">
                <Eyebrow>Onder de motorkap</Eyebrow>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-2xl">
                    Live met {provider.label} in drie stappen
                </h2>
            </Reveal>

            <RevealGroup className="mx-auto mt-12 grid max-w-[1160px] gap-10 lg:grid-cols-3">
                {provider.how_it_works!.map((paragraph, index) => (
                    <RevealItem key={index} className="flex flex-col gap-4">
                        <span
                            aria-hidden
                            className="flex size-11 items-center justify-center rounded-pill border border-border bg-card text-lg font-bold tracking-[-0.5px] text-muted-foreground"
                        >
                            {index + 1}
                        </span>
                        <p className="text-sm leading-[1.6] text-muted-foreground">{paragraph}</p>
                    </RevealItem>
                ))}
            </RevealGroup>
        </section>
    );
}

function Endpoints({ provider }: { provider: ProviderDetail }) {
    return (
        <section className="border-t border-border bg-card px-6 py-20 lg:px-section-x">
            <Reveal className="mx-auto flex max-w-[760px] flex-col items-center gap-4 text-center">
                <Eyebrow>Voor developers</Eyebrow>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-2xl">Endpoint-kaart</h2>
            </Reveal>

            <Reveal delay={0.1} className="mx-auto mt-12 max-w-[1160px] overflow-hidden rounded-lg border border-border bg-background">
                <div className="hidden grid-cols-[90px_minmax(0,1.2fr)_minmax(0,1.6fr)] gap-3 border-b border-border bg-muted px-5 py-2.5 font-mono text-2xs uppercase tracking-[1.5px] text-muted-foreground md:grid">
                    <span>Methode</span>
                    <span>Pad</span>
                    <span>Wat het doet</span>
                </div>
                {provider.endpoints!.map((endpoint) => (
                    <div
                        key={`${endpoint.method}-${endpoint.path}`}
                        className="grid gap-1.5 border-b border-border px-5 py-3.5 font-mono text-xs2 last:border-b-0 md:grid-cols-[90px_minmax(0,1.2fr)_minmax(0,1.6fr)] md:gap-3"
                    >
                        <span className="font-medium text-brand">{endpoint.method}</span>
                        <span className="break-all text-foreground">{endpoint.path}</span>
                        <span className="font-sans text-sm text-muted-foreground">{endpoint.description}</span>
                    </div>
                ))}
            </Reveal>

            {provider.example_curl && (
                <Reveal delay={0.15} className="mx-auto mt-6 max-w-[1160px] overflow-hidden rounded-lg border border-border bg-background">
                    <div className="flex items-center gap-3.5 border-b border-border bg-muted px-4 py-[13px]">
                        <div aria-hidden className="flex items-center gap-2">
                            <span className="size-2.5 rounded-pill bg-border" />
                            <span className="size-2.5 rounded-pill bg-border" />
                            <span className="size-2.5 rounded-pill bg-border" />
                        </div>
                        <span className="font-mono text-xs text-muted-foreground">voorbeeld.sh</span>
                    </div>
                    <pre className="overflow-x-auto p-6 font-mono text-xs2 leading-[1.6] text-foreground">
                        {provider.example_curl}
                    </pre>
                </Reveal>
            )}
        </section>
    );
}

function ConnectSection({ provider, providers }: { provider: ProviderDetail; providers: ProviderSummary[] }) {
    return (
        <section id="koppelen" className="border-t border-border bg-brand-subtle px-6 py-20 lg:px-section-x lg:py-24">
            <div className="mx-auto grid max-w-[1160px] gap-12 lg:grid-cols-[1fr_520px] lg:gap-16">
                <Reveal className="flex flex-col gap-5">
                    <Eyebrow>Start vandaag</Eyebrow>
                    <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-2xl">
                        Klaar om <span className="text-brand">{provider.label}</span> te koppelen?
                    </h2>
                    <p className="max-w-[480px] text-base leading-[1.6] text-muted-foreground">
                        Vraag een koppeling aan en we richten je toegang in: een API-token, de juiste abilities en een
                        korte onboarding. Binnen één werkdag persoonlijk contact — vrijblijvend.
                    </p>
                    <ul className="flex flex-col gap-2.5">
                        {provider.connect_steps.map((step, index) => (
                            <li key={index} className="flex items-start gap-3 text-sm leading-[1.6] text-muted-foreground">
                                <span className="mt-0.5 font-mono text-xs2 text-brand">{`0${index + 1}`}</span>
                                <span className="break-words font-mono text-xs2">{step}</span>
                            </li>
                        ))}
                    </ul>
                </Reveal>

                <Reveal delay={0.1} className="rounded-xl border border-border bg-card p-7 shadow-card lg:p-8">
                    <KoppelForm providers={providers.map(({ key, label }) => ({ key, label }))} preselect={provider.key} />
                </Reveal>
            </div>
        </section>
    );
}
