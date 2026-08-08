import { KeyRound, Lock } from 'lucide-react';
import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Pill } from '@/components/ui/pill';

const badges = [
    {
        icon: <img src="/img/badges/iso27001.png" alt="" aria-hidden className="size-5 opacity-70" />,
        label: 'ISO 27001-hosting',
    },
    {
        icon: <img src="/img/badges/gdpr.png" alt="" aria-hidden className="size-5 opacity-70" />,
        label: 'GDPR',
    },
    { icon: <Lock aria-hidden className="size-4 text-muted-foreground" />, label: 'Tokens encrypted at rest' },
    { icon: <KeyRound aria-hidden className="size-4 text-muted-foreground" />, label: 'Per-Connection secrets' },
];

export function SecurityBand() {
    return (
        <section className="border-y border-border bg-card px-6 py-20 lg:px-section-x lg:py-24">
            <div className="mx-auto flex max-w-[720px] flex-col items-center gap-9 text-center">
                <Reveal className="flex flex-col items-center gap-3.5">
                    <p className="font-mono text-xs uppercase tracking-[1.5px] text-muted-foreground">
                        Security &amp; compliance
                    </p>
                    <p className="max-w-[640px] text-lg leading-[1.6] text-muted-foreground">
                        Veilig integreren zonder concessies. Versleutelde credentials, unieke webhook-secrets en een
                        complete audit-trail zijn standaard inbegrepen.
                    </p>
                </Reveal>

                <RevealGroup className="flex flex-wrap items-center justify-center gap-3.5">
                    {badges.map((badge) => (
                        <RevealItem key={badge.label}>
                            <Pill icon={badge.icon} className="border border-border">
                                {badge.label}
                            </Pill>
                        </RevealItem>
                    ))}
                </RevealGroup>
            </div>
        </section>
    );
}
