import { type ComponentProps } from 'react';
import { cn } from '@/lib/utils';

interface StepProps extends ComponentProps<'div'> {
    number: number;
    title: string;
}

/** Step (component kKpAT): outline-nummercirkel, titel, gedempte beschrijving. */
function Step({ className, number, title, children, ...props }: StepProps) {
    return (
        <div className={cn('flex flex-col gap-4', className)} {...props}>
            <span
                aria-hidden
                className="flex size-11 items-center justify-center rounded-pill border border-border bg-card font-sans text-lg font-bold tracking-[-0.5px] text-muted-foreground"
            >
                {number}
            </span>
            <h3 className="text-lg font-semibold text-foreground">{title}</h3>
            <p className="text-sm text-muted-foreground">{children}</p>
        </div>
    );
}

export { Step };
