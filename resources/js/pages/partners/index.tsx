import { Head, Link } from '@inertiajs/react';
import { ArrowRight, BookOpen, CreditCard, Plug } from 'lucide-react';
import ShowcaseLayout from '@/layouts/showcase-layout';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import type { ProviderSummary } from '@/types';

function categoryIcon(category: string) {
    if (category === 'Betalingen') {
        return <CreditCard className="size-5 text-amber-500" />;
    }

    return <BookOpen className="size-5 text-amber-500" />;
}

export default function PartnersIndex({ providers }: { providers: ProviderSummary[] }) {
    return (
        <ShowcaseLayout>
            <Head title="Integraties" />

            <section className="mx-auto max-w-6xl px-4 py-20 text-center md:py-28">
                <Badge variant="secondary" className="mb-4">
                    <Plug className="mr-1 size-3.5" /> Integratieplatform
                </Badge>
                <h1 className="text-4xl font-bold tracking-tight md:text-5xl">
                    Integraties die we{' '}
                    <span className="bg-gradient-to-r from-amber-500 to-orange-600 bg-clip-text text-transparent">
                        ondersteunen
                    </span>
                </h1>
                <p className="mx-auto mt-4 max-w-2xl text-lg text-muted-foreground">
                    Koppel je app via één Hub aan Nederlandse boekhoud- en betaal-API's — OAuth, multi-tenant
                    token-opslag en audit-logging zijn voor ons.
                </p>
            </section>

            <section className="mx-auto max-w-6xl px-4 pb-24">
                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {providers.map((provider) => (
                        <Link key={provider.key} href={`/partners/${provider.key}`} className="group">
                            <Card className="h-full transition-colors group-hover:border-amber-500/60">
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        {categoryIcon(provider.category)}
                                        <Badge variant="outline">{provider.category}</Badge>
                                    </div>
                                    <CardTitle className="mt-2">{provider.label}</CardTitle>
                                    <CardDescription>{provider.tagline}</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">{provider.summary}</p>
                                </CardContent>
                                <CardFooter>
                                    <span className="inline-flex items-center text-sm font-medium text-amber-600">
                                        Bekijk integratie
                                        <ArrowRight className="ml-1 size-4 transition-transform group-hover:translate-x-1" />
                                    </span>
                                </CardFooter>
                            </Card>
                        </Link>
                    ))}
                </div>
            </section>
        </ShowcaseLayout>
    );
}
