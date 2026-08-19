import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useEffect, useRef, useState } from 'react';
import { EASE } from '@/components/motion';
import { CardGlyph, DocGlyph, TextGlyph } from '@/components/ui/glyphs';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const enter = (delay: number) => ({
    initial: { opacity: 0, y: 14 },
    animate: { opacity: 1, y: 0 },
    transition: { duration: 0.5, delay, ease: EASE },
});

export function Hero() {
    return (
        <section className="relative overflow-hidden px-page pb-24 pt-16 lg:pt-[96px]">
            {/* Dotgrid — nauwelijks zichtbaar, alleen in de hero */}
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 opacity-30 [background-image:radial-gradient(circle,#17171720_1px,transparent_1px)] [background-size:24px_24px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
            />

            <div className="relative flex flex-col gap-14 lg:flex-row lg:gap-12">
                <div className="flex max-w-[600px] flex-col gap-6">
                    <motion.p {...enter(0)} className="font-mono text-xs2 uppercase tracking-[1.5px] text-muted-foreground">
                        Unified API · Integratieplatform
                    </motion.p>

                    <motion.h1
                        {...enter(0.08)}
                        className="text-2xl font-bold tracking-[-1px] text-foreground md:text-display md:leading-[1.13] md:tracking-[-2px]"
                    >
                        Eén API. <span className="text-brand">Elke koppeling.</span>
                        <br />
                        Voor de boekhoud- en <br className="hidden lg:block" />
                        betaalsystemen die <br className="hidden lg:block" />
                        je klanten gebruiken.
                    </motion.h1>

                    <motion.p {...enter(0.16)} className="text-lg leading-[1.6] text-muted-foreground">
                        Emeq Hub is de unified API voor Nederlandse boekhoud- en betaalkoppelingen. Wij verzorgen OAuth,
                        tokenbeheer en webhooks; jouw team bouwt verder aan het product.
                    </motion.p>

                    <motion.div {...enter(0.24)} className="flex flex-col gap-4 sm:flex-row sm:items-center">
                        <Link href="/koppelen" className={cn(buttonVariants({ variant: 'primary', size: 'lg' }), 'group')}>
                            Start met koppelen
                            <TextGlyph glyph="→" className="transition-transform duration-150 group-hover:translate-x-0.5" />
                        </Link>
                        <Link href="/demo" className={cn(buttonVariants({ variant: 'ghost', size: 'lg' }), 'group gap-1.5 px-2')}>
                            Demo aanvragen
                            <TextGlyph glyph="→" className="text-[15px] transition-transform duration-150 group-hover:translate-x-0.5" />
                        </Link>
                    </motion.div>
                </div>

                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.6, delay: 0.2, ease: EASE }}
                    className="relative flex-1 lg:max-w-[720px] lg:self-start"
                >
                    <HubDiagram />
                </motion.div>
            </div>
        </section>
    );
}

/**
 * Hub-diagram uit landingspage.pen: jouw software → emeq hub → partners.
 * Twee vaste geometrieën (342×256 en 552×284) die de canvas-frames 1:1 volgen;
 * vaste maten houden de lijnen en nodes intern uitgelijnd op elke viewport.
 */
/**
 * Schaalt een vaste diagram-geometrie mee met de containerbreedte. De inhoud
 * blijft intern pixel-vast (lijnen en nodes uitgelijnd); alleen de transform
 * verandert, dus alles blijft vector-scherp.
 */
function Scaled({ baseW, baseH, className, children }: { baseW: number; baseH: number; className?: string; children: React.ReactNode }) {
    const ref = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(1);

    useEffect(() => {
        const el = ref.current;
        if (!el) {
            return;
        }
        const observer = new ResizeObserver(([entry]) => {
            const width = entry.contentRect.width;
            if (width > 0) {
                setScale(width / baseW);
            }
        });
        observer.observe(el);
        return () => observer.disconnect();
    }, [baseW]);

    return (
        <div ref={ref} className={cn('w-full', className)} style={{ height: baseH * scale }}>
            <div
                className="relative"
                style={{ width: baseW, height: baseH, transform: `scale(${scale})`, transformOrigin: 'top left' }}
            >
                {children}
            </div>
        </div>
    );
}

function HubDiagram() {
    return (
        <>
            <Scaled baseW={342} baseH={256} className="lg:hidden">
                <motion.svg
                    aria-hidden
                    viewBox="0 0 342 256"
                    fill="none"
                    className="absolute inset-0"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ delay: 0.9, duration: 0.6, ease: EASE }}
                >
                    <circle cx="171" cy="128" r="78" stroke="#ebebeb" />
                    <circle cx="171" cy="128" r="50" stroke="#e3e3e3" />
                    <path d="M88 128h47M207 128h43" stroke="#dddddd" strokeWidth="1.5" />
                    <path d="M207 128c18 0 26-76 43-76M207 128c18 0 26 76 43 76" stroke="#dddddd" strokeWidth="1.5" />
                    <FlowDot from={90} to={133} cy={128} />
                </motion.svg>
                <DiagramCard delay={0.35} className="left-0 top-[108px] h-10 w-[88px]">
                    <p className="text-[11px] font-semibold text-foreground">Jouw app</p>
                </DiagramCard>
                <HubNode delay={0.5} className="left-[135px] top-[92px] size-[72px] rounded-2xl" logoClass="w-[26px]" />
                <DiagramCard delay={0.65} className="right-0 top-8 h-10 w-[92px] gap-1.5">
                    <DocGlyph className="size-3.5" />
                    <span className="text-[10px] font-semibold text-foreground">Boekhouden</span>
                </DiagramCard>
                <DiagramCard delay={0.75} className="right-0 top-[108px] h-10 w-[92px] gap-1.5">
                    <CardGlyph className="size-3.5" />
                    <span className="text-[10px] font-semibold text-foreground">Betalen</span>
                </DiagramCard>
                <DiagramCard delay={0.85} className="right-0 top-[184px] h-10 w-[92px]">
                    <span className="font-mono text-[10px] text-muted-foreground">+ meer</span>
                </DiagramCard>
            </Scaled>

            <Scaled baseW={552} baseH={284} className="hidden lg:block">
                <motion.svg
                    aria-hidden
                    viewBox="0 0 552 284"
                    fill="none"
                    className="absolute inset-0"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ delay: 0.9, duration: 0.6, ease: EASE }}
                >
                    <circle cx="276" cy="142" r="102" stroke="#ebebeb" />
                    <circle cx="276" cy="142" r="66" stroke="#e3e3e3" />
                    <path d="M148 142h80M324 142h96" stroke="#dddddd" strokeWidth="1.5" />
                    <path d="M324 142c40 0 56-82 96-82M324 142c40 0 56 82 96 82" stroke="#dddddd" strokeWidth="1.5" />
                    <FlowDot from={150} to={226} cy={142} />
                </motion.svg>
                <DiagramCard delay={0.35} className="left-2 top-28 h-[60px] w-[140px] flex-col gap-0.5">
                    <p className="text-[13px] font-semibold text-foreground">Jouw software</p>
                    <p className="font-mono text-[10px] text-muted-foreground">REST · JSON</p>
                </DiagramCard>
                <HubNode delay={0.5} className="left-[228px] top-[94px] size-24 rounded-[20px]" logoClass="w-10" />
                <DiagramCard delay={0.65} className="right-0 top-9 h-12 w-[124px] gap-2">
                    <DocGlyph className="size-4" />
                    <span className="text-xs font-semibold text-foreground">Boekhouden</span>
                </DiagramCard>
                <DiagramCard delay={0.75} className="right-0 top-[122px] h-12 w-[124px] gap-2">
                    <CardGlyph className="size-4" />
                    <span className="text-xs font-semibold text-foreground">Betalen</span>
                </DiagramCard>
                <DiagramCard delay={0.85} className="right-0 top-52 h-12 w-[124px]">
                    <span className="font-mono text-[11px] text-muted-foreground">+ meer partners</span>
                </DiagramCard>
            </Scaled>
        </>
    );
}

/** Brand-dot die herhaald over de consumer→hub-lijn loopt. */
function FlowDot({ from, to, cy }: { from: number; to: number; cy: number }) {
    return (
        <motion.circle
            cy={cy}
            r={2.25}
            fill="var(--color-brand)"
            initial={{ cx: from, opacity: 0 }}
            animate={{ cx: [from, to], opacity: [0, 1, 1, 0] }}
            transition={{ delay: 1.4, duration: 2, times: [0, 0.15, 0.85, 1], repeat: Infinity, repeatDelay: 0.8, ease: 'linear' }}
        />
    );
}

const pop = (delay: number) => ({
    initial: { opacity: 0, y: 8, scale: 0.97 },
    animate: { opacity: 1, y: 0, scale: 1 },
    transition: { delay, duration: 0.45, ease: EASE },
});

function DiagramCard({ delay, className, children }: { delay: number; className?: string; children: React.ReactNode }) {
    return (
        <motion.div
            {...pop(delay)}
            className={cn(
                'absolute flex items-center justify-center rounded-lg border border-border bg-card shadow-[0_2px_8px_-2px_#0000000f]',
                className,
            )}
        >
            {children}
        </motion.div>
    );
}

function HubNode({ delay, className, logoClass }: { delay: number; className: string; logoClass: string }) {
    return (
        <motion.div
            {...pop(delay)}
            className={cn(
                'absolute flex items-center justify-center border border-border bg-card shadow-[0_8px_24px_-6px_#00000014]',
                className,
            )}
        >
            <img src="/img/logo.png" alt="Emeq Hub" className={cn('h-auto', logoClass)} />
        </motion.div>
    );
}

