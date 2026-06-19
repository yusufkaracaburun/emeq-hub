import type { LucideIcon } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { cn } from '@/lib/utils';

type FeatureCardProps = PropsWithChildren<{
    icon: LucideIcon;
    title: string;
    className?: string;
}>;

export function FeatureCard({ icon: Icon, title, children, className }: FeatureCardProps) {
    return (
        <div
            className={cn(
                'group relative h-full rounded-2xl border bg-card p-6 shadow-sm shadow-amber-500/5 transition-all hover:-translate-y-0.5 hover:border-amber-500/50 hover:shadow-lg hover:shadow-amber-500/10',
                className,
            )}
        >
            <div className="flex size-11 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 ring-1 ring-amber-500/20 dark:text-amber-400">
                <Icon className="size-5" />
            </div>
            <h3 className="mt-4 text-base font-semibold">{title}</h3>
            <p className="mt-2 text-sm text-muted-foreground">{children}</p>
        </div>
    );
}
