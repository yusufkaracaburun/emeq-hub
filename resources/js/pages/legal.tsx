import { Head } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import ShowcaseLayout from '@/layouts/showcase-layout';

interface LegalProps {
    title: string;
    html: string;
    updatedAt: string;
}

export default function Legal({ title, html, updatedAt }: LegalProps) {
    return (
        <ShowcaseLayout>
            <Head title={title} />

            <section className="mx-auto max-w-3xl px-4 py-16 sm:py-24">
                {updatedAt && (
                    <p className="mb-8 text-sm text-muted-foreground">Laatst bijgewerkt: {updatedAt}</p>
                )}

                <article
                    className={cn(
                        'max-w-none',
                        '[&_h1]:mb-6 [&_h1]:text-3xl [&_h1]:font-bold [&_h1]:tracking-tight [&_h1]:text-foreground',
                        '[&_h2]:mt-10 [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-foreground',
                        '[&_p]:mb-4 [&_p]:leading-relaxed [&_p]:text-muted-foreground',
                        '[&_ul]:mb-4 [&_ul]:list-disc [&_ul]:pl-6 [&_li]:mb-1 [&_li]:text-muted-foreground',
                        '[&_a]:font-medium [&_a]:text-amber-600 [&_a]:underline hover:[&_a]:text-amber-500',
                        '[&_strong]:font-semibold [&_strong]:text-foreground',
                        '[&_blockquote]:my-4 [&_blockquote]:border-l-2 [&_blockquote]:border-amber-500 [&_blockquote]:pl-4 [&_blockquote]:text-muted-foreground [&_blockquote]:italic',
                        '[&_table]:my-4 [&_table]:w-full [&_table]:text-sm',
                        '[&_th]:border-b [&_th]:py-2 [&_th]:pr-4 [&_th]:text-left [&_th]:font-semibold [&_th]:text-foreground',
                        '[&_td]:border-t [&_td]:py-2 [&_td]:pr-4 [&_td]:text-muted-foreground',
                    )}
                    dangerouslySetInnerHTML={{ __html: html }}
                />
            </section>
        </ShowcaseLayout>
    );
}
