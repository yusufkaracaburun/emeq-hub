import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Reveal } from '@/components/motion';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const categories = [
    { label: 'Boekhouden', live: true },
    { label: 'Betalen', live: false },
    { label: 'CRM', live: false },
    { label: 'E-commerce', live: false },
    { label: '+ meer', live: false },
];

export function IntegrationsTeaser() {
    return (
        <section className="px-6 pb-24 lg:px-section-x lg:pb-section-x">
            <Reveal className="flex flex-col items-start justify-between gap-10 rounded-xl border border-border bg-card p-8 lg:flex-row lg:items-center lg:px-12 lg:py-11">
                <div className="flex flex-col gap-5">
                    <h2 className="text-xl font-bold tracking-[-1px] text-foreground md:text-2xl">
                        Van je eerste koppeling tot een compleet ecosysteem.
                    </h2>
                    <div className="flex flex-wrap gap-3">
                        {categories.map((category) => (
                            <span
                                key={category.label}
                                className="inline-flex items-center gap-2 rounded-pill border border-border px-3.5 py-[7px] text-sm font-medium text-muted-foreground"
                            >
                                {category.live && <span aria-hidden className="size-1.5 rounded-pill bg-success" />}
                                <span className={category.live ? 'text-foreground' : undefined}>{category.label}</span>
                            </span>
                        ))}
                    </div>
                </div>

                <Link href="/koppelen" className={cn(buttonVariants({ variant: 'primary', size: 'lg' }), 'group shrink-0')}>
                    Start met koppelen
                    <ArrowRight aria-hidden className="size-4 transition-transform duration-150 group-hover:translate-x-0.5" />
                </Link>
            </Reveal>
        </section>
    );
}
