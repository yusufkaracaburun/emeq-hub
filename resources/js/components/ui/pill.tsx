import { type ComponentProps, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface PillProps extends ComponentProps<'span'> {
    icon?: ReactNode;
    /** Statusdot vóór het label (bijv. groen voor live). */
    dot?: 'success' | 'error';
}

/** Borderless $muted-pill — het badge-pattern uit de security band. */
function Pill({ className, icon, dot, children, ...props }: PillProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-2 rounded-pill bg-muted px-4 py-2.5 font-mono text-xs2 text-foreground',
                className,
            )}
            {...props}
        >
            {dot && (
                <span
                    aria-hidden
                    className={cn('size-1.5 rounded-pill', dot === 'success' ? 'bg-success' : 'bg-error')}
                />
            )}
            {icon}
            {children}
        </span>
    );
}

export { Pill };
