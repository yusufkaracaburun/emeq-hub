import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { CodeBlock } from '@/components/marketing/code-block';
import { CtaSection } from '@/components/marketing/cta-section';
import { HeroShell } from '@/components/marketing/hero';
import { ProviderLogo } from '@/components/marketing/provider-logo';
import { Reveal } from '@/components/marketing/reveal';
import { TrustBar } from '@/components/marketing/trust-bar';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import ShowcaseLayout from '@/layouts/showcase-layout';
import type { ProviderDetail } from '@/types';

const METHOD_STYLES: Record<string, string> = {
    GET: 'bg-emerald-500/10 text-emerald-600 ring-emerald-500/20 dark:text-emerald-400',
    POST: 'bg-blue-500/10 text-blue-600 ring-blue-500/20 dark:text-blue-400',
    PUT: 'bg-violet-500/10 text-violet-600 ring-violet-500/20 dark:text-violet-400',
    PATCH: 'bg-violet-500/10 text-violet-600 ring-violet-500/20 dark:text-violet-400',
    DELETE: 'bg-red-500/10 text-red-600 ring-red-500/20 dark:text-red-400',
    ANY: 'bg-muted text-muted-foreground ring-border',
};

function MethodTag({ method }: { method: string }) {
    return (
        <span
            className={cn(
                'inline-flex w-16 justify-center rounded-md px-2 py-0.5 font-mono text-xs font-semibold ring-1 ring-inset',
                METHOD_STYLES[method] ?? METHOD_STYLES.ANY,
            )}
        >
            {method}
        </span>
    );
}

export default function PartnerShow({ provider }: { provider: ProviderDetail }) {
    const hasDeveloperDetail = Boolean(
        provider.how_it_works || provider.integration || provider.endpoints || provider.example_curl,
    );

    return (
        <ShowcaseLayout>
            <Head title={provider.label} />

            <HeroShell className="pb-0">
                <Link
                    href="/partners"
                    className="inline-flex items-center text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="mr-1 size-4" /> Alle integraties
                </Link>

                <div className="mt-8 flex flex-col gap-6 sm:flex-row sm:items-center">
                    <ProviderLogo provider={provider} size="lg" />
                    <div>
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{provider.label}</h1>
                            <Badge variant="outline">{provider.category}</Badge>
                        </div>
                        <p className="mt-1 text-muted-foreground">{provider.tagline}</p>
                    </div>
                </div>
                <p className="mt-6 max-w-3xl text-lg text-muted-foreground">{provider.summary}</p>
            </HeroShell>

            <div className="mx-auto max-w-5xl px-4">
                {provider.use_cases && (
                    <section className="border-t py-12">
                        <h2 className="text-2xl font-semibold">Wat het je oplevert</h2>
                        <p className="mt-2 max-w-3xl text-muted-foreground">
                            Concrete dingen die je met de koppeling regelt — zonder dubbel werk of overtypen.
                        </p>
                        <div className="mt-6 grid gap-5 md:grid-cols-2">
                            {provider.use_cases.map((useCase, i) => (
                                <Reveal key={useCase.title} delay={i * 0.05}>
                                    <Card className="h-full transition-colors hover:border-amber-500/40">
                                        <CardHeader>
                                            <CardTitle className="text-base">{useCase.title}</CardTitle>
                                        </CardHeader>
                                        <CardContent className="text-sm text-muted-foreground">
                                            {useCase.value}
                                        </CardContent>
                                    </Card>
                                </Reveal>
                            ))}
                        </div>
                    </section>
                )}

                <section className="border-t py-12">
                    <h2 className="text-2xl font-semibold">Wat we ondersteunen</h2>
                    <div className="mt-6 grid gap-5 md:grid-cols-2">
                        {provider.capabilities.map((capability, i) => (
                            <Reveal key={capability.title} delay={i * 0.05}>
                                <Card className="h-full transition-colors hover:border-amber-500/40">
                                    <CardHeader>
                                        <CardTitle className="text-base">{capability.title}</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-sm text-muted-foreground">
                                        {capability.description}
                                    </CardContent>
                                </Card>
                            </Reveal>
                        ))}
                    </div>
                </section>

                <section className="border-t py-12">
                    <TrustBar className="justify-start" />
                    <div className="mt-10">
                        <CtaSection
                            title={`Aan de slag met ${provider.label}?`}
                            description="Wij regelen de koppeling en de techniek. Vraag een koppeling aan of stel je vraag — we denken met je mee."
                            primary={{ label: 'Neem contact op', href: 'mailto:support@emeq.nl' }}
                            secondary={{ label: 'Alle integraties', href: '/partners' }}
                        />
                    </div>
                </section>

                {hasDeveloperDetail && (
                    <section className="border-t py-12">
                        <Badge variant="outline" className="mb-3">
                            Voor ontwikkelaars
                        </Badge>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Het technische deel voor de ontwikkelaar die de koppeling inbouwt. Voor de afweging of deze
                            integratie iets voor je is, heb je het bovenstaande genoeg.
                        </p>

                        <Accordion type="single" collapsible className="mt-6 rounded-xl border px-4">
                            {provider.how_it_works && (
                                <AccordionItem value="how-it-works">
                                    <AccordionTrigger className="text-base font-semibold">Hoe het werkt</AccordionTrigger>
                                    <AccordionContent>
                                        <div className="max-w-3xl space-y-4 text-muted-foreground">
                                            {provider.how_it_works.map((paragraph, index) => (
                                                <p key={index}>{paragraph}</p>
                                            ))}
                                        </div>
                                    </AccordionContent>
                                </AccordionItem>
                            )}

                            {provider.integration && (
                                <AccordionItem value="integration">
                                    <AccordionTrigger className="text-base font-semibold">
                                        Inrichten aan jouw kant
                                    </AccordionTrigger>
                                    <AccordionContent>
                                        <ol className="space-y-5">
                                            {provider.integration.map((step, index) => (
                                                <li key={step.title} className="flex gap-3">
                                                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-sm font-semibold text-amber-600 ring-1 ring-amber-500/20 dark:text-amber-400">
                                                        {index + 1}
                                                    </span>
                                                    <div>
                                                        <p className="font-medium text-foreground">{step.title}</p>
                                                        <p className="mt-0.5 text-muted-foreground">{step.description}</p>
                                                    </div>
                                                </li>
                                            ))}
                                        </ol>
                                    </AccordionContent>
                                </AccordionItem>
                            )}

                            {provider.endpoints && (
                                <AccordionItem value="endpoints">
                                    <AccordionTrigger className="text-base font-semibold">Endpoints</AccordionTrigger>
                                    <AccordionContent>
                                        <div className="divide-y overflow-hidden rounded-xl border">
                                            {provider.endpoints.map((endpoint) => (
                                                <div
                                                    key={`${endpoint.method} ${endpoint.path}`}
                                                    className="flex flex-col gap-2 p-4 sm:flex-row sm:items-baseline sm:gap-4"
                                                >
                                                    <div className="flex shrink-0 items-center gap-2 sm:w-80">
                                                        <MethodTag method={endpoint.method} />
                                                        <code className="text-sm break-all">{endpoint.path}</code>
                                                    </div>
                                                    <div className="text-muted-foreground">
                                                        <span className="font-medium text-foreground">
                                                            → {endpoint.target}
                                                        </span>
                                                        <span className="mt-0.5 block">{endpoint.description}</span>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </AccordionContent>
                                </AccordionItem>
                            )}

                            <AccordionItem value="connect" className="border-b-0">
                                <AccordionTrigger className="text-base font-semibold">Koppelen</AccordionTrigger>
                                <AccordionContent>
                                    <ol className="space-y-4">
                                        {provider.connect_steps.map((step, index) => (
                                            <li key={index} className="flex gap-3">
                                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-sm font-semibold text-amber-600 ring-1 ring-amber-500/20 dark:text-amber-400">
                                                    {index + 1}
                                                </span>
                                                <span className="pt-0.5 text-muted-foreground">{step}</span>
                                            </li>
                                        ))}
                                    </ol>
                                    {provider.example_curl && <CodeBlock code={provider.example_curl} className="mt-6" />}
                                </AccordionContent>
                            </AccordionItem>
                        </Accordion>
                    </section>
                )}
            </div>
        </ShowcaseLayout>
    );
}
