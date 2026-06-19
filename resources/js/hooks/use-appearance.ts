import { useCallback, useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const prefersDark = (): boolean =>
    typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches;

function applyAppearance(appearance: Appearance): void {
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());
    document.documentElement.classList.toggle('dark', isDark);
}

/**
 * Light/dark/system met localStorage-persistentie. De eerste paint wordt al door
 * het inline-script in app.blade.php gezet; deze hook houdt het in sync en volgt
 * systeem-wissels zolang de keuze 'system' is.
 */
export function useAppearance() {
    const [appearance, setAppearanceState] = useState<Appearance>('system');

    const setAppearance = useCallback((value: Appearance): void => {
        setAppearanceState(value);
        localStorage.setItem('appearance', value);
        applyAppearance(value);
    }, []);

    useEffect(() => {
        const stored = (localStorage.getItem('appearance') as Appearance | null) ?? 'system';
        setAppearanceState(stored);
        applyAppearance(stored);

        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = (): void => {
            if (((localStorage.getItem('appearance') as Appearance | null) ?? 'system') === 'system') {
                applyAppearance('system');
            }
        };
        media.addEventListener('change', onChange);

        return () => media.removeEventListener('change', onChange);
    }, []);

    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());

    return { appearance, setAppearance, isDark } as const;
}
