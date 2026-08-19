<?php

declare(strict_types=1);

namespace App\Support\Seo;

final class Schema
{
    public const ORGANIZATION_ID = '#organization';

    public const WEBSITE_ID = '#website';

    /** @return array<string, mixed> */
    public static function organization(): array
    {
        return [
            '@type' => 'Organization',
            '@id' => url('/').self::ORGANIZATION_ID,
            'name' => config('app.name'),
            'url' => url('/'),
            'logo' => url('/favicon.svg'),
            'description' => 'Integratieplatform dat externe koppelingen (boekhouden, betalen en meer) '
                .'aanbiedt als één API, inclusief OAuth, tokenbeheer en webhooks.',
            'email' => 'info@emeq.nl',
            'areaServed' => 'NL',
            'sameAs' => [
                'https://emeq.nl',
                'https://planny.nl',
            ],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'email' => 'support@emeq.nl',
                'availableLanguage' => ['nl', 'en'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function website(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => url('/').self::WEBSITE_ID,
            'url' => url('/'),
            'name' => config('app.name'),
            'inLanguage' => 'nl-NL',
            'publisher' => ['@id' => url('/').self::ORGANIZATION_ID],
        ];
    }

    /**
     * @param  list<array{name:string,url:string}>  $items
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $items): array
    {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                fn (array $item, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'item' => $item['url'],
                ],
                $items,
                array_keys($items),
            ),
        ];
    }

    /**
     * @param  list<string>  $features
     * @return array<string, mixed>
     */
    public static function integration(string $name, string $description, string $url, array $features): array
    {
        return [
            '@type' => 'SoftwareApplication',
            'name' => $name,
            'description' => $description,
            'url' => $url,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'featureList' => $features,
            'provider' => ['@id' => url('/').self::ORGANIZATION_ID],
            'offers' => [
                '@type' => 'Offer',
                'availability' => 'https://schema.org/InStock',
                'priceCurrency' => 'EUR',
                'price' => '0',
                'description' => 'Prijs op aanvraag, afhankelijk van je volume.',
            ],
        ];
    }

    /**
     * @param  list<array{question:string,answer:string}>  $entries
     * @return array<string, mixed>
     */
    public static function faq(array $entries): array
    {
        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $entry): array => [
                '@type' => 'Question',
                'name' => $entry['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $entry['answer'],
                ],
            ], $entries),
        ];
    }

    /** @return array<string, mixed> */
    public static function legalPage(string $name, string $url, ?string $dateModified): array
    {
        return array_filter([
            '@type' => 'WebPage',
            'name' => $name,
            'url' => $url,
            'inLanguage' => 'nl-NL',
            'dateModified' => $dateModified,
            'isPartOf' => ['@id' => url('/').self::WEBSITE_ID],
            'publisher' => ['@id' => url('/').self::ORGANIZATION_ID],
        ]);
    }
}
