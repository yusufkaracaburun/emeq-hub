import { type ComponentProps, type ReactNode } from 'react';
import { IconTile } from '@/components/ui/glyphs';
import { cn } from '@/lib/utils';

interface FeatureCardProps extends ComponentProps<'div'> {
    glyph: ReactNode;
    tag?: string;
    title: string;
}

/**
 * Feature Card v2 (landingspage.pen): glyph-tile met mono resource-tag in de
 * kop, titel en gedempte beschrijving eronder. Hover: border → brand.
 */
function FeatureCard({ className, glyph, tag, title, children, ...props }: FeatureCardProps) {
    return (
        <div
            className={cn(
                'flex flex-col gap-4 rounded-lg border border-border bg-card p-6',
                'transition-colors duration-150 hover:border-brand',
                className,
            )}
            {...props}
        >
            <div className="flex items-center justify-between">
                <IconTile className="text-muted-foreground">{glyph}</IconTile>
                {tag && (
                    <span className="rounded-pill bg-brand-subtle px-2 py-[3px] font-mono text-2xs font-medium tracking-[0.3px] text-brand">
                        {tag}
                    </span>
                )}
            </div>
            <div className="flex flex-col gap-1.5">
                <h3 className="text-base font-semibold text-foreground">{title}</h3>
                <p className="text-sm leading-[1.6] text-muted-foreground">{children}</p>
            </div>
        </div>
    );
}

export { FeatureCard };
