import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment } from 'react';

interface Crumb {
    label: string;
    href?: string;
}

/** Kruimelpad boven de hero — mono 2xs, laatste item foreground (Breadcrumb-frames in landingspage.pen). */
function Breadcrumbs({ items }: { items: Crumb[] }) {
    return (
        <nav aria-label="Kruimelpad" className="flex items-center gap-2 font-mono text-2xs tracking-[0.5px]">
            {items.map((item, index) => {
                const last = index === items.length - 1;

                return (
                    <Fragment key={item.label}>
                        {index > 0 && <ChevronRight aria-hidden className="size-3 shrink-0 text-muted-foreground" />}
                        {item.href && !last ? (
                            <Link
                                href={item.href}
                                className="text-muted-foreground transition-colors duration-150 hover:text-foreground"
                            >
                                {item.label}
                            </Link>
                        ) : (
                            <span aria-current={last ? 'page' : undefined} className="text-foreground">
                                {item.label}
                            </span>
                        )}
                    </Fragment>
                );
            })}
        </nav>
    );
}

export { Breadcrumbs };
