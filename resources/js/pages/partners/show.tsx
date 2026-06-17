import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Check, Terminal } from 'lucide-react';
import ShowcaseLayout from '@/layouts/showcase-layout';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import type { ProviderDetail } from '@/types';

export default function PartnerShow({ provider }: { provider: ProviderDetail }) {
    return (
        <ShowcaseLayout>
            <Head title={provider.label} />

            <section className="mx-auto max-w-5xl px-4 py-16">
                <Link
                    href="/partners"
                    className="inline-flex items-center text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="mr-1 size-4" /> Alle integraties
                </Link>

                <div className="mt-6 flex flex-wrap items-center gap-3">
                    <h1 className="text-4xl font-bold tracking-tight">{provider.label}</h1>
                    <Badge variant="outline">{provider.category}</Badge>
                </div>
                <p className="mt-1 text-muted-foreground">{provider.tagline}</p>
                <p className="mt-4 max-w-3xl text-lg text-muted-foreground">{provider.summary}</p>
            </section>

            <Separator className="mx-auto max-w-5xl" />

            {provider.use_cases && (
                <>
                    <section className="mx-auto max-w-5xl px-4 py-12">
                        <h2 className="text-2xl font-semibold">Wat het je oplevert</h2>
                        <p className="mt-2 max-w-3xl text-muted-foreground">
                            Concrete dingen die je met de koppeling regelt — zonder dubbel werk of overtypen.
                        </p>
                        <div className="mt-6 grid gap-5 md:grid-cols-2">
                            {provider.use_cases.map((useCase) => (
                                <Card key={useCase.title}>
                                    <CardHeader>
                                        <CardTitle className="text-base">{useCase.title}</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-sm text-muted-foreground">
                                        {useCase.value}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </section>
                    <Separator className="mx-auto max-w-5xl" />
                </>
            )}

            <section className="mx-auto max-w-5xl px-4 py-12">
                <h2 className="text-2xl font-semibold">Wat we ondersteunen</h2>
                <div className="mt-6 grid gap-5 md:grid-cols-2">
                    {provider.capabilities.map((capability) => (
                        <Card key={capability.title}>
                            <CardHeader>
                                <CardTitle className="text-base">{capability.title}</CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm text-muted-foreground">
                                {capability.description}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </section>

            {(provider.how_it_works || provider.endpoints) && (
                <section className="mx-auto max-w-5xl px-4 pb-2 pt-10">
                    <Badge variant="outline" className="mb-3">
                        Voor ontwikkelaars
                    </Badge>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Het deel hieronder is voor de ontwikkelaar die de koppeling inbouwt. Om te beoordelen of
                        deze integratie iets voor je is, heb je alleen het bovenstaande nodig.
                    </p>
                </section>
            )}

            {provider.how_it_works && (
                <section className="mx-auto max-w-5xl px-4 pb-8">
                    <h2 className="text-2xl font-semibold">Hoe het werkt</h2>
                    <div className="mt-6 max-w-3xl space-y-4">
                        {provider.how_it_works.map((paragraph, index) => (
                            <p key={index} className="text-muted-foreground">
                                {paragraph}
                            </p>
                        ))}
                    </div>
                </section>
            )}

            {provider.integration && (
                <section className="mx-auto max-w-5xl px-4 pb-8">
                    <h2 className="text-2xl font-semibold">Inrichten aan jouw kant</h2>
                    <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                        Wat je app eenmalig opzet om via de Hub met Exact te koppelen — en wat je per document
                        meestuurt. De Hub regelt OAuth, token-refresh en de vertaling naar Exact.
                    </p>
                    <ol className="mt-6 space-y-5">
                        {provider.integration.map((step, index) => (
                            <li key={step.title} className="flex gap-3">
                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-sm font-medium text-amber-600">
                                    {index + 1}
                                </span>
                                <div>
                                    <p className="font-medium">{step.title}</p>
                                    <p className="mt-0.5 text-sm text-muted-foreground">{step.description}</p>
                                </div>
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            {provider.endpoints && (
                <section className="mx-auto max-w-5xl px-4 pb-12">
                    <h2 className="text-2xl font-semibold">Endpoints</h2>
                    <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                        Welke Hub-endpoints je app aanroept en op welk partner-endpoint ze uitkomen. Elke call
                        gebruikt je Bearer-token en de header <code>X-Account-Id</code>; elke call wordt geaudit.
                    </p>
                    <div className="mt-6 divide-y rounded-lg border">
                        {provider.endpoints.map((endpoint) => (
                            <div
                                key={`${endpoint.method} ${endpoint.path}`}
                                className="flex flex-col gap-1 p-4 sm:flex-row sm:items-baseline sm:gap-4"
                            >
                                <div className="flex shrink-0 items-center gap-2 sm:w-80">
                                    <Badge variant="secondary" className="font-mono text-xs">
                                        {endpoint.method}
                                    </Badge>
                                    <code className="text-sm">{endpoint.path}</code>
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    <span className="font-medium text-foreground">→ {endpoint.target}</span>
                                    <span className="mt-0.5 block">{endpoint.description}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            <section className="mx-auto max-w-5xl px-4 pb-12">
                <h2 className="text-2xl font-semibold">Koppelen</h2>
                <ol className="mt-6 space-y-4">
                    {provider.connect_steps.map((step, index) => (
                        <li key={index} className="flex gap-3">
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-sm font-medium text-amber-600">
                                {index + 1}
                            </span>
                            <span className="text-muted-foreground">{step}</span>
                        </li>
                    ))}
                </ol>

                {provider.example_curl && (
                    <div className="mt-8">
                        <div className="mb-2 flex items-center gap-2 text-sm font-medium text-muted-foreground">
                            <Terminal className="size-4" /> Voorbeeld
                        </div>
                        <pre className="overflow-x-auto rounded-lg bg-neutral-950 p-4 text-sm text-neutral-100">
                            <code>{provider.example_curl}</code>
                        </pre>
                    </div>
                )}

                <p className="mt-8 inline-flex items-center gap-2 text-sm text-muted-foreground">
                    <Check className="size-4 text-amber-500" />
                    Tokens encrypted at rest · audit-log per call · per-Connection scheiding.
                </p>
            </section>
        </ShowcaseLayout>
    );
}
