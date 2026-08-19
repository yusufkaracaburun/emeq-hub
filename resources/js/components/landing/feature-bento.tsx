import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Eyebrow } from '@/components/ui/eyebrow';
import { cn } from '@/lib/utils';

interface Cell {
    index: string;
    tag: string;
    title: string;
    body: string;
    hero?: boolean;
}

const rowOne: Cell[] = [
    {
        index: 'F01',
        tag: '/v1/{provider}/{path}',
        title: 'Elk partner-endpoint, één route',
        body: 'Elk partner-endpoint bereik je via dezelfde route. Emeq Hub zoekt de juiste koppeling erbij, voegt credentials toe en geeft status en headers ongewijzigd terug.',
        hero: true,
    },
    {
        index: 'F02',
        tag: 'kill-switch',
        title: 'Direct aan of uit, per omgeving',
        body: 'Zet een provider per omgeving aan of uit, zonder release. Handig bij onderhoud of een storing bij de partner.',
    },
];

const rowTwo: Cell[] = [
    {
        index: 'F03',
        tag: 'Idempotency-Key',
        title: 'Nooit dubbel geboekt',
        body: 'Komt dezelfde request twee keer binnen, dan boeken we hem één keer. De Idempotency-Key-header regelt dat platformbreed.',
    },
    {
        index: 'F04',
        tag: 'canoniek → partner',
        title: 'Eén datamodel, elke partner',
        body: 'Je levert één canoniek document aan; Emeq Hub vertaalt het naar het formaat van de gekoppelde partner.',
    },
    {
        index: 'F05',
        tag: 'partner → hub',
        title: 'Elke webhook traceerbaar',
        body: 'Elke inkomende webhook wordt geregistreerd met bron, topic en request-id. Bij een incident zie je direct welk event wanneer binnenkwam.',
    },
];

export function FeatureBento() {
    return (
        <section id="platform" className="px-page py-24 lg:py-section-x">
            <Reveal className="flex max-w-[820px] flex-col gap-4">
                <Eyebrow>04 — Platform</Eyebrow>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-3xl">
                    Alles wat een koppeling in productie nodig heeft.
                </h2>
            </Reveal>

            <RevealGroup className="mt-12 flex flex-col gap-6">
                <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
                    {rowOne.map((cell) => (
                        <BentoCell key={cell.index} cell={cell} />
                    ))}
                </div>
                <div className="grid gap-6 md:grid-cols-3">
                    {rowTwo.map((cell) => (
                        <BentoCell key={cell.index} cell={cell} />
                    ))}
                </div>
            </RevealGroup>
        </section>
    );
}

function BentoCell({ cell }: { cell: Cell }) {
    return (
        <RevealItem
            className={cn(
                'flex flex-col gap-4 rounded-lg border border-border px-[30px] py-8 transition-colors duration-150 hover:border-brand',
                cell.hero ? 'bg-brand-subtle' : 'bg-card',
            )}
        >
            <div className="flex items-center justify-between gap-4">
                <span className="font-mono text-xs tracking-[1px] text-muted-foreground">{cell.index}</span>
                <span className="rounded-xs bg-muted px-2.5 py-1 font-mono text-xs text-muted-foreground">{cell.tag}</span>
            </div>
            <h3 className="text-lg font-semibold text-foreground">{cell.title}</h3>
            <p className="text-md leading-[1.6] text-muted-foreground">{cell.body}</p>
            {cell.hero && <Schematic />}
        </RevealItem>
    );
}

/** CONSUMER ··· EMEQ HUB ··· PARTNER API — het pass-through-verhaal in één regel. */
function Schematic() {
    return (
        <div className="hidden items-center border-t border-border pt-[22px] font-mono text-2xs tracking-[0.5px] sm:flex">
            <span className="shrink-0 border border-border bg-background px-3.5 py-[9px] text-muted-foreground">CONSUMER</span>
            <Dots />
            <span className="shrink-0 border border-brand bg-brand-soft px-3.5 py-[9px] text-brand">EMEQ HUB</span>
            <Dots />
            <span className="shrink-0 border border-border bg-background px-3.5 py-[9px] text-muted-foreground">PARTNER API</span>
        </div>
    );
}

function Dots() {
    return (
        <span aria-hidden className="flex flex-1 items-center justify-center gap-1.5">
            {Array.from({ length: 5 }, (_, i) => (
                <span key={i} className="size-[3px] rounded-pill bg-border" />
            ))}
        </span>
    );
}
