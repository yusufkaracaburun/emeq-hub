import { Flag, KeyRound, ScrollText, ShieldCheck } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

const DEFAULT_ITEMS: { icon: LucideIcon; label: string }[] = [
    { icon: ShieldCheck, label: 'Tokens encrypted at rest' },
    { icon: ScrollText, label: 'Audit-log per call' },
    { icon: KeyRound, label: 'Per-Connection scheiding' },
    { icon: Flag, label: 'Gebouwd voor NL-API’s' },
];

export function TrustBar({ className, items = DEFAULT_ITEMS }: { className?: string; items?: typeof DEFAULT_ITEMS }) {
    return (
        <ul className={cn('flex flex-wrap items-center justify-center gap-x-3 gap-y-3', className)}>
            {items.map(({ icon: Icon, label }) => (
                <li
                    key={label}
                    className="inline-flex items-center gap-2 rounded-full border bg-card/60 px-4 py-1.5 text-sm font-medium text-foreground/80 backdrop-blur"
                >
                    <Icon className="size-4 text-amber-500" />
                    {label}
                </li>
            ))}
        </ul>
    );
}
