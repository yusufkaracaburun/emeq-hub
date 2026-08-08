import { Link } from '@inertiajs/react';
import { TextGlyph } from '@/components/ui/glyphs';

const links = [
    { label: 'Partners', href: '/partners' },
    { label: 'Privacy', href: '/privacy' },
    { label: 'Voorwaarden', href: '/voorwaarden' },
    { label: 'Verwerkersovereenkomst', href: '/verwerkersovereenkomst' },
    { label: 'Support', href: '/support' },
];

const products = [
    { name: 'Emeq', href: 'https://emeq.nl' },
    { name: 'Planny', href: 'https://planny.nl' },
];

/** Eénregelige footer voor de intake-pagina's (/koppelen en /demo) — volgt de compacte Footer uit die design-frames. */
export function SimpleFooter() {
    return (
        <footer className="flex flex-col gap-4 border-t border-border px-6 py-7 text-xs2 text-muted-foreground md:flex-row md:items-center md:justify-between md:gap-6 lg:px-section-x">
            <p>© 2026 emeq</p>
            <nav className="flex flex-wrap gap-6">
                {links.map((link) => (
                    <Link
                        key={link.label}
                        href={link.href}
                        className="transition-colors duration-150 hover:text-foreground"
                    >
                        {link.label}
                    </Link>
                ))}
            </nav>
        </footer>
    );
}

export function Footer() {
    return (
        <footer className="border-t border-border bg-background px-6 pb-10 pt-14 lg:px-section-x">
            <div className="flex flex-col items-start gap-10 md:flex-row md:justify-between md:gap-12">
                <div className="flex flex-col gap-2">
                    <div className="flex items-center gap-2">
                        <span aria-hidden className="size-3.5 rounded-xs bg-brand" />
                        <span className="text-[24px] font-bold tracking-[-0.5px] text-foreground">hub</span>
                    </div>
                    <p className="text-sm text-muted-foreground">De integratie-API voor software die wil doorgroeien.</p>
                </div>

                <nav className="flex flex-col items-start gap-3">
                    {links.map((link) => (
                        <Link
                            key={link.label}
                            href={link.href}
                            className="text-sm font-medium text-muted-foreground transition-colors duration-150 hover:text-foreground"
                        >
                            {link.label}
                        </Link>
                    ))}
                </nav>

                <div className="flex flex-col gap-3">
                    <p className="text-sm font-medium tracking-[0.5px] text-muted-foreground">Ook van Emeq</p>
                    {products.map((product) => (
                        <a
                            key={product.name}
                            href={product.href}
                            className="group flex items-center gap-1.5 text-sm font-medium text-foreground"
                        >
                            {product.name}
                            <TextGlyph
                                glyph="↗"
                                className="text-xs text-muted-foreground transition-colors duration-150 group-hover:text-foreground"
                            />
                        </a>
                    ))}
                </div>
            </div>

            <div className="mt-6 flex items-center justify-between border-t border-border pt-6">
                <p className="font-mono text-xs text-muted-foreground">© 2026 emeq. Alle rechten voorbehouden.</p>
                <div className="flex items-center gap-2">
                    <span className="flex items-center justify-center rounded-xs border border-border p-1.5">
                        <img src="/img/badges/iso27001.png" alt="ISO 27001" className="size-[18px] opacity-70" />
                    </span>
                    <span className="flex items-center justify-center rounded-xs border border-border p-1.5">
                        <img src="/img/badges/gdpr.png" alt="GDPR" className="size-[18px] opacity-70" />
                    </span>
                </div>
            </div>
        </footer>
    );
}
