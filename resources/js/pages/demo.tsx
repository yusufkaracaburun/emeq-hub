import { Link, useForm, usePage } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';
import { ArrowRight, CircleAlert, CircleCheck } from 'lucide-react';
import { type FormEvent } from 'react';
import { IntakeShell } from '@/components/landing/intake-shell';
import { Field, FieldError, inputClasses, TextInput } from '@/components/landing/koppel-form';
import { Seo } from '@/components/seo';
import { Button } from '@/components/ui/button';
import { type SeoMeta, type SharedProps } from '@/lib/types';
import { cn } from '@/lib/utils';

const steps = [
    {
        title: 'Van API-call naar resultaat',
        description: 'Bekijk hoe één request veilig bij de juiste partner terechtkomt.',
    },
    {
        title: 'Inzicht zonder zoeken',
        description: 'Zie precies wat er gebeurt: status, provider, timing en resultaat.',
    },
    {
        title: 'Een route voor jouw use-case',
        description: 'We vertalen jouw integratievraag naar een concrete, schaalbare aanpak.',
    },
];

interface DemoProps {
    slots: string[];
    seo: SeoMeta;
}

export default function Demo({ slots, seo }: DemoProps) {
    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <IntakeShell
                eyebrow="Demo"
                title={
                    <>
                        Zie hoe je <br />
                        <span className="text-brand">sneller integreert.</span>
                    </>
                }
                intro="In 30 minuten zie je hoe emeq je koppelingen versnelt: van eerste API-call tot audit-inzicht, afgestemd op jouw use-case."
                steps={steps}
            >
                <DemoForm slots={slots} />
            </IntakeShell>
        </MotionConfig>
    );
}

/**
 * Demo-aanvraag — POST /demo (StoreDemoRequestRequest). Veldnamen en foutteksten
 * volgen de backend; succes komt terug via flash.submitted. Het verborgen
 * `website`-veld is de honeypot.
 */
function DemoForm({ slots }: { slots: string[] }) {
    const { flash } = usePage<SharedProps>().props;

    const form = useForm({
        contact_name: '',
        email: '',
        company: '',
        preferred_slot: '',
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
                    We plannen je demo binnen één werkdag in. Je ontvangt een bevestiging per e-mail.
                </p>
            </div>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/demo', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <p className="text-lg font-semibold text-foreground">Plan je persoonlijke demo</p>

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

            <Field label="Wanneer schikt het? *" error={form.errors.preferred_slot}>
                <select
                    value={form.data.preferred_slot}
                    onChange={(e) => form.setData('preferred_slot', e.target.value)}
                    className={cn(
                        inputClasses(Boolean(form.errors.preferred_slot)),
                        'appearance-none bg-[url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2716%27 height=%2716%27 fill=%27none%27 stroke=%27%23737373%27 stroke-width=%272%27%3E%3Cpath d=%27m4 6 4 4 4-4%27/%3E%3C/svg%3E")] bg-[position:right_14px_center] bg-no-repeat pr-10',
                        form.data.preferred_slot === '' && 'text-muted-foreground',
                    )}
                >
                    <option value="" disabled>
                        Kies een voorkeursmoment
                    </option>
                    {slots.map((slot) => (
                        <option key={slot} value={slot}>
                            {slot}
                        </option>
                    ))}
                </select>
            </Field>

            <Field label="Toelichting (optioneel)" error={form.errors.message}>
                <textarea
                    value={form.data.message}
                    onChange={(e) => form.setData('message', e.target.value)}
                    placeholder="Welke systemen of processen wil je tijdens de demo bespreken?"
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
                Plan mijn demo
                <ArrowRight aria-hidden className="size-4 transition-transform duration-150 group-hover:translate-x-0.5" />
            </Button>
            <p className="font-mono text-2xs text-muted-foreground">
                30 minuten · online · afgestemd op jouw integratievraag
            </p>
        </form>
    );
}
