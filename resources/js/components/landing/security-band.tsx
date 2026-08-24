import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { KeyGlyph, PadlockGlyph } from '@/components/ui/glyphs';
import { Pill } from '@/components/ui/pill';

const badges = [
    {
        icon: <img src="/img/badges/iso27001.png" alt="" aria-hidden className="size-5 opacity-70" />,
        label: 'ISO 27001-hosting',
    },
    {
        icon: <img src="/img/badges/gdpr.png" alt="" aria-hidden className="size-5 opacity-70" />,
        label: 'AVG-verwerkersovereenkomst',
    },
    { icon: <PadlockGlyph className="size-5 text-muted-foreground" />, label: 'Tokens versleuteld opgeslagen' },
    { icon: <KeyGlyph className="size-5 text-muted-foreground" />, label: 'Eigen webhook-secret per koppeling' },
];

export function SecurityBand() {
    return (
        <section className="border-y border-border bg-card px-page py-20 lg:py-24">
            <div className="mx-auto flex max-w-[720px] flex-col items-center gap-10 text-center">
                <Reveal className="flex flex-col items-center gap-4">
                    <p className="font-mono text-xs uppercase tracking-[1.5px] text-muted-foreground">
                        Security &amp; compliance
                    </p>
                    <p className="max-w-[640px] text-lg leading-[1.6] text-muted-foreground">
                        Tokens slaan we versleuteld op, elke koppeling krijgt een eigen webhook-secret en elke call
                        belandt in de audit-log.
                    </p>
                </Reveal>

                <RevealGroup className="flex flex-wrap items-center justify-center gap-4">
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
