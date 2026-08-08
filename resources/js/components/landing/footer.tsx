import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';

const links = [
    { label: 'Partners', href: '/partners' },
    { label: 'Privacy', href: '/privacy' },
    { label: 'Voorwaarden', href: '/voorwaarden' },
    { label: 'Verwerkersovereenkomst', href: '/verwerkersovereenkomst' },
    { label: 'Support', href: '/support' },
];

const products = [
    { name: 'Emeq', domain: 'emeq.nl', href: 'https://emeq.nl', description: 'Software-studio achter de Hub.' },
    { name: 'Planny', domain: 'planny.nl', href: 'https://planny.nl', description: 'Planning en roosters voor teams.' },
];

export function Footer() {
    return (
        <footer className="border-t border-border bg-background px-6 pb-10 pt-14 lg:px-section-x">
            <div className="flex flex-col items-start gap-10 md:flex-row md:justify-between md:gap-12">
                <div className="flex max-w-[320px] flex-col gap-2.5">
                    <div className="flex items-center gap-2.5">
                        <span aria-hidden className="size-3.5 rounded-[3px] bg-brand" />
                        <span className="text-[22px] font-bold tracking-[-0.5px] text-foreground">emeq</span>
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

                <div className="flex flex-col gap-4">
                    <p className="text-sm font-medium tracking-[0.5px] text-muted-foreground">Ook van Emeq</p>
                    {products.map((product) => (
                        <a key={product.name} href={product.href} className="group flex flex-col gap-1">
                            <span className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                                {product.name}
                                <ArrowUpRight
                                    aria-hidden
                                    className="size-3 text-muted-foreground transition-colors duration-150 group-hover:text-foreground"
                                />
                            </span>
                            <span className="font-mono text-xs text-muted-foreground">{product.domain}</span>
                            <span className="text-xs2 text-muted-foreground">{product.description}</span>
                        </a>
                    ))}
                </div>
            </div>

            <div className="mt-7 flex items-center justify-between border-t border-border pt-6">
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
