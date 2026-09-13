import { MotionConfig } from 'framer-motion';
import { useEffect, useRef, useState, type RefObject } from 'react';
import { SimpleFooter } from '@/components/landing/footer';
import { Nav } from '@/components/landing/nav';
import { Reveal } from '@/components/motion';
import { Seo } from '@/components/seo';
import { Eyebrow } from '@/components/ui/eyebrow';
import { cn } from '@/lib/utils';
import { type SeoMeta } from '@/lib/types';

interface TocEntry {
    id: string;
    text: string;
    level: 2 | 3;
}

interface DocPageProps {
    eyebrow: string;
    title: string;
    html: string;
    updatedAt: string;
    seo: SeoMeta;
    /** Externe link naast de meta-regel, bv. de live API-reference. */
    headerAction?: { label: string; href: string };
    /** Genummerde h2-secties (het juridische contract-patroon). */
    numbered?: boolean;
    /** Sticky sidebar-navigatie, opgebouwd uit de echte headings in de body. */
    toc?: boolean;
}

function slugify(text: string): string {
    return text
        .toLowerCase()
        .trim()
        .replace(/[^\p{Letter}\p{Number}]+/gu, '-')
        .replace(/^-+|-+$/g, '');
}

/** Injecteert een kopieerknop boven elk codeblok in de gerenderde markdown-HTML. */
function useCodeCopyButtons(containerRef: RefObject<HTMLDivElement | null>, html: string) {
    useEffect(() => {
        const container = containerRef.current;
        if (!container) {
            return;
        }

        const blocks = container.querySelectorAll<HTMLPreElement>('pre:not([data-copy-ready])');

        blocks.forEach((pre) => {
            pre.dataset.copyReady = 'true';
            pre.classList.add('relative', 'group/code');

            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = 'Kopieer';
            button.className =
                'absolute right-3 top-3 rounded-sm border border-border/60 bg-card/80 px-2.5 py-1 font-mono text-2xs text-muted-foreground ' +
                'opacity-0 backdrop-blur transition-opacity duration-150 hover:border-brand hover:text-foreground group-hover/code:opacity-100 focus-visible:opacity-100';

            button.addEventListener('click', () => {
                const code = pre.querySelector('code')?.textContent ?? pre.textContent ?? '';
                navigator.clipboard.writeText(code).then(() => {
                    button.textContent = 'Gekopieerd';
                    window.setTimeout(() => {
                        button.textContent = 'Kopieer';
                    }, 1500);
                });
            });

            pre.appendChild(button);
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [html]);
}

/** Verzamelt h2/h3 uit de gerenderde body, kent er zelf id's aan toe, en volgt scrollpositie. */
function useToc(containerRef: RefObject<HTMLDivElement | null>, html: string, enabled: boolean) {
    const [entries, setEntries] = useState<TocEntry[]>([]);
    const [activeId, setActiveId] = useState<string | null>(null);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const container = containerRef.current;
        if (!container) {
            return;
        }

        const headings = Array.from(container.querySelectorAll<HTMLHeadingElement>('h2, h3'));
        const seen = new Set<string>();

        const collected = headings.map((heading) => {
            let id = heading.id || slugify(heading.textContent ?? '');
            while (seen.has(id)) {
                id = `${id}-2`;
            }
            seen.add(id);
            heading.id = id;

            return {
                id,
                text: heading.textContent ?? '',
                level: heading.tagName === 'H3' ? 3 : 2,
            } satisfies TocEntry;
        });

        setEntries(collected);

        // Scrollspy op "laatste heading die de trigger-lijn passeerde", met
        // vooraf berekende document-offsets — een live getBoundingClientRect
        // per scroll-frame is niet nodig en herhaald opvragen tijdens scroll
        // op een zeer lange pagina bleek onbetrouwbaar.
        const TRIGGER_LINE = 110;
        const offsets = headings.map((heading) => ({
            id: heading.id,
            top: heading.getBoundingClientRect().top + window.scrollY,
        }));

        const updateActive = () => {
            let current: string | null = offsets[0]?.id ?? null;
            const line = window.scrollY + TRIGGER_LINE;
            for (const offset of offsets) {
                if (offset.top <= line) {
                    current = offset.id;
                } else {
                    break;
                }
            }
            setActiveId(current);
        };

        updateActive();

        let ticking = false;
        const onScroll = () => {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(() => {
                updateActive();
                ticking = false;
            });
        };

        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, html]);

    return { entries, activeId };
}

export function DocPage({ eyebrow, title, html, updatedAt, seo, headerAction, numbered = false, toc = false }: DocPageProps) {
    const articleRef = useRef<HTMLDivElement>(null);
    useCodeCopyButtons(articleRef, html);
    const { entries, activeId } = useToc(articleRef, html, toc);

    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <Nav />
            <main className="relative px-page pb-24 pt-16 lg:pt-20">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 top-0 h-[360px] opacity-30 [background-image:radial-gradient(circle,#17171720_1px,transparent_1px)] [background-size:24px_24px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
                />

                <div className={cn('relative mx-auto flex flex-col gap-10', toc ? 'max-w-[1040px] lg:flex-row lg:gap-16' : 'max-w-[800px]')}>
                    <div className={cn('flex min-w-0 flex-1 flex-col gap-10', toc && 'lg:max-w-[760px]')}>
                        <Reveal className="flex flex-col gap-4">
                            <Eyebrow>
                                {eyebrow} · {title}
                            </Eyebrow>
                            <h1 className="text-2xl font-bold leading-[1.05] tracking-[-1px] text-foreground md:text-display md:tracking-[-2px]">
                                {title}
                            </h1>
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 font-mono text-xs2 text-muted-foreground">
                                <span>Laatst bijgewerkt: {updatedAt}</span>
                                {headerAction && (
                                    <a
                                        href={headerAction.href}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="font-medium text-foreground underline underline-offset-4 hover:text-brand"
                                    >
                                        {headerAction.label} →
                                    </a>
                                )}
                            </div>
                        </Reveal>

                        <Reveal delay={0.1}>
                            <div
                                ref={articleRef}
                                className={cn(
                                    'prose-docs text-md leading-[1.6] text-muted-foreground [scroll-behavior:smooth]',
                                    numbered && '[counter-reset:legal]',
                                    '[&_a]:font-medium [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4 hover:[&_a]:text-brand ' +
                                        '[&_blockquote]:my-5 [&_blockquote]:rounded-md [&_blockquote]:border [&_blockquote]:border-l-2 [&_blockquote]:border-border [&_blockquote]:border-l-brand [&_blockquote]:bg-card [&_blockquote]:px-6 [&_blockquote]:py-5 [&_blockquote]:font-mono [&_blockquote]:text-xs2 [&_blockquote_p]:mt-0 ' +
                                        (numbered
                                            ? '[&_h2]:relative [&_h2]:mt-6 [&_h2]:scroll-mt-24 [&_h2]:border-t [&_h2]:border-border [&_h2]:pl-10 [&_h2]:pt-6 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-foreground [&_h2]:[counter-increment:legal] ' +
                                              '[&_h2:first-child]:mt-0 [&_h2:first-child]:border-t-0 [&_h2:first-child]:pt-0 ' +
                                              "[&_h2]:before:absolute [&_h2]:before:left-0 [&_h2]:before:font-mono [&_h2]:before:text-xs2 [&_h2]:before:font-normal [&_h2]:before:text-brand [&_h2]:before:content-[counter(legal,decimal-leading-zero)] " +
                                              '[&_h3]:mt-6 [&_h3]:scroll-mt-24 [&_h3]:pl-10 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-foreground ' +
                                              '[&_p]:pl-10 [&_table]:ml-10 [&_table]:w-[calc(100%-40px)]'
                                            : '[&_h2]:mt-10 [&_h2]:scroll-mt-24 [&_h2]:border-t [&_h2]:border-border [&_h2]:pt-6 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-foreground ' +
                                              '[&_h2:first-child]:mt-0 [&_h2:first-child]:border-t-0 [&_h2:first-child]:pt-0 ' +
                                              '[&_h3]:mt-6 [&_h3]:scroll-mt-24 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-foreground ' +
                                              '[&_table]:w-full') +
                                        ' [&_li]:mt-1.5 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:pl-6 ' +
                                        '[&_p]:mt-3 [&_strong]:font-semibold [&_strong]:text-foreground ' +
                                        '[&_pre]:my-4 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:border [&_pre]:border-border [&_pre]:bg-[#141414] [&_pre]:p-4 [&_pre]:pr-20 [&_pre]:text-xs2 [&_pre]:leading-[1.6] [&_pre]:text-[#e8e8e8] ' +
                                        '[&_code]:font-mono [&_code]:text-[0.85em] ' +
                                        '[&_:not(pre)>code]:rounded [&_:not(pre)>code]:border [&_:not(pre)>code]:border-border [&_:not(pre)>code]:bg-card [&_:not(pre)>code]:px-1.5 [&_:not(pre)>code]:py-0.5 [&_:not(pre)>code]:text-foreground ' +
                                        '[&_table]:mt-5 [&_table]:border-collapse [&_td]:border-b [&_td]:border-border [&_td]:py-2.5 [&_td]:pr-4 [&_td]:align-top ' +
                                        '[&_th]:border-b [&_th]:py-2.5 [&_th]:pr-4 [&_th]:text-left [&_th]:font-mono [&_th]:text-2xs [&_th]:uppercase [&_th]:tracking-[1.5px] [&_th]:text-muted-foreground ' +
                                        '[&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-6',
                                )}
                                dangerouslySetInnerHTML={{ __html: html }}
                            />
                        </Reveal>
                    </div>

                    {toc && entries.length > 0 && (
                        <aside className="hidden shrink-0 lg:block lg:w-[220px]">
                            <nav className="sticky top-24 flex max-h-[calc(100dvh-120px)] flex-col gap-0.5 overflow-y-auto border-l border-border pl-5 text-xs2">
                                {entries.map((entry) => (
                                    <a
                                        key={entry.id}
                                        href={`#${entry.id}`}
                                        className={cn(
                                            'block truncate py-1 transition-colors duration-150',
                                            entry.level === 3 && 'pl-3',
                                            activeId === entry.id ? 'font-medium text-brand' : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {entry.text}
                                    </a>
                                ))}
                            </nav>
                        </aside>
                    )}
                </div>
            </main>
            <SimpleFooter />
        </MotionConfig>
    );
}
