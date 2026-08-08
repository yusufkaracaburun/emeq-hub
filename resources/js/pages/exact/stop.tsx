import { Link, router, useForm } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';
import { SimpleFooter } from '@/components/landing/footer';
import { Nav } from '@/components/landing/nav';
import { Reveal } from '@/components/motion';
import { Seo } from '@/components/seo';
import { Button, buttonVariants } from '@/components/ui/button';
import { Eyebrow } from '@/components/ui/eyebrow';
import { CheckCircleGlyph } from '@/components/ui/glyphs';
import { type SeoMeta } from '@/lib/types';

type StopState = 'confirm' | 'done' | 'soft';

interface ExactStopProps {
    state: StopState;
    seo: SeoMeta;
}

/** Losgekoppelde stekker — neutrale variant van de state-badge (soft-state). */
function UnplugGlyph() {
    return (
        <div aria-hidden className="flex size-11 items-center justify-center rounded-full bg-muted">
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
                className="size-5 text-muted-foreground"
            >
                <path d="m19 5 3-3" />
                <path d="m2 22 3-3" />
                <path d="M6.3 20.3a2.4 2.4 0 0 0 3.4 0L12 18l-6-6-2.3 2.3a2.4 2.4 0 0 0 0 3.4Z" />
                <path d="M7.5 13.5 10 11" />
                <path d="M13.5 7.5 11 10" />
                <path d="m12 6 6 6 2.3-2.3a2.4 2.4 0 0 0 0-3.4l-2.6-2.6a2.4 2.4 0 0 0-3.4 0Z" />
            </svg>
        </div>
    );
}

/** Klein Q-merk + wordmark: Exact stuurt gebruikers hier koud naartoe. */
function BrandSignal() {
    return (
        <div className="flex items-center gap-2">
            <img src="/img/logo.png" alt="" aria-hidden className="h-4 w-auto" />
            <span className="text-sm font-bold tracking-[-0.3px] text-foreground">emeq hub</span>
        </div>
    );
}

const COPY: Record<StopState, { eyebrow: string; title: string; sub: string; hint: string }> = {
    confirm: {
        eyebrow: 'Koppeling beëindigen',
        title: 'Emeq Hub ontkoppelen van Exact Online?',
        sub: 'Je Exact-administratie wordt losgekoppeld. Lopende tokens worden ingetrokken. Boekingen die al in Exact staan blijven staan.',
        hint: 'Dit kun je later opnieuw autoriseren via je app of het App Center.',
    },
    done: {
        eyebrow: 'Koppeling beëindigd',
        title: 'Geen toegang meer',
        sub: 'Emeq Hub heeft geen toegang meer tot deze Exact-administratie.',
        hint: 'Opnieuw koppelen kan altijd via het Exact App Center.',
    },
    soft: {
        eyebrow: 'Koppeling beëindigen',
        title: 'Geen actieve koppeling gevonden',
        sub: 'Er is geen Emeq Hub-koppeling gekoppeld aan dit Exact-account, of die is al beëindigd. Twijfel je? Neem contact op.',
        hint: 'Support bereik je via de supportpagina of info@emeq.nl.',
    },
};

function ConfirmActions() {
    const form = useForm({});

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/exact/stop');
            }}
            className="flex flex-wrap items-center justify-center gap-3"
        >
            <Button type="submit" size="sm" disabled={form.processing}>
                Ontkoppelen
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => (window.history.length > 1 ? window.history.back() : router.visit('/'))}
            >
                Toch behouden
            </Button>
        </form>
    );
}

function LinkActions({ primary, secondary }: { primary: { href: string; label: string }; secondary: { href: string; label: string } }) {
    return (
        <div className="flex flex-wrap items-center justify-center gap-3">
            <Link href={primary.href} className={buttonVariants({ variant: 'primary', size: 'sm' })}>
                {primary.label}
            </Link>
            <Link href={secondary.href} className={buttonVariants({ variant: 'outline', size: 'sm' })}>
                {secondary.label}
            </Link>
        </div>
    );
}

export default function ExactStop({ state, seo }: ExactStopProps) {
    const copy = COPY[state];

    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <div className="flex min-h-screen flex-col">
                <Nav />
                <main className="relative flex flex-1 items-center justify-center overflow-hidden px-page py-24">
                    <div
                        aria-hidden
                        className="pointer-events-none absolute inset-x-0 top-0 h-[360px] opacity-30 [background-image:radial-gradient(circle,#17171720_1px,transparent_1px)] [background-size:24px_24px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
                    />

                    <Reveal className="relative flex w-full max-w-[640px] flex-col items-center gap-6 text-center">
                        {state === 'confirm' && <BrandSignal />}
                        {state === 'done' && <CheckCircleGlyph className="size-11" />}
                        {state === 'soft' && <UnplugGlyph />}

                        <div className="flex flex-col items-center gap-4">
                            <Eyebrow>{copy.eyebrow}</Eyebrow>
                            <h1 className="text-2xl font-bold leading-[1.08] tracking-[-1px] text-foreground md:text-display md:tracking-[-2px]">
                                {copy.title}
                            </h1>
                            <p className="max-w-[520px] text-base leading-[1.6] text-muted-foreground">{copy.sub}</p>
                        </div>

                        {state === 'confirm' && <ConfirmActions />}
                        {state === 'done' && (
                            <LinkActions primary={{ href: '/', label: 'Terug naar Emeq Hub' }} secondary={{ href: '/support', label: 'Support' }} />
                        )}
                        {state === 'soft' && (
                            <LinkActions primary={{ href: '/', label: 'Naar Emeq Hub' }} secondary={{ href: '/support', label: 'Support' }} />
                        )}

                        <p className="font-mono text-2xs text-muted-foreground">{copy.hint}</p>
                    </Reveal>
                </main>
                <SimpleFooter />
            </div>
        </MotionConfig>
    );
}
