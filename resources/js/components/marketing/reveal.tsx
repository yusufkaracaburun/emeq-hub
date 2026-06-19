import { motion, useReducedMotion } from 'framer-motion';
import type { PropsWithChildren } from 'react';

type RevealProps = PropsWithChildren<{
    delay?: number;
    className?: string;
    as?: 'div' | 'li' | 'section';
}>;

/**
 * Fade + rise zodra het element in beeld scrollt. Bij prefers-reduced-motion
 * (of framer's useReducedMotion) rendert het statisch — geen beweging.
 */
export function Reveal({ children, delay = 0, className, as = 'div' }: RevealProps) {
    const reduce = useReducedMotion();
    const Tag = motion[as];

    if (reduce) {
        const Static = as;
        return <Static className={className}>{children}</Static>;
    }

    return (
        <Tag
            className={className}
            initial={{ opacity: 0, y: 18 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, margin: '-80px' }}
            transition={{ duration: 0.5, delay, ease: [0.21, 0.47, 0.32, 0.98] }}
        >
            {children}
        </Tag>
    );
}
