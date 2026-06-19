import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';

type CtaSectionProps = {
    title: string;
    description?: string;
    primary: { label: string; href: string };
    secondary?: { label: string; href: string };
};

export function CtaSection({ title, description, primary, secondary }: CtaSectionProps) {
    return (
        <div className="relative overflow-hidden rounded-3xl border bg-card px-6 py-14 text-center sm:px-12">
            <div className="bg-hero-mesh pointer-events-none absolute inset-0" />
            <div className="relative mx-auto max-w-2xl">
                <h2 className="text-2xl font-bold tracking-tight text-balance sm:text-3xl">{title}</h2>
                {description && <p className="mt-3 text-base text-muted-foreground">{description}</p>}
                <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <Button asChild size="lg">
                        <Link href={primary.href}>
                            {primary.label}
                            <ArrowRight />
                        </Link>
                    </Button>
                    {secondary && (
                        <Button asChild size="lg" variant="outline">
                            <a href={secondary.href}>{secondary.label}</a>
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}
