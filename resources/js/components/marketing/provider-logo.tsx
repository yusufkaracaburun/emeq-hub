import { cn } from '@/lib/utils';
import type { ProviderSummary } from '@/types';

// Witte logo-plate: merk-logo's lezen correct in licht én donker (Mollie's
// zwarte wordmark zou anders wegvallen op een donkere achtergrond) en
// respecteert de clearspace uit de huisstijl.
const plate = { sm: 'h-9 px-2.5', md: 'h-11 px-3', lg: 'h-16 px-4' } as const;
const logo = { sm: 'h-4', md: 'h-6', lg: 'h-8' } as const;
const tile = {
    sm: 'size-9 rounded-lg text-sm',
    md: 'size-11 rounded-xl text-base',
    lg: 'size-16 rounded-2xl text-2xl',
} as const;

type ProviderLogoProps = {
    provider: Pick<ProviderSummary, 'key' | 'label' | 'logo' | 'brand'>;
    size?: keyof typeof plate;
    className?: string;
};

/**
 * Toont het officiële SVG-logo op een witte plate. Zonder logo valt het terug
 * op een merk-veilige monogram-tegel (eerste letter).
 */
export function ProviderLogo({ provider, size = 'md', className }: ProviderLogoProps) {
    if (provider.logo) {
        return (
            <span
                className={cn(
                    'inline-flex items-center justify-center rounded-xl bg-white shadow-sm ring-1 ring-black/5',
                    plate[size],
                    className,
                )}
            >
                <img
                    src={provider.logo}
                    alt={`${provider.label} logo`}
                    loading="lazy"
                    className={cn('w-auto object-contain', logo[size])}
                />
            </span>
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-flex items-center justify-center border bg-gradient-to-br from-muted to-card font-semibold text-foreground/80 shadow-sm ring-1 ring-border/60',
                tile[size],
                className,
            )}
            style={provider.brand ? { color: provider.brand } : undefined}
        >
            {provider.label.charAt(0).toUpperCase()}
        </span>
    );
}
