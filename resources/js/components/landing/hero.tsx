import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ArrowRight } from 'lucide-react';
import { EASE } from '@/components/motion';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const enter = (delay: number) => ({
    initial: { opacity: 0, y: 14 },
    animate: { opacity: 1, y: 0 },
    transition: { duration: 0.5, delay, ease: EASE },
});

export function Hero() {
    return (
        <section className="relative overflow-hidden px-6 pb-24 pt-16 lg:px-section-x lg:pt-[96px]">
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
                        Integreer <span className="text-brand">zonder grenzen.</span>
                        <br />
                        Eén API voor alle <br className="hidden lg:block" />
                        externe systemen die <br className="hidden lg:block" />
                        je klanten gebruiken.
                    </motion.h1>

                    <motion.p {...enter(0.16)} className="text-lg leading-[1.6] text-muted-foreground">
                        Bouw productintegraties zonder maanden werk aan OAuth, tokenbeheer en webhooks. emeq is jouw
                        integratielaag; jij focust op product, klanten en groei.
                    </motion.p>

                    <motion.div {...enter(0.24)} className="flex flex-col gap-4 sm:flex-row sm:items-center">
                        <Link href="/koppelen" className={cn(buttonVariants({ variant: 'primary', size: 'lg' }), 'group')}>
                            Start met koppelen
                            <ArrowRight aria-hidden className="size-4 transition-transform duration-150 group-hover:translate-x-0.5" />
                        </Link>
                        <Link href="/demo" className={cn(buttonVariants({ variant: 'ghost', size: 'lg' }), 'group gap-1.5 px-2')}>
                            Demo aanvragen
                            <ArrowRight aria-hidden className="size-[15px] transition-transform duration-150 group-hover:translate-x-0.5" />
                        </Link>
                    </motion.div>
                </div>

                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.6, delay: 0.2, ease: EASE }}
                    className="relative flex-1 lg:max-w-[544px]"
                >
                    <CodeWindow />
                    <WebhookToast />
                </motion.div>
            </div>
        </section>
    );
}

function CodeWindow() {
    return (
        <div className="overflow-hidden rounded-lg border border-border bg-card shadow-card">
            <div className="flex items-center gap-4 border-b border-border bg-muted px-4 py-[13px]">
                <div aria-hidden className="flex items-center gap-2">
                    <span className="size-3 rounded-pill bg-border" />
                    <span className="size-3 rounded-pill bg-border" />
                    <span className="size-3 rounded-pill bg-border" />
                </div>
                <span className="font-mono text-xs text-muted-foreground">request.http</span>
            </div>

            <div className="flex flex-col gap-2 p-[22px] font-mono text-xs2">
                <p>
                    <span className="text-brand">POST</span>
                    <span className="text-foreground"> /v1/{'{provider}'}/resources</span>
                </p>
                <p className="text-muted-foreground">
                    Authorization: <span className="text-foreground">Bearer</span> ••••••••
                </p>
                <p aria-hidden className="h-2" />
                <p className="text-muted-foreground">{'{'}</p>
                <p className="text-muted-foreground">
                    {'  "connection_id": '}
                    <span className="text-foreground">"conn_7fK2…"</span>,
                </p>
                <p className="text-muted-foreground">{'  "payload": { … }'}</p>
                <p className="text-muted-foreground">{'}'}</p>
            </div>

            <div className="flex items-center gap-2 border-t border-border px-[22px] py-3 font-mono text-xs">
                <span aria-hidden className="size-2 rounded-pill bg-success" />
                <span className="text-success">200 OK</span>
                <span className="text-muted-foreground">· doorgestuurd · 142 ms</span>
            </div>
        </div>
    );
}

/** Webhook-event dat na de request-animatie "binnenkomt" — de tweede helft van het product. */
function WebhookToast() {
    return (
        <motion.div
            initial={{ opacity: 0, y: 10, scale: 0.96 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            transition={{ delay: 1.1, type: 'spring', stiffness: 320, damping: 24 }}
            className="absolute -left-3 bottom-6 flex flex-col gap-1 rounded-md border border-border bg-card px-4 py-3 shadow-[0_8px_28px_-8px_#0000001a] lg:-left-10"
        >
            <p className="flex items-center gap-2 font-mono text-xs font-medium text-foreground">
                <span aria-hidden className="size-[7px] rounded-pill bg-success" />
                webhook · invoice.created
            </p>
            <p className="font-mono text-2xs text-muted-foreground">→ doorgestuurd naar je app · 84 ms</p>
        </motion.div>
    );
}
