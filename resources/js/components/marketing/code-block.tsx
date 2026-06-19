import { Check, Copy, Terminal } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

export function CodeBlock({ code, label = 'Voorbeeld', className }: { code: string; label?: string; className?: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async (): Promise<void> => {
        try {
            await navigator.clipboard.writeText(code);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard geweigerd (geen https/permissie) — stil negeren, code blijft selecteerbaar.
        }
    };

    return (
        <div className={cn('overflow-hidden rounded-xl border border-white/10 bg-neutral-950 shadow-sm', className)}>
            <div className="flex items-center justify-between border-b border-white/10 px-4 py-2.5">
                <span className="inline-flex items-center gap-2 text-xs font-medium text-neutral-300">
                    <Terminal className="size-3.5" /> {label}
                </span>
                <button
                    type="button"
                    onClick={copy}
                    aria-label="Kopieer naar klembord"
                    className="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-neutral-400 transition-colors hover:bg-white/10 hover:text-neutral-100"
                >
                    {copied ? <Check className="size-3.5 text-emerald-400" /> : <Copy className="size-3.5" />}
                    {copied ? 'Gekopieerd' : 'Kopieer'}
                </button>
            </div>
            <pre className="overflow-x-auto p-4 text-sm leading-relaxed text-neutral-100">
                <code>{code}</code>
            </pre>
        </div>
    );
}
