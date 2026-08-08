import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { BroadcastGlyph, KeyGlyph, MergeGlyph } from '@/components/ui/glyphs';
import { Eyebrow } from '@/components/ui/eyebrow';

const columns = [
    {
        index: '01',
        icon: MergeGlyph,
        title: 'Eén API. Elke partner.',
        body: 'Integreer één keer met het uniforme API-contract van Emeq Hub. Nieuwe partners voeg je toe zonder je productlogica opnieuw te ontwerpen.',
    },
    {
        index: '02',
        icon: KeyGlyph,
        title: 'OAuth en tokens? Al geregeld.',
        body: 'Emeq Hub beheert OAuth-flows, bewaart credentials versleuteld en ververst tokens automatisch. Minder onderhoud, minder storingen.',
    },
    {
        index: '03',
        icon: BroadcastGlyph,
        title: 'Volledige grip op elke koppeling.',
        body: 'Van webhooks tot audit-trails en tenant-scheiding: je ziet wat er gebeurt en houdt controle op schaal.',
    },
];

export function ValueTrio() {
    return (
        <section id="waarom" className="px-6 py-24 lg:px-section-x lg:py-section-x">
            <Reveal className="flex max-w-[760px] flex-col gap-4">
                <Eyebrow>01 — Waarom Emeq Hub</Eyebrow>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-3xl">
                    Stop met integraties steeds opnieuw bouwen.
                </h2>
            </Reveal>

            <RevealGroup className="mt-12 grid gap-10 border-t border-border md:grid-cols-3 md:gap-0">
                {columns.map((column, index) => (
                    <RevealItem
                        key={column.index}
                        className={
                            index === 0
                                ? 'flex flex-col gap-6 pb-2 pt-10 md:pr-11'
                                : 'flex flex-col gap-6 pb-2 pt-10 md:border-l md:border-border md:px-11'
                        }
                    >
                        <div className="flex items-center justify-between">
                            <column.icon aria-hidden className="size-5 text-foreground" />
                            <span className="font-mono text-xs2 tracking-[1px] text-muted-foreground/60">{column.index}</span>
                        </div>
                        <h3 className="text-lg font-semibold text-foreground">{column.title}</h3>
                        <p className="text-md leading-[1.6] text-muted-foreground">{column.body}</p>
                    </RevealItem>
                ))}
            </RevealGroup>
        </section>
    );
}
