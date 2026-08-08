import { Link } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { Menu, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const links = [
    { label: 'Waarom', href: '/#waarom' },
    { label: 'Zo werkt het', href: '/#hoe-het-werkt' },
    { label: 'Platform', href: '/#platform' },
];

/** Rustige transparante balk; de onderrand verschijnt pas bij scroll. */
export function Nav() {
    const [scrolled, setScrolled] = useState(false);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 8);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <header
            className={cn(
                'sticky top-0 z-40 border-b bg-background/85 backdrop-blur transition-colors duration-150',
                scrolled ? 'border-border' : 'border-transparent',
            )}
        >
            <div className="flex items-center justify-between px-6 py-[18px] lg:px-section-x">
                <div className="flex items-center gap-10">
                    <Link href="/" className="flex items-center gap-2" aria-label="emeq home">
                        <span aria-hidden className="size-3.5 rounded-xs bg-brand" />
                        <span className="text-[24px] font-bold tracking-[-0.5px] text-foreground">emeq</span>
                    </Link>
                    <nav className="hidden items-center gap-6 md:flex">
                        {links.map((link) => (
                            <a
                                key={link.label}
                                href={link.href}
                                className="text-sm font-medium text-muted-foreground transition-colors duration-150 hover:text-foreground"
                            >
                                {link.label}
                            </a>
                        ))}
                    </nav>
                </div>

                <div className="hidden items-center gap-4 md:flex">
                    <Link href="/demo" className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
                        Demo aanvragen
                    </Link>
                    <Link href="/koppelen" className={buttonVariants({ variant: 'primary', size: 'sm' })}>
                        Koppelen
                    </Link>
                </div>

                <button
                    type="button"
                    className="text-foreground md:hidden"
                    aria-label={open ? 'Menu sluiten' : 'Menu openen'}
                    onClick={() => setOpen((v) => !v)}
                >
                    {open ? <X className="size-5" /> : <Menu className="size-5" />}
                </button>
            </div>

            <AnimatePresence>
                {open && (
                    <motion.nav
                        initial={{ opacity: 0, height: 0 }}
                        animate={{ opacity: 1, height: 'auto' }}
                        exit={{ opacity: 0, height: 0 }}
                        transition={{ duration: 0.2, ease: 'easeOut' }}
                        className="overflow-hidden border-t border-border bg-background md:hidden"
                    >
                        <div className="flex flex-col px-6 pb-8 pt-10">
                            {links.map((link, index) => (
                                <a
                                    key={link.label}
                                    href={link.href}
                                    onClick={() => setOpen(false)}
                                    className="flex items-baseline gap-4 border-b border-border py-[22px] text-2xl font-bold tracking-[-1px] text-foreground"
                                >
                                    <span className="font-mono text-xs2 font-normal text-muted-foreground">{`0${index + 1}`}</span>
                                    {link.label}
                                </a>
                            ))}
                            <div className="flex flex-col gap-6 pt-8">
                                <div className="flex flex-col gap-3">
                                    <Link href="/koppelen" className={buttonVariants({ variant: 'primary', size: 'md' })}>
                                        Start met koppelen
                                    </Link>
                                    <Link href="/demo" className={buttonVariants({ variant: 'outline', size: 'md' })}>
                                        Demo aanvragen
                                    </Link>
                                </div>
                                <p className="font-mono text-2xs text-muted-foreground">© 2026 emeq</p>
                            </div>
                        </div>
                    </motion.nav>
                )}
            </AnimatePresence>
        </header>
    );
}
