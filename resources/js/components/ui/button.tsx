import { cva, type VariantProps } from 'class-variance-authority';
import { type ComponentProps } from 'react';
import { TextGlyph } from '@/components/ui/glyphs';
import { cn } from '@/lib/utils';

/**
 * Button-schaal uit landingspage.pen: drie maten (sm/md/lg), drie varianten.
 * States volgen de "Interaction States"-note in het design:
 * hover primary → brand-deep (150ms), focus-ring $ring, disabled opacity 40%.
 */
const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 rounded-md font-semibold transition-colors duration-150 ease-out ' +
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ' +
        'disabled:pointer-events-none disabled:opacity-40 disabled:shadow-none',
    {
        variants: {
            variant: {
                primary: 'bg-primary text-primary-foreground shadow-btn hover:bg-brand-deep',
                outline: 'border border-border bg-card text-foreground hover:border-brand hover:bg-muted',
                ghost: 'text-foreground hover:text-brand',
            },
            size: {
                sm: 'px-4 py-[9px] text-sm',
                md: 'px-[22px] py-[13px] text-md',
                lg: 'px-[26px] py-[15px] text-md',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'md',
        },
    },
);

interface ButtonProps extends ComponentProps<'button'>, VariantProps<typeof buttonVariants> {
    withArrow?: boolean;
}

function Button({ className, variant, size, withArrow = false, children, ...props }: ButtonProps) {
    return (
        <button className={cn(buttonVariants({ variant, size }), className)} {...props}>
            {children}
            {withArrow && <TextGlyph glyph="→" className="transition-transform duration-150 group-hover:translate-x-0.5" />}
        </button>
    );
}

export { Button, buttonVariants, type ButtonProps };
