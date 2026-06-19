import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Check, Loader2, Plug } from 'lucide-react';
import { HeroShell } from '@/components/marketing/hero';
import { ProviderLogo } from '@/components/marketing/provider-logo';
import { Reveal } from '@/components/marketing/reveal';
import { SectionHeading } from '@/components/marketing/section-heading';
import { TrustBar } from '@/components/marketing/trust-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import ShowcaseLayout from '@/layouts/showcase-layout';
import type { ProviderSummary, SharedProps } from '@/types';

const STEPS = [
    { title: 'Aanvraag', body: 'Vertel ons over je app en welke integraties je nodig hebt — dit formulier is genoeg.' },
    { title: 'Onboarding door Emeq', body: 'Wij zetten je koppeling klaar: een API-token met de juiste rechten. Geen OAuth-app of token-opslag aan jouw kant.' },
    { title: 'Koppelen & live', body: 'Je klanten koppelen hun administratie of betaalaccount; jij bouwt tegen één API.' },
];

function FieldError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="mt-1 text-sm text-destructive">{message}</p>;
}

export default function Koppelen({ providers }: { providers: ProviderSummary[] }) {
    const page = usePage<SharedProps & { flash?: { submitted?: boolean } }>();
    const submitted = Boolean(page.props.flash?.submitted);

    const form = useForm({
        company: '',
        contact_name: '',
        email: '',
        app_url: '',
        providers: [] as string[],
        message: '',
        website: '', // honeypot — moet leeg blijven
    });

    const toggleProvider = (key: string): void => {
        form.setData(
            'providers',
            form.data.providers.includes(key)
                ? form.data.providers.filter((p) => p !== key)
                : [...form.data.providers, key],
        );
    };

    const submit = (e: React.FormEvent): void => {
        e.preventDefault();
        form.post('/koppelen', { preserveScroll: true });
    };

    return (
        <ShowcaseLayout>
            <Head title="Koppelen — vraag een integratie aan" />

            <HeroShell className="pb-0">
                <div className="mx-auto max-w-3xl text-center">
                    <Badge variant="secondary" className="gap-1.5">
                        <Plug className="size-3.5 text-amber-500" /> Aan de slag
                    </Badge>
                    <h1 className="mt-5 text-4xl font-bold tracking-tight text-balance sm:text-5xl">
                        Koppel je app aan <span className="text-brand-gradient">de Hub</span>
                    </h1>
                    <p className="mx-auto mt-4 max-w-2xl text-lg text-muted-foreground">
                        Vraag een koppeling aan en wij regelen de techniek — OAuth, token-opslag en routing. Jij bouwt
                        tegen één API.
                    </p>
                    <TrustBar className="mt-10" />
                </div>
            </HeroShell>

            <section className="mx-auto max-w-6xl px-4 py-16 sm:py-20">
                <div className="grid gap-10 lg:grid-cols-5">
                    {/* Zo werkt het */}
                    <div className="lg:col-span-2">
                        <SectionHeading eyebrow="Zo werkt het" title="Van aanvraag tot live" className="mb-8" />
                        <ol className="space-y-6">
                            {STEPS.map((step, i) => (
                                <li key={step.title} className="flex gap-4">
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-sm font-semibold text-amber-600 ring-1 ring-amber-500/20 dark:text-amber-400">
                                        {i + 1}
                                    </span>
                                    <div>
                                        <p className="font-semibold">{step.title}</p>
                                        <p className="mt-1 text-sm text-muted-foreground">{step.body}</p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </div>

                    {/* Form / success */}
                    <div className="lg:col-span-3">
                        <Reveal>
                            <div className="rounded-2xl border bg-card p-6 shadow-sm sm:p-8">
                                {submitted ? (
                                    <div className="py-8 text-center">
                                        <div className="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 ring-1 ring-emerald-500/20 dark:text-emerald-400">
                                            <Check className="size-7" />
                                        </div>
                                        <h2 className="mt-5 text-xl font-bold">Aanvraag ontvangen</h2>
                                        <p className="mx-auto mt-2 max-w-md text-muted-foreground">
                                            Bedankt — we nemen zo snel mogelijk contact met je op om de koppeling klaar te
                                            zetten.
                                        </p>
                                        <div className="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                                            <Button asChild variant="outline">
                                                <Link href="/partners">Bekijk integraties</Link>
                                            </Button>
                                            <Button asChild variant="ghost">
                                                <Link href="/">Terug naar home</Link>
                                            </Button>
                                        </div>
                                    </div>
                                ) : (
                                    <form onSubmit={submit} className="space-y-5">
                                        <div className="grid gap-5 sm:grid-cols-2">
                                            <div>
                                                <Label htmlFor="company">Bedrijf</Label>
                                                <Input
                                                    id="company"
                                                    value={form.data.company}
                                                    onChange={(e) => form.setData('company', e.target.value)}
                                                    aria-invalid={Boolean(form.errors.company)}
                                                    className="mt-1.5"
                                                    autoComplete="organization"
                                                />
                                                <FieldError message={form.errors.company} />
                                            </div>
                                            <div>
                                                <Label htmlFor="contact_name">Naam</Label>
                                                <Input
                                                    id="contact_name"
                                                    value={form.data.contact_name}
                                                    onChange={(e) => form.setData('contact_name', e.target.value)}
                                                    aria-invalid={Boolean(form.errors.contact_name)}
                                                    className="mt-1.5"
                                                    autoComplete="name"
                                                />
                                                <FieldError message={form.errors.contact_name} />
                                            </div>
                                        </div>

                                        <div className="grid gap-5 sm:grid-cols-2">
                                            <div>
                                                <Label htmlFor="email">E-mail</Label>
                                                <Input
                                                    id="email"
                                                    type="email"
                                                    value={form.data.email}
                                                    onChange={(e) => form.setData('email', e.target.value)}
                                                    aria-invalid={Boolean(form.errors.email)}
                                                    className="mt-1.5"
                                                    autoComplete="email"
                                                />
                                                <FieldError message={form.errors.email} />
                                            </div>
                                            <div>
                                                <Label htmlFor="app_url">
                                                    App-URL <span className="text-muted-foreground">(optioneel)</span>
                                                </Label>
                                                <Input
                                                    id="app_url"
                                                    type="url"
                                                    placeholder="https://app.voorbeeld.nl"
                                                    value={form.data.app_url}
                                                    onChange={(e) => form.setData('app_url', e.target.value)}
                                                    aria-invalid={Boolean(form.errors.app_url)}
                                                    className="mt-1.5"
                                                />
                                                <FieldError message={form.errors.app_url} />
                                            </div>
                                        </div>

                                        <div>
                                            <Label>Welke integraties?</Label>
                                            <div className="mt-2 grid gap-3 sm:grid-cols-3">
                                                {providers.map((provider) => {
                                                    const selected = form.data.providers.includes(provider.key);

                                                    return (
                                                        <button
                                                            type="button"
                                                            key={provider.key}
                                                            onClick={() => toggleProvider(provider.key)}
                                                            aria-pressed={selected}
                                                            className={cn(
                                                                'flex items-center gap-3 rounded-xl border p-3 text-left transition-all',
                                                                selected
                                                                    ? 'border-amber-500 bg-amber-500/5 ring-2 ring-amber-500/30'
                                                                    : 'hover:border-amber-500/40',
                                                            )}
                                                        >
                                                            <ProviderLogo provider={provider} size="sm" />
                                                            <span className="text-sm font-medium">{provider.label}</span>
                                                            {selected && (
                                                                <Check className="ml-auto size-4 text-amber-600 dark:text-amber-400" />
                                                            )}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                            <FieldError message={form.errors.providers} />
                                        </div>

                                        <div>
                                            <Label htmlFor="message">
                                                Bericht <span className="text-muted-foreground">(optioneel)</span>
                                            </Label>
                                            <Textarea
                                                id="message"
                                                rows={4}
                                                value={form.data.message}
                                                onChange={(e) => form.setData('message', e.target.value)}
                                                aria-invalid={Boolean(form.errors.message)}
                                                className="mt-1.5"
                                                placeholder="Waar wil je mee koppelen en wat is je tijdlijn?"
                                            />
                                            <FieldError message={form.errors.message} />
                                        </div>

                                        {/* Honeypot — verborgen voor mensen, gevuld door bots. */}
                                        <div aria-hidden="true" className="hidden">
                                            <label htmlFor="website">Website</label>
                                            <input
                                                id="website"
                                                type="text"
                                                tabIndex={-1}
                                                autoComplete="off"
                                                value={form.data.website}
                                                onChange={(e) => form.setData('website', e.target.value)}
                                            />
                                        </div>

                                        <Button type="submit" size="lg" className="w-full" disabled={form.processing}>
                                            {form.processing ? (
                                                <>
                                                    <Loader2 className="animate-spin" /> Versturen…
                                                </>
                                            ) : (
                                                <>
                                                    Verstuur aanvraag <ArrowRight />
                                                </>
                                            )}
                                        </Button>

                                        <p className="text-center text-xs text-muted-foreground">
                                            We gebruiken je gegevens alleen om contact op te nemen over de koppeling.
                                        </p>
                                    </form>
                                )}
                            </div>
                        </Reveal>
                    </div>
                </div>
            </section>
        </ShowcaseLayout>
    );
}
