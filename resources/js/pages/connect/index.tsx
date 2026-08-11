import { router } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';
import { useEffect, useState } from 'react';
import { SimpleFooter } from '@/components/landing/footer';
import { Reveal } from '@/components/motion';
import { Seo } from '@/components/seo';
import { Button } from '@/components/ui/button';
import { Eyebrow } from '@/components/ui/eyebrow';
import { PadlockGlyph } from '@/components/ui/glyphs';
import { type SeoMeta } from '@/lib/types';

type ConnectState = 'manage' | 'expired';

type ProviderStatus = 'connected' | 'pending' | 'disconnected';

interface ConnectProvider {
    key: string;
    label: string;
    tagline: string;
    category: string;
    logo: string | null;
    brand: string | null;
    status: ProviderStatus;
    start_url: string;
    disconnect_url: string | null;
}

interface ConnectProps {
    state: ConnectState;
    consumerName: string | null;
    accountName: string | null;
    providers: ConnectProvider[];
    returnUrl: string | null;
    expiresAt: string | null;
    seo: SeoMeta;
}

/** Klok-met-uitroepteken: de link is verlopen, geen fout van de gebruiker. */
function ExpiredGlyph() {
    return (
        <div aria-hidden className="flex size-14 items-center justify-center rounded-full bg-muted">
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
                className="size-6 text-muted-foreground"
            >
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7v5l2.5 2.5" />
            </svg>
        </div>
    );
}

/**
 * Wie stuurt je hierheen. De eindgebruiker komt koud uit een andere app; zonder
 * dit anker weet hij niet waar hij is en waarom.
 */
function HandoffSignal({ consumerName }: { consumerName: string | null }) {
    return (
        <div className="flex items-center gap-2 rounded-full border border-border bg-card px-3.5 py-[7px]">
            {consumerName && (
                <>
                    <span className="text-xs2 font-semibold text-foreground">{consumerName}</span>
                    <span aria-hidden className="text-xs2 text-muted-foreground">
                        ⇄
                    </span>
                </>
            )}
            <img src="/img/logo.png" alt="" aria-hidden className="h-3.5 w-auto" />
            <span className="text-xs2 font-bold text-foreground">emeq hub</span>
        </div>
    );
}

function ProviderRow({ provider }: { provider: ConnectProvider }) {
    const isConnected = provider.status === 'connected';
    const [confirming, setConfirming] = useState(false);
    const [working, setWorking] = useState(false);

    const start = () => router.post(provider.start_url, {}, { preserveScroll: true });

    const disconnect = () => {
        if (!provider.disconnect_url) {
            return;
        }

        setWorking(true);
        router.delete(provider.disconnect_url, {
            preserveScroll: true,
            onFinish: () => {
                setWorking(false);
                setConfirming(false);
            },
        });
    };

    // Ontkoppelen trekt tokens in en is niet met één klik terug te draaien;
    // daarom een bevestigingsstap in de rij zelf in plaats van een dialoog.
    if (confirming) {
        return (
            <div className="flex flex-col gap-3 rounded-lg border border-border bg-card px-[22px] py-[18px] text-left sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-col gap-[3px]">
                    <span className="text-md font-semibold text-foreground">
                        {provider.label} ontkoppelen?
                    </span>
                    <span className="text-xs2 text-muted-foreground">
                        De toegang wordt ingetrokken. Gegevens die al zijn uitgewisseld blijven staan.
                    </span>
                </div>
                {/*
                    "Ontkoppelen" blijft op dezelfde plek staan als de knop die deze stap
                    opende (rechts op desktop, onderaan op mobiel), zodat een destructieve
                    actie nooit onder de cursor wegschuift. Annuleren heet "Toch behouden",
                    gelijk aan /exact/stop.
                */}
                <div className="flex shrink-0 gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setConfirming(false)}
                        disabled={working}
                    >
                        Toch behouden
                    </Button>
                    <Button type="button" size="sm" onClick={disconnect} disabled={working}>
                        {working ? 'Bezig…' : 'Ontkoppelen'}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-border bg-card px-[22px] py-[18px] sm:flex-row sm:items-center sm:gap-4">
            <div className="flex w-full items-center gap-4 sm:w-40 sm:shrink-0">
                {provider.logo ? (
                    <img src={provider.logo} alt={provider.label} className="h-7 w-auto" />
                ) : (
                    <span className="text-md font-semibold text-foreground">{provider.label}</span>
                )}
            </div>

            <div className="flex w-full flex-col gap-[3px]">
                <div className="flex items-center gap-2">
                    <span className="text-md font-semibold text-foreground">{provider.label}</span>
                    {isConnected && (
                        <span className="rounded-full bg-success-soft px-2 py-[2px] font-mono text-2xs uppercase tracking-[1px] text-success">
                            Gekoppeld
                        </span>
                    )}
                    {provider.status === 'pending' && (
                        <span className="rounded-full bg-muted px-2 py-[2px] font-mono text-2xs uppercase tracking-[1px] text-muted-foreground">
                            Bezig
                        </span>
                    )}
                </div>
                <span className="text-xs2 text-muted-foreground">{provider.tagline}</span>
            </div>

            <div className="flex w-full shrink-0 gap-2 sm:w-auto">
                <Button
                    type="button"
                    size="sm"
                    variant={isConnected ? 'outline' : 'primary'}
                    onClick={start}
                    className="w-full sm:w-auto"
                >
                    {isConnected ? 'Opnieuw koppelen' : 'Koppelen'}
                    <span aria-hidden className="ml-2">
                        →
                    </span>
                </Button>
                {isConnected && provider.disconnect_url && (
                    <Button type="button" size="sm" variant="outline" onClick={() => setConfirming(true)}>
                        Ontkoppelen
                    </Button>
                )}
            </div>
        </div>
    );
}

/** Fallback als de consumer geen naam heeft — één bron, zodat losse teksten niet uiteenlopen. */
const APP_FALLBACK = 'de app waar je vandaan komt';

/** Wat de gebruiker deelt als hij koppelt — toestemming vragen zonder dit is te mager. */
function Disclosure({ app }: { app: string }) {
    const points = [
        `Je logt in bij het systeem zelf. Emeq Hub ziet je wachtwoord nooit.`,
        `${app} wisselt daarna alleen de gegevens uit die voor deze koppeling nodig zijn.`,
        `Je kunt de koppeling op elk moment intrekken — op deze pagina, of bij de leverancier zelf.`,
    ];

    return (
        <div className="flex w-full flex-col gap-3 rounded-lg bg-muted p-5 text-left">
            <p className="text-sm font-semibold text-foreground">Wat er gebeurt als je koppelt</p>
            {points.map((point) => (
                <div key={point} className="flex items-start gap-2.5">
                    <PadlockGlyph aria-hidden className="mt-[2px] size-4 shrink-0 text-muted-foreground" />
                    <p className="text-xs2 leading-[1.5] text-muted-foreground">{point}</p>
                </div>
            ))}
        </div>
    );
}

/** Signed return_url when host passed the guard; else document.referrer (not Hub). */
function useHandoffBackUrl(returnUrl: string | null): string | null {
    const [backUrl, setBackUrl] = useState(returnUrl);

    useEffect(() => {
        const referrer = document.referrer;
        const hasExternalReferrer = referrer !== '' && !referrer.startsWith(window.location.origin);

        if (returnUrl) {
            try {
                const signed = new URL(returnUrl);
                const isBareRoot = (signed.pathname === '/' || signed.pathname === '') && signed.search === '';

                // Bare app_url-fallback (geen pad) terwijl de gebruiker vanaf een
                // andere host kwam → liever de referrer dan het marketingdomein.
                if (isBareRoot && hasExternalReferrer && new URL(referrer).host !== signed.host) {
                    setBackUrl(referrer);

                    return;
                }
            } catch {
                // keep signed returnUrl
            }

            setBackUrl(returnUrl);

            return;
        }

        setBackUrl(hasExternalReferrer ? referrer : null);
    }, [returnUrl]);

    return backUrl;
}

export default function Connect({ state, consumerName, accountName, providers, returnUrl, seo }: ConnectProps) {
    const app = consumerName ?? APP_FALLBACK;
    const backUrl = useHandoffBackUrl(returnUrl);

    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <div className="flex min-h-screen flex-col">
                <header className="flex items-center justify-between px-page py-[18px]">
                    <div className="flex items-center gap-2">
                        <img src="/img/logo.png" alt="" aria-hidden className="h-[18px] w-auto" />
                        <span className="text-xl font-bold tracking-[-0.3px] text-foreground">hub</span>
                    </div>
                    <div className="flex items-center gap-[7px] rounded-full bg-muted px-3 py-[7px]">
                        <PadlockGlyph aria-hidden className="size-3.5 text-muted-foreground" />
                        <span className="text-xs2 text-muted-foreground">Beveiligde koppeling</span>
                    </div>
                </header>

                <main className="relative flex flex-1 items-center justify-center overflow-hidden px-page py-16">
                    <div
                        aria-hidden
                        className="pointer-events-none absolute inset-0 opacity-30 [background-image:radial-gradient(circle,#17171720_1px,transparent_1px)] [background-size:24px_24px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
                    />

                    <Reveal className="relative flex w-full max-w-[720px] flex-col items-center gap-8 text-center">
                        {state === 'manage' && (
                            <>
                                <HandoffSignal consumerName={consumerName} />
                                <div className="flex flex-col items-center gap-4">
                                    <Eyebrow className="text-brand">Koppelingen beheren</Eyebrow>
                                    <h1 className="text-2xl font-bold leading-[1.15] tracking-[-1px] text-foreground md:text-3xl md:tracking-[-1.5px]">
                                        Je koppelingen
                                    </h1>
                                    <p className="max-w-[560px] text-base leading-[1.55] text-muted-foreground">
                                        {accountName ? <>Je beheert de koppelingen van {accountName}. </> : null}
                                        Een gekoppeld systeem wisselt automatisch gegevens uit met {app} — je hoeft niets
                                        meer over te typen.
                                    </p>
                                </div>

                                <div className="flex w-full flex-col gap-3">
                                    {providers.map((provider) => (
                                        <ProviderRow key={provider.key} provider={provider} />
                                    ))}
                                </div>

                                <Disclosure app={app} />

                                {backUrl && (
                                    <Button type="button" size="sm" onClick={() => (window.location.href = backUrl)}>
                                        Terug naar {app}
                                        <span aria-hidden className="ml-2">
                                            →
                                        </span>
                                    </Button>
                                )}

                                <p className="text-xs2 text-muted-foreground">
                                    Deze pagina is persoonlijk en verloopt na 15 minuten.
                                </p>
                            </>
                        )}

                        {state === 'expired' && (
                            <>
                                <ExpiredGlyph />
                                <div className="flex flex-col items-center gap-4">
                                    <Eyebrow>Link verlopen</Eyebrow>
                                    <h1 className="text-2xl font-bold leading-[1.15] tracking-[-1px] text-foreground md:text-3xl md:tracking-[-1.5px]">
                                        Deze koppellink is niet meer geldig
                                    </h1>
                                    <p className="max-w-[560px] text-base leading-[1.55] text-muted-foreground">
                                        Een koppellink is 15 minuten geldig. Ga terug naar {APP_FALLBACK} en start de
                                        koppeling daar opnieuw — je bent zo weer hier.
                                    </p>
                                </div>

                                <p className="text-xs2 text-muted-foreground">
                                    Lukt het daarna nog niet? Neem contact op met de supportafdeling van die app.
                                </p>
                            </>
                        )}
                    </Reveal>
                </main>

                <SimpleFooter />
            </div>
        </MotionConfig>
    );
}
