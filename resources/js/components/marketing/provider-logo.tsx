import { cn } from '@/lib/utils';
import type { ProviderSummary } from '@/types';

const sizes = {
    sm: 'size-9 rounded-lg text-sm',
    md: 'size-12 rounded-xl text-base',
    lg: 'size-16 rounded-2xl text-2xl',
} as const;

type ProviderLogoProps = {
    provider: Pick<ProviderSummary, 'key' | 'label' | 'logo' | 'brand'>;
    size?: keyof typeof sizes;
    className?: string;
};

/**
 * Toont het officiële SVG-logo wanneer config het pad levert. Zo niet, dan een
 * merk-veilige monogram-tegel (eerste letter) — geen broken image, geen
 * misleidende namaak. Drop het echte logo in public/img/partners/ en zet
 * 'logo' in config/partner-showcase.php om het te vervangen.
 */
export function ProviderLogo({ provider, size = 'md', className }: ProviderLogoProps) {
    if (provider.logo) {
        return (
            <img
                src={provider.logo}
                alt={`${provider.label} logo`}
                loading="lazy"
                className={cn('object-contain', sizes[size], className)}
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-flex items-center justify-center border bg-gradient-to-br from-muted to-card font-semibold text-foreground/80 shadow-sm ring-1 ring-border/60',
                sizes[size],
                className,
            )}
            style={provider.brand ? { color: provider.brand } : undefined}
        >
            {provider.label.charAt(0).toUpperCase()}
        </span>
    );
}
