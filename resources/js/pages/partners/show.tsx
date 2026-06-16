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
