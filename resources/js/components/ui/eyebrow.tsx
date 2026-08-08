import { type ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Sectie-eyebrow: mono, uppercase, tracking 1.5, gedempt — nooit magenta. */
function Eyebrow({ className, ...props }: ComponentProps<'p'>) {
    return (
        <p
            className={cn('font-mono text-xs2 uppercase tracking-[1.5px] text-muted-foreground', className)}
            {...props}
        />
    );
}

export { Eyebrow };
