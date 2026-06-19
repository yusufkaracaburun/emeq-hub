import { cn } from '@/lib/utils';

type SectionHeadingProps = {
    eyebrow?: string;
    title: string;
    description?: string;
    align?: 'left' | 'center';
    className?: string;
};

export function SectionHeading({ eyebrow, title, description, align = 'left', className }: SectionHeadingProps) {
    return (
        <div className={cn('max-w-2xl', align === 'center' && 'mx-auto text-center', className)}>
            {eyebrow && (
                <p className="text-sm font-semibold tracking-wide text-amber-600 uppercase dark:text-amber-400">{eyebrow}</p>
            )}
            <h2 className="mt-2 text-2xl font-bold tracking-tight text-balance sm:text-3xl">{title}</h2>
            {description && <p className="mt-3 text-base text-muted-foreground">{description}</p>}
        </div>
    );
}
