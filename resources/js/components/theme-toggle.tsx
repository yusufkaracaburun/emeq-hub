import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';

export function ThemeToggle({ className }: { className?: string }) {
    const { isDark, setAppearance } = useAppearance();

    return (
        <Button
            variant="ghost"
            size="icon-sm"
            className={className}
            aria-label={isDark ? 'Schakel naar lichte modus' : 'Schakel naar donkere modus'}
            onClick={() => setAppearance(isDark ? 'light' : 'dark')}
        >
            {isDark ? <Sun /> : <Moon />}
        </Button>
    );
}
