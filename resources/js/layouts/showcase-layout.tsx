import { Link, usePage } from '@inertiajs/react';
import { Boxes, Menu, X } from 'lucide-react';
import { useState } from 'react';
import type { PropsWithChildren } from 'react';
import { ThemeToggle } from '@/components/theme-toggle';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

const NAV = [
    { label: 'Home', href: '/' },
    { label: 'Integraties', href: '/partners' },
    { label: 'API-docs', href: '/docs/api', external: true },
];

export default function ShowcaseLayout({ children }: PropsWithChildren) {
    const { appName } = usePage<SharedProps>().props;
    const currentPath = usePage().url;
    const [open, setOpen] = useState(false);

    const isActive = (href: string): boolean =>
        href === '/' ? currentPath === '/' : currentPath.startsWith(href);

    return (
        <div className="flex min-h-dvh flex-col">
            <header className="sticky top-0 z-40 border-b bg-background/80 backdrop-blur">
                <nav className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
                    <Link href="/" className="flex items-center gap-2 font-bold">
                        <Boxes className="size-5 text-amber-500" />
                        {appName}
                    </Link>

                    <div className="hidden items-center gap-1 md:flex">
                        {NAV.map((item) =>
                            item.external ? (
                                <a
                                    key={item.href}
                                    href={item.href}
                                    className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    {item.label}
                                </a>
                            ) : (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={cn(
                                        'rounded-md px-3 py-2 text-sm font-medium transition-colors hover:text-foreground',
                                        isActive(item.href) ? 'text-foreground' : 'text-muted-foreground',
                                    )}
                                >
                                    {item.label}
                                </Link>
                            ),
                        )}
                    </div>

                    <div className="flex items-center gap-1">
                        <ThemeToggle />
                        <Button asChild size="sm" className="hidden sm:inline-flex">
                            <Link href="/partners">Koppelen</Link>
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            className="md:hidden"
                            aria-label={open ? 'Sluit menu' : 'Open menu'}
                            aria-expanded={open}
                            onClick={() => setOpen((v) => !v)}
                        >
                            {open ? <X /> : <Menu />}
                        </Button>
                    </div>
                </nav>

                {open && (
                    <div className="border-t md:hidden">
                        <div className="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-3">
                            {NAV.map((item) => (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    onClick={() => setOpen(false)}
                                    className={cn(
                                        'rounded-md px-3 py-2 text-sm font-medium',
                                        isActive(item.href) ? 'bg-accent text-foreground' : 'text-muted-foreground',
                                    )}
                                >
                                    {item.label}
                                </Link>
                            ))}
                        </div>
                    </div>
                )}
            </header>

            <main id="main" className="flex-1">{children}</main>

            <footer className="border-t">
                <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-10 text-sm text-muted-foreground sm:flex-row">
                    <p className="flex items-center gap-2">
                        <Boxes className="size-4 text-amber-500" />© {new Date().getFullYear()} {appName} — integratieplatform voor NL boekhoud- en betaal-API's.
                    </p>
                    <div className="flex gap-4">
                        <a href="https://emeq.nl/privacy-policy/" className="transition-colors hover:text-foreground">
                            Privacy
                        </a>
                        <a href="https://emeq.nl/algemene-voorwaarden/" className="transition-colors hover:text-foreground">
                            Voorwaarden
                        </a>
                        <a href="mailto:support@emeq.nl" className="transition-colors hover:text-foreground">
                            Support
                        </a>
                    </div>
                </div>
            </footer>
        </div>
    );
}
