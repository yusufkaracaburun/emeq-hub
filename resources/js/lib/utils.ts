import { clsx, type ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

/**
 * tailwind-merge kent onze eigen type-scale niet: `text-xs2` valt buiten zijn
 * font-size-validator en wordt dan als tekstkleur geclassificeerd — waarna een
 * échte kleur ('text-foreground') 'm als "conflict" wegmergt en de tekst
 * terugvalt op 16px. De hele scale registreren houdt maat en kleur gescheiden.
 */
const twMerge = extendTailwindMerge({
    extend: {
        classGroups: {
            'font-size': [{ text: ['2xs', 'xs2', 'md', 'display'] }],
        },
    },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
