import { type ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * Bespoke glyphs uit landingspage.pen — vervangen lucide integraal.
 * Functionele iconen zijn typografisch (TextGlyph); inhoudelijke iconen zijn
 * handgetekende SVG's met maximaal één brand-accent per glyph.
 */

interface TextGlyphProps extends ComponentProps<'span'> {
    glyph: '→' | '↗' | '›' | '▾' | '✕';
}

/** Typografische pijl/chevron — erft kleur en grootte van de omringende tekst. */
function TextGlyph({ glyph, className, ...props }: TextGlyphProps) {
    return (
        <span aria-hidden className={cn('leading-none', className)} {...props}>
            {glyph}
        </span>
    );
}

/** 40×40-tile met subtiel verloop waarin de feature-glyphs leven. */
function IconTile({ className, children, ...props }: ComponentProps<'div'>) {
    return (
        <div
            aria-hidden
            className={cn(
                'flex size-10 shrink-0 items-center justify-center rounded-[10px] border border-[#e6e6e6]',
                'bg-gradient-to-b from-white to-[#f4f4f4] shadow-[0_1px_2px_#0000000f]',
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}

type SvgProps = ComponentProps<'svg'>;

function Svg({ className, children, viewBox, ...props }: SvgProps) {
    return (
        <svg aria-hidden viewBox={viewBox} fill="none" xmlns="http://www.w3.org/2000/svg" className={className} {...props}>
            {children}
        </svg>
    );
}

/** Hangslot — beugel + massief slotlichaam met keyhole. */
function PadlockGlyph({ className, ...props }: SvgProps) {
    return (
        <Svg viewBox="0 0 20 20" className={cn('size-5', className)} {...props}>
            <path d="M6.8 10.5V6a3.2 3.2 0 0 1 6.4 0v4.5" stroke="currentColor" strokeWidth="1.6" />
            <rect x="3" y="9" width="14" height="9" rx="2.5" fill="currentColor" />
            <circle cx="10" cy="13.5" r="1.25" fill="var(--color-card)" />
        </Svg>
    );
}

/** Sleutel — ring, steel en twee tanden. */
function KeyGlyph({ className, ...props }: SvgProps) {
    return (
        <Svg viewBox="0 0 20 20" className={cn('size-5', className)} {...props}>
            <circle cx="5" cy="10" r="3.2" stroke="currentColor" strokeWidth="1.7" />
            <rect x="8.4" y="9.1" width="9.4" height="1.8" rx="0.9" fill="currentColor" />
            <rect x="13" y="10.5" width="1.8" height="3.5" rx="0.9" fill="currentColor" />
            <rect x="16.2" y="10.5" width="1.8" height="4.5" rx="0.9" fill="currentColor" />
        </Svg>
    );
}

/** Merge — twee nodes met een elleboog-verbinding. */
function MergeGlyph({ className, ...props }: SvgProps) {
    return (
        <Svg viewBox="0 0 20 20" className={cn('size-5', className)} {...props}>
            <circle cx="4" cy="3" r="2.6" stroke="currentColor" strokeWidth="1.7" />
            <path d="M4 5.6v5.4a4.5 4.5 0 0 0 4.5 4.5h4.4" stroke="currentColor" strokeWidth="1.8" />
            <circle cx="15.5" cy="15.5" r="2.6" stroke="currentColor" strokeWidth="1.7" />
        </Svg>
    );
}

/** Broadcast — bron-punt met twee uitdijende arcs. */
function BroadcastGlyph({ className, dotClassName, ...props }: SvgProps & { dotClassName?: string }) {
    return (
        <Svg viewBox="0 0 20 20" className={cn('size-5', className)} {...props}>
            <circle cx="8" cy="14" r="2.25" fill="currentColor" className={dotClassName} />
            <path d="M13.4 14A5.4 5.4 0 0 0 8 8.6" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <path d="M16.4 14A8.4 8.4 0 0 0 8 5.6" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
        </Svg>
    );
}

/** Factuurtje — velletje met tekstregels en één brand-bedragregel. */
function DocGlyph({ className, ...props }: SvgProps) {
    return (
        <Svg viewBox="0 0 18 18" className={cn('size-[18px]', className)} {...props}>
            <rect x="2.5" y="1" width="13" height="16" rx="2" fill="var(--color-card)" stroke="#c9c9c9" />
            <rect x="5.5" y="5.4" width="7" height="1.4" rx="0.7" fill="#cfcfcf" />
            <rect x="5.5" y="8.2" width="7" height="1.4" rx="0.7" fill="#cfcfcf" />
            <rect x="5.5" y="11.4" width="4.5" height="1.4" rx="0.7" fill="var(--color-brand)" />
        </Svg>
    );
}

/** Factuurtje met licht gekanteld boekingsstempel. */
function DocStampGlyph({ className, ...props }: SvgProps) {
    return (
        <Svg viewBox="0 0 18 18" className={cn('size-[18px]', className)} {...props}>
            <rect x="2.5" y="1" width="13" height="16" rx="2" fill="var(--color-card)" stroke="#c9c9c9" />
            <rect x="5.5" y="5.4" width="7" height="1.4" rx="0.7" fill="#cfcfcf" />
            <rect x="5.5" y="8.2" width="4.5" height="1.4" rx="0.7" fill="#cfcfcf" />
            <rect
                x="9.3"
                y="10.8"
                width="4"
                height="4"
                rx="1.2"
                fill="var(--color-brand-subtle)"
                stroke="var(--color-brand)"
                transform="rotate(8 11.3 12.8)"
            />
        </Svg>
    );
}

/** Betaalkaart — magstripe en brand-chip. */
function CardGlyph({ className, ...props }: SvgProps) {
    return (
        <Svg viewBox="0 0 18 18" className={cn('size-[18px]', className)} {...props}>
            <rect x="1" y="3" width="16" height="12" rx="2.5" fill="var(--color-card)" stroke="#c9c9c9" />
            <rect x="1.5" y="5.6" width="15" height="2.4" fill="#ececec" />
            <rect x="3.2" y="10" width="3.4" height="2.2" rx="1" fill="var(--color-brand)" />
        </Svg>
    );
}

/** Twee silhouetten — relaties/debiteuren-crediteuren. */
function UsersGlyph({ className, ...props }: SvgProps) {
    return (
        <Svg viewBox="0 0 18 18" className={cn('size-[18px]', className)} {...props}>
            <circle cx="12.25" cy="4.75" r="2.25" fill="#c7c7c7" />
            <path d="M8.5 12.5a4 4 0 0 1 8 0Z" fill="#c7c7c7" />
            <circle cx="6" cy="4" r="2.5" fill="currentColor" />
            <path d="M1.5 12a4.5 4.5 0 0 1 9 0Z" fill="currentColor" />
        </Svg>
    );
}

/** 2×2-tegelraster — grootboek/kostenplaatsen, één brand-accent-tegel. */
function GridGlyph({ className, ...props }: SvgProps) {
    return (
        <Svg viewBox="0 0 18 18" className={cn('size-[18px]', className)} {...props}>
            <rect x="2" y="2" width="6.5" height="6.5" rx="1.8" fill="#cfcfcf" />
            <rect x="9.5" y="2" width="6.5" height="6.5" rx="1.8" fill="#cfcfcf" />
            <rect x="2" y="9.5" width="6.5" height="6.5" rx="1.8" fill="#cfcfcf" />
            <rect x="9.5" y="9.5" width="6.5" height="6.5" rx="1.8" fill="var(--color-brand-subtle)" stroke="var(--color-brand)" strokeWidth="1.2" />
        </Svg>
    );
}

/** Typografisch procent-teken. */
function PctGlyph({ className, ...props }: ComponentProps<'span'>) {
    return (
        <span aria-hidden className={cn('text-[16px] font-medium leading-none', className)} {...props}>
            %
        </span>
    );
}

/** Massieve error-dot met uitroepteken — formulier-validatie. */
function AlertGlyph({ className, ...props }: SvgProps) {
    return (
        <Svg viewBox="0 0 14 14" className={cn('size-3.5', className)} {...props}>
            <circle cx="7" cy="7" r="7" fill="currentColor" />
            <rect x="6.1" y="3.2" width="1.8" height="5" rx="0.9" fill="var(--color-card)" />
            <rect x="6.1" y="9.7" width="1.8" height="1.8" rx="0.9" fill="var(--color-card)" />
        </Svg>
    );
}

/** Zachtgroene succescirkel met getekend vinkje. */
function CheckCircleGlyph({ className, ...props }: SvgProps) {
    return (
        <Svg viewBox="0 0 32 32" className={cn('size-8', className)} {...props}>
            <circle cx="16" cy="16" r="16" fill="var(--color-success-soft)" />
            <path d="M9.5 17l5 5 8.5-10" stroke="var(--color-success)" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" />
        </Svg>
    );
}

export {
    AlertGlyph,
    BroadcastGlyph,
    CardGlyph,
    CheckCircleGlyph,
    DocGlyph,
    DocStampGlyph,
    GridGlyph,
    IconTile,
    KeyGlyph,
    MergeGlyph,
    PadlockGlyph,
    PctGlyph,
    TextGlyph,
    UsersGlyph,
};
