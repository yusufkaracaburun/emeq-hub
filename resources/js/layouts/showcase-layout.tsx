import { Link, usePage } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import type { SharedProps } from '@/types';

export default function ShowcaseLayout({ children }: PropsWithChildren) {
    const { appName } = usePage<SharedProps>().props;

    return (
        <div className="flex min-h-screen flex-col">
            <header className="border-b">
                <nav className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
                    <Link href="/partners" className="flex items-center gap-2 font-bold">
                        <Boxes className="size-5 text-amber-500" />
                        {appName}
                    </Link>
                    <span className="text-sm text-muted-foreground">Integraties</span>
                </nav>
            </header>

            <main className="flex-1">{children}</main>

            <footer className="border-t">
                <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-10 text-sm text-muted-foreground sm:flex-row">
                    <p>© {appName} — integratieplatform voor NL boekhoud- en betaal-API's.</p>
                    <div className="flex gap-4">
                        <a href="https://emeq.nl/privacy-policy/" className="hover:text-foreground">
                            Privacy
                        </a>
                        <a href="https://emeq.nl/algemene-voorwaarden/" className="hover:text-foreground">
                            Voorwaarden
                        </a>
                        <a href="mailto:support@emeq.nl" className="hover:text-foreground">
                            Support
                        </a>
                    </div>
                </div>
            </footer>
        </div>
    );
}
