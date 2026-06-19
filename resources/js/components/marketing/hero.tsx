import type { PropsWithChildren } from 'react';
import { cn } from '@/lib/utils';

/**
 * Hero-omhulsel met amber-glow mesh + fijn raster. Pagina's leveren de inhoud.
 */
export function HeroShell({ children, className }: PropsWithChildren<{ className?: string }>) {
    return (
        <section className={cn('relative isolate overflow-hidden', className)}>
            <div className="bg-hero-mesh pointer-events-none absolute inset-0 -z-10" />
            <div className="bg-grid pointer-events-none absolute inset-0 -z-10" />
            <div className="mx-auto max-w-6xl px-4 py-20 sm:py-28">{children}</div>
        </section>
    );
}
