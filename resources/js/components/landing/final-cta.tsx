import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Reveal } from '@/components/motion';
import { buttonVariants } from '@/components/ui/button';
import { TextGlyph } from '@/components/ui/glyphs';
import { cn } from '@/lib/utils';

export function FinalCta() {
    return (
        <section className="relative overflow-hidden border-t border-border bg-brand-subtle px-6 py-24 lg:px-section-x lg:py-32">
            <ConnectPattern />

            <Reveal className="relative mx-auto flex max-w-[640px] flex-col items-center gap-6 text-center">
                <p className="font-mono text-xs2 uppercase tracking-[1.5px] text-muted-foreground">Start vandaag</p>
                <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-display md:tracking-[-2px]">
                    Klaar om <span className="text-brand">sneller te integreren?</span>
                </h2>
                <p className="max-w-[560px] text-lg leading-[1.6] text-muted-foreground">
                    Maak van koppelingen een groeiversneller in plaats van een ontwikkelproject. Start vandaag met Emeq Hub.
                </p>
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <Link href="/koppelen" className={cn(buttonVariants({ variant: 'primary', size: 'lg' }), 'group')}>
                        Start met koppelen
                        <TextGlyph glyph="→" className="transition-transform duration-150 group-hover:translate-x-0.5" />
                    </Link>
                    <Link href="/demo" className={cn(buttonVariants({ variant: 'ghost', size: 'lg' }), 'group gap-1.5 px-2')}>
                        Demo aanvragen
                        <TextGlyph glyph="→" className="text-[15px] transition-transform duration-150 group-hover:translate-x-0.5" />
                    </Link>
                </div>
            </Reveal>
        </section>
    );
}

const connectPaths = [
    'M 0,110 C 280,110 480,250 660,278',
    'M 0,253 C 300,253 500,280 660,288',
    'M 0,400 C 280,400 480,330 660,298',
    'M 1440,110 C 1160,110 960,250 780,278',
    'M 1440,253 C 1140,253 940,280 780,288',
    'M 1440,400 C 1160,400 960,330 780,298',
];

/**
 * Connectielijnen uit het design: systemen die van beide kanten naar de CTA
 * toe linken. Geometrie 1:1 uit landingspage.pen (Connect Lines/Nodes).
 * De lijnen tekenen zichzelf bij scroll-in; de endpoint-dots pulseren zacht.
 */
function ConnectPattern() {
    return (
        <svg
            aria-hidden
            viewBox="0 0 1440 507"
            preserveAspectRatio="xMidYMid slice"
            className="pointer-events-none absolute inset-0 hidden h-full w-full lg:block"
        >
            <g className="stroke-brand" strokeWidth="1" fill="none" opacity="0.13">
                {connectPaths.map((d, index) => (
                    <motion.path
                        key={d}
                        d={d}
                        initial={{ pathLength: 0 }}
                        whileInView={{ pathLength: 1 }}
                        viewport={{ once: true, margin: '-80px' }}
                        transition={{ duration: 1.4, delay: index * 0.1, ease: 'easeOut' }}
                    />
                ))}
            </g>
            <g className="fill-brand">
                {[
                    [10, 110],
                    [10, 253],
                    [10, 400],
                    [1430, 110],
                    [1430, 253],
                    [1430, 400],
                ].map(([cx, cy], index) => (
                    <motion.circle
                        key={`${cx}-${cy}`}
                        cx={cx}
                        cy={cy}
                        r={3}
                        initial={{ opacity: 0.25 }}
                        animate={{ opacity: [0.25, 0.55, 0.25] }}
                        transition={{ duration: 2.6, delay: index * 0.35, repeat: Infinity, ease: 'easeInOut' }}
                    />
                ))}
                {[
                    [668, 278],
                    [668, 288],
                    [668, 298],
                    [772, 278],
                    [772, 288],
                    [772, 298],
                ].map(([cx, cy]) => (
                    <circle key={`${cx}-${cy}`} cx={cx} cy={cy} r={2} opacity={0.25} />
                ))}
            </g>
        </svg>
    );
}
