import { motion, useInView, type Variants } from 'framer-motion';
import { useRef, type ComponentProps, type ReactNode } from 'react';

/**
 * Motion-systeem voor de publieke pagina's. Ingetogen en snel, passend bij het
 * design: fade + 14px lift, 0.5s, eenmalig bij in-view scrollen. Reduced-motion
 * wordt gerespecteerd via <MotionConfig reducedMotion="user"> in de page-shell.
 */
const EASE = [0.21, 0.47, 0.32, 0.98] as const;

const fadeUp: Variants = {
    hidden: { opacity: 0, y: 14 },
    visible: { opacity: 1, y: 0, transition: { duration: 0.5, ease: EASE } },
};

interface RevealProps extends ComponentProps<typeof motion.div> {
    children?: ReactNode;
    delay?: number;
}

/** Eén element dat in-view infadet. */
function Reveal({ children, delay = 0, ...props }: RevealProps) {
    return (
        <motion.div
            initial="hidden"
            whileInView="visible"
            viewport={{ once: true, margin: '-80px' }}
            variants={{
                hidden: { opacity: 0, y: 14 },
                visible: { opacity: 1, y: 0, transition: { duration: 0.5, delay, ease: EASE } },
            }}
            {...props}
        >
            {children}
        </motion.div>
    );
}

/**
 * Container die zijn RevealItem-children met 80ms stagger toont. Gebruikt
 * `animate` in plaats van `whileInView`, zodat children die later mounten
 * (bijvoorbeeld na een filterwissel) de zichtbare variant alsnog erven.
 */
function RevealGroup({ children, ...props }: ComponentProps<typeof motion.div>) {
    const ref = useRef<HTMLDivElement>(null);
    const inView = useInView(ref, { once: true, margin: '-80px' });

    return (
        <motion.div
            ref={ref}
            initial="hidden"
            animate={inView ? 'visible' : 'hidden'}
            variants={{ visible: { transition: { staggerChildren: 0.08 } } }}
            {...props}
        >
            {children}
        </motion.div>
    );
}

function RevealItem({ children, ...props }: ComponentProps<typeof motion.div>) {
    return (
        <motion.div variants={fadeUp} {...props}>
            {children}
        </motion.div>
    );
}

export { EASE, fadeUp, Reveal, RevealGroup, RevealItem };
