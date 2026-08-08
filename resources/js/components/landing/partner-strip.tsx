import { Reveal } from '@/components/motion';
import { type ProviderSummary } from '@/lib/types';

/** Compacte logo-strip: caption + gedempte (grijswaarden) partnerlogo's. */
export function PartnerStrip({ providers }: { providers: ProviderSummary[] }) {
    const withLogo = providers.filter((p) => p.live && p.logo !== null);

    if (withLogo.length === 0) {
        return null;
    }

    return (
        <section className="border-y border-border bg-card">
            <Reveal className="flex flex-wrap items-center justify-center gap-6 px-6 py-[22px]">
                <p className="font-mono text-xs uppercase tracking-[1.5px] text-muted-foreground">Nu live met</p>
                {withLogo.map((provider) => (
                    <img
                        key={provider.key}
                        src={provider.logo!}
                        alt={provider.label}
                        className="h-[26px] opacity-75 grayscale"
                    />
                ))}
            </Reveal>
        </section>
    );
}
