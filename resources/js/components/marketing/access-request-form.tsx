import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Check, Loader2 } from 'lucide-react';
import { Reveal } from '@/components/marketing/reveal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { SharedProps } from '@/types';

function FieldError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="mt-1 text-sm text-destructive">{message}</p>;
}

/**
 * Koppel-aanvraag voor één provider. De integratie is voorgeselecteerd vanuit de
 * partner-pagina (geen keuzeveld); de Hub onboardt daarna via OnboardConsumer.
 * Honeypot + throttle tegen spam; POST /koppelen.
 */
export function AccessRequestForm({ provider }: { provider: { key: string; label: string } }) {
    const page = usePage<SharedProps & { flash?: { submitted?: boolean } }>();
    const submitted = Boolean(page.props.flash?.submitted);

    const form = useForm({
        company: '',
        contact_name: '',
        email: '',
        app_url: '',
        providers: [provider.key],
        message: '',
        privacy_accepted: false,
        website: '', // honeypot — moet leeg blijven
    });

    const submit = (e: React.FormEvent): void => {
        e.preventDefault();
        form.post('/koppelen', { preserveScroll: true });
    };

    return (
        <Reveal>
            <div className="rounded-2xl border bg-card p-6 shadow-sm sm:p-8">
                {submitted ? (
                    <div className="py-8 text-center">
                        <div className="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 ring-1 ring-emerald-500/20 dark:text-emerald-400">
                            <Check className="size-7" />
                        </div>
                        <h3 className="mt-5 text-xl font-bold">Aanvraag ontvangen</h3>
                        <p className="mx-auto mt-2 max-w-md text-muted-foreground">
                            Bedankt — we nemen zo snel mogelijk contact met je op om de koppeling klaar te zetten.
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
                            <Label htmlFor="message">
                                Bericht <span className="text-muted-foreground">(optioneel)</span>
                            </Label>
                            <Textarea
                                id="message"
                                rows={4}
                                value={form.data.message}
                                onChange={(e) => form.setData('message', e.target.value)}
                                aria-invalid={Boolean(form.errors.message)}
                                className="mt-1.5 resize-none"
                                placeholder={`Waar wil je mee koppelen via ${provider.label} en wat is je tijdlijn?`}
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

                        <div>
                            <label htmlFor="privacy_accepted" className="flex items-start gap-3 text-sm text-muted-foreground">
                                <input
                                    id="privacy_accepted"
                                    type="checkbox"
                                    checked={form.data.privacy_accepted}
                                    onChange={(e) => form.setData('privacy_accepted', e.target.checked)}
                                    aria-invalid={Boolean(form.errors.privacy_accepted)}
                                    className="mt-0.5 size-4 shrink-0 rounded border-input accent-amber-500"
                                />
                                <span>
                                    Ik ga akkoord met het{' '}
                                    <a href="/privacy" target="_blank" rel="noopener noreferrer" className="font-medium text-amber-600 underline hover:text-amber-500">
                                        privacybeleid
                                    </a>
                                    .
                                </span>
                            </label>
                            <FieldError message={form.errors.privacy_accepted} />
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
    );
}
