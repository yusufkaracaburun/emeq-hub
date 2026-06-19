import { AppWindow, ArrowRight, Boxes, Plug } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

const NODES: { icon: LucideIcon; title: string; sub: string; highlight?: boolean }[] = [
    { icon: AppWindow, title: 'Jouw app', sub: 'Bearer-token + Account-id' },
    { icon: Boxes, title: 'emeq Hub', sub: 'OAuth · tokens · audit · routing', highlight: true },
    { icon: Plug, title: 'Partner-API', sub: 'Exact · Mollie · SnelStart' },
];

export function FlowDiagram() {
    return (
        <div className="flex flex-col items-stretch gap-4 sm:flex-row sm:items-center sm:justify-center">
            {NODES.map((node, i) => (
                <div key={node.title} className="contents">
                    <div
                        className={`flex flex-1 flex-col items-center gap-3 rounded-2xl border p-6 text-center ${
                            node.highlight ? 'border-amber-500/40 bg-amber-500/5 ring-1 ring-amber-500/20' : 'bg-card'
                        }`}
                    >
                        <div
                            className={`flex size-12 items-center justify-center rounded-xl ${
                                node.highlight ? 'bg-amber-500 text-amber-950' : 'bg-muted text-foreground/70'
                            }`}
                        >
                            <node.icon className="size-6" />
                        </div>
                        <div>
                            <p className="font-semibold">{node.title}</p>
                            <p className="mt-0.5 text-xs text-muted-foreground">{node.sub}</p>
                        </div>
                    </div>
                    {i < NODES.length - 1 && (
                        <ArrowRight className="mx-auto size-5 shrink-0 rotate-90 text-muted-foreground sm:rotate-0" />
                    )}
                </div>
            ))}
        </div>
    );
}
