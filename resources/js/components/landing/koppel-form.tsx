import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, CircleAlert, CircleCheck } from 'lucide-react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { type ProviderSummary, type SharedProps } from '@/lib/types';
import { cn } from '@/lib/utils';

interface KoppelFormProps {
    providers: Pick<ProviderSummary, 'key' | 'label'>[];
    /** Provider-key die vooraf aangevinkt staat (de partner-pagina waarop het formulier staat). */
    preselect?: string;
}

/**
 * Koppel-intake — POST /koppelen (StoreAccessRequestRequest). Veldnamen en
 * foutteksten volgen de backend; succes komt terug via flash.submitted.
 * Het verborgen `website`-veld is de honeypot.
 */
export function KoppelForm({ providers, preselect }: KoppelFormProps) {
    const { flash } = usePage<SharedProps>().props;

    const form = useForm({
        contact_name: '',
        email: '',
        company: '',
        app_url: '',
        providers: (preselect ? [preselect] : []) as string[],
        message: '',
        privacy_accepted: false as boolean,
        website: '',
    });

    if (flash.submitted) {
        return (
            <div className="flex flex-col items-center gap-3 rounded-md border border-border bg-background px-7 py-8 text-center">
                <CircleCheck aria-hidden className="size-8 text-success" />
                <p className="text-lg font-semibold text-foreground">Aanvraag verzonden</p>
                <p className="max-w-[380px] text-sm leading-[1.6] text-muted-foreground">
                    We nemen binnen één werkdag contact met je op. Je ontvangt een bevestiging per e-mail.
                </p>
            </div>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/koppelen', { preserveScroll: true });
    };

    const toggleProvider = (key: string) => {
        form.setData(
            'providers',
            form.data.providers.includes(key)
                ? form.data.providers.filter((k) => k !== key)
                : [...form.data.providers, key],
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <p className="text-lg font-semibold text-foreground">Start je integratie-aanvraag</p>

            <Field label="Naam *" error={form.errors.contact_name}>
                <TextInput
                    value={form.data.contact_name}
                    invalid={Boolean(form.errors.contact_name)}
                    autoComplete="name"
                    placeholder="Je volledige naam"
                    onChange={(v) => form.setData('contact_name', v)}
                />
            </Field>

            <Field label="Werk-e-mail *" error={form.errors.email}>
                <TextInput
                    type="email"
                    value={form.data.email}
                    invalid={Boolean(form.errors.email)}
                    autoComplete="email"
                    placeholder="naam@bedrijf.nl"
                    mono
                    onChange={(v) => form.setData('email', v)}
                />
            </Field>

            <Field label="Bedrijf *" error={form.errors.company}>
                <TextInput
                    value={form.data.company}
                    invalid={Boolean(form.errors.company)}
                    autoComplete="organization"
                    placeholder="Bedrijfsnaam"
                    onChange={(v) => form.setData('company', v)}
                />
            </Field>

            <Field label="URL van je app" error={form.errors.app_url}>
                <TextInput
                    type="url"
                    value={form.data.app_url}
                    invalid={Boolean(form.errors.app_url)}
                    placeholder="https://jouwapp.nl"
                    mono
                    onChange={(v) => form.setData('app_url', v)}
                />
            </Field>

            <Field label="Welke integraties? *" error={form.errors.providers}>
                <div className="flex flex-wrap gap-2.5">
                    {providers.map((provider) => {
                        const selected = form.data.providers.includes(provider.key);

                        return (
                            <button
                                key={provider.key}
                                type="button"
                                aria-pressed={selected}
                                onClick={() => toggleProvider(provider.key)}
                                className={cn(
                                    'rounded-pill border px-3.5 py-[7px] text-sm font-medium transition-colors duration-150',
                                    selected
                                        ? 'border-brand bg-brand-soft text-brand'
                                        : 'border-border bg-card text-muted-foreground hover:border-brand hover:text-foreground',
                                )}
                            >
                                {provider.label}
                            </button>
                        );
                    })}
                </div>
            </Field>

            <Field label="Toelichting (optioneel)" error={form.errors.message}>
                <textarea
                    value={form.data.message}
                    onChange={(e) => form.setData('message', e.target.value)}
                    placeholder="Welke systemen, datastromen of processen wil je verbinden?"
                    rows={3}
                    className={inputClasses(Boolean(form.errors.message))}
                />
            </Field>

            <label className="flex items-start gap-2.5 text-sm text-muted-foreground">
                <input
                    type="checkbox"
                    checked={form.data.privacy_accepted}
                    onChange={(e) => form.setData('privacy_accepted', e.target.checked)}
                    className="mt-0.5 size-4 accent-brand"
                />
                <span>
                    Ik ga akkoord met het{' '}
                    <Link href="/privacy" className="font-medium text-foreground underline underline-offset-4 hover:text-brand">
                        privacybeleid
                    </Link>
                    . *
                </span>
            </label>
            {form.errors.privacy_accepted && <FieldError message={form.errors.privacy_accepted} />}

            {/* Honeypot — verborgen voor mensen, bots vullen 'm in. */}
            <input
                type="text"
                name="website"
                value={form.data.website}
                onChange={(e) => form.setData('website', e.target.value)}
                tabIndex={-1}
                autoComplete="off"
                aria-hidden="true"
                className="hidden"
            />

            <Button type="submit" size="md" disabled={form.processing} className="group mt-1 w-full">
                Start mijn aanvraag
                <ArrowRight aria-hidden className="size-4 transition-transform duration-150 group-hover:translate-x-0.5" />
            </Button>
            <p className="text-center font-mono text-2xs text-muted-foreground">
                Persoonlijk contact binnen één werkdag · vrijblijvend
            </p>
        </form>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <label className="text-xs2 font-medium text-foreground">{label}</label>
            {children}
            {error && <FieldError message={error} />}
        </div>
    );
}

function FieldError({ message }: { message: string }) {
    return (
        <p className="flex items-center gap-1.5 text-xs2 text-error">
            <CircleAlert aria-hidden className="size-3.5" />
            {message}
        </p>
    );
}

function inputClasses(invalid: boolean) {
    return cn(
        'w-full rounded-sm border bg-background px-3.5 py-3 text-sm text-foreground placeholder:text-muted-foreground',
        'transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-ring',
        invalid ? 'border-error' : 'border-input',
    );
}

interface TextInputProps {
    value: string;
    onChange: (value: string) => void;
    invalid: boolean;
    type?: string;
    placeholder?: string;
    autoComplete?: string;
    mono?: boolean;
}

function TextInput({ value, onChange, invalid, type = 'text', placeholder, autoComplete, mono = false }: TextInputProps) {
    return (
        <input
            type={type}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder={placeholder}
            autoComplete={autoComplete}
            className={cn(inputClasses(invalid), mono && 'font-mono text-xs2')}
        />
    );
}

export { Field, FieldError, inputClasses, TextInput };
