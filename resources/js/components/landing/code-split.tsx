import { Reveal, RevealGroup, RevealItem } from '@/components/motion';
import { Eyebrow } from '@/components/ui/eyebrow';
import { cn } from '@/lib/utils';

const auditRows = [
    { provider: 'provider-a', topic: 'invoice.created', ok: true, status: '201' },
    { provider: 'provider-b', topic: 'order.synced', ok: true, status: '200' },
    { provider: 'provider-a', topic: 'contact.updated', ok: true, status: '200' },
    { provider: 'provider-c', topic: 'document.posted', ok: false, status: '422' },
    { provider: 'provider-b', topic: 'ledger.synced', ok: true, status: '201' },
];

export function CodeSplit() {
    return (
        <section className="px-page py-24 lg:py-section-x">
            <Reveal className="flex max-w-[820px] flex-col gap-4">
                <Eyebrow>03 · Audit &amp; transparantie</Eyebrow>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-3xl">
                    Elke API-call staat in de audit-log.
                </h2>
                <p className="max-w-[640px] text-base leading-[1.6] text-muted-foreground">
                    Van elke request leggen we provider, status en timing vast. Belt een klant met een probleem, dan
                    zoek je de call gewoon op.
                </p>
            </Reveal>

            <RevealGroup className="mt-12 grid gap-8 lg:grid-cols-2">
                <RevealItem>
                    <PassthroughWindow />
                </RevealItem>
                <RevealItem>
                    <AuditView />
                </RevealItem>
            </RevealGroup>
        </section>
    );
}

function PassthroughWindow() {
    return (
        <div className="overflow-hidden rounded-lg border border-border bg-card shadow-card">
            <div className="flex items-center gap-4 border-b border-border bg-muted px-4 py-[13px]">
                <div aria-hidden className="flex items-center gap-2">
                    <span className="size-3 rounded-pill bg-border" />
                    <span className="size-3 rounded-pill bg-border" />
                    <span className="size-3 rounded-pill bg-border" />
                </div>
                <span className="font-mono text-xs text-muted-foreground">passthrough.http</span>
            </div>

            <div className="flex flex-col gap-2 p-[22px] font-mono text-xs2">
                <p>
                    <span className="text-brand">GET</span>
                    <span className="text-foreground"> /v1/{'{provider}'}/resources</span>
                </p>
                <p className="text-muted-foreground">
                    Authorization: <span className="text-foreground">Bearer</span> ••••••••
                </p>
                <p className="text-muted-foreground">
                    Idempotency-Key: <span className="text-foreground">a1f9c2e7</span>
                </p>
                <p aria-hidden className="h-2" />
                <p className="text-muted-foreground"># → doorgestuurd naar de partner-API</p>
                <p className="text-muted-foreground">
                    # ← <span className="text-success">200 OK</span> · gelogd in de audit-log
                </p>
            </div>
        </div>
    );
}

function AuditView() {
    return (
        <div className="overflow-hidden rounded-lg border border-border bg-card">
            <div className="flex items-center gap-2 border-b border-border bg-muted px-[18px] py-[15px] font-mono text-xs2">
                <span aria-hidden className="size-2 rounded-pill bg-success" />
                <span className="text-foreground">audit-log · live</span>
            </div>

            <div className="grid grid-cols-[1fr_1fr_78px_56px] gap-3 border-b border-border px-[18px] py-2.5 font-mono text-2xs uppercase tracking-[1.5px] text-muted-foreground">
                <span>Provider</span>
                <span>Topic</span>
                <span>Outcome</span>
                <span>Status</span>
            </div>

            {auditRows.map((row) => (
                <div
                    key={`${row.provider}-${row.topic}`}
                    className="grid grid-cols-[1fr_1fr_78px_56px] items-center gap-3 border-b border-border px-[18px] py-3 font-mono text-xs2 last:border-b-0"
                >
                    <span className="text-foreground">{row.provider}</span>
                    <span className="truncate text-muted-foreground">{row.topic}</span>
                    <span
                        className={cn(
                            'inline-flex w-fit items-center gap-1 rounded-xs px-2 py-[3px] text-2xs',
                            row.ok ? 'bg-success-soft text-success' : 'bg-error-soft text-error',
                        )}
                    >
                        <span aria-hidden className={cn('size-1.5 rounded-pill', row.ok ? 'bg-success' : 'bg-error')} />
                        {row.ok ? 'ok' : 'failed'}
                    </span>
                    <span className={row.ok ? 'text-foreground' : 'text-error'}>{row.status}</span>
                </div>
            ))}
        </div>
    );
}
