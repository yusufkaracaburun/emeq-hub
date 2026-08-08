import { type ComponentProps, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface FeatureCardProps extends ComponentProps<'div'> {
    icon: ReactNode;
    title: string;
}

/**
 * Feature Card (component aCSlG): hairline card, 18px-icoon inline naast de
 * titel, gedempte beschrijving. Hover: border → brand, fill → muted (states-note).
 */
function FeatureCard({ className, icon, title, children, ...props }: FeatureCardProps) {
    return (
        <div
            className={cn(
                'flex min-h-[148px] flex-col gap-2.5 rounded-lg border border-border bg-card p-6',
                'transition-colors duration-150 hover:border-brand hover:bg-muted',
                className,
            )}
            {...props}
        >
            <div className="flex items-center gap-2.5">
                <span aria-hidden className="text-muted-foreground [&>svg]:size-[18px]">
                    {icon}
                </span>
                <h3 className="text-base font-semibold text-foreground">{title}</h3>
            </div>
            <p className="text-sm text-muted-foreground">{children}</p>
        </div>
    );
}

export { FeatureCard };
