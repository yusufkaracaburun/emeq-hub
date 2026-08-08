<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Server-side SEO/GEO-payload voor één publieke pagina. Reist als Inertia-prop
 * mee en wordt in de <Head> gerenderd — server-side via SSR (crawlers zonder
 * JS), client-side bij SPA-navigatie. PHP is daarmee de enige bron voor titel,
 * meta-tags en JSON-LD; de React-laag formatteert alleen.
 *
 * @implements Arrayable<string, mixed>
 */
final class SeoMeta implements Arrayable, JsonSerializable
{
    /**
     * Extra schema.org-nodes bovenop de site-brede Organization/WebSite.
     *
     * @var list<array<string, mixed>>
     */
    private array $graph = [];

    private string $type = 'website';

    private ?string $image = null;

    private function __construct(
        private readonly string $title,
        private readonly string $description,
        private readonly string $canonical,
    ) {}

    public static function make(string $title, string $description, ?string $canonical = null): self
    {
        return new self($title, $description, $canonical ?? url()->current());
    }

    /**
     * OpenGraph-type: 'website' voor overzichten, 'article' voor tekstpagina's
     * met een wijzigingsdatum (juridische documenten).
     */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function image(string $image): self
    {
        $this->image = $image;

        return $this;
    }

    /**
     * @param  array<string, mixed>  ...$nodes
     */
    public function schema(array ...$nodes): self
    {
        foreach ($nodes as $node) {
            $this->graph[] = $node;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title.' · '.config('app.name'),
            'description' => $this->description,
            'canonical' => $this->canonical,
            'type' => $this->type,
            'image' => $this->image ?? url('/og-image.png'),
            'locale' => 'nl_NL',
            'siteName' => config('app.name'),
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@graph' => [
                    Schema::organization(),
                    Schema::website(),
                    ...$this->graph,
                ],
            ],
        ];
    }
}
