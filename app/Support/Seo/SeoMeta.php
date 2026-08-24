<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final class SeoMeta implements Arrayable, JsonSerializable
{
    /** @var list<array<string, mixed>> */
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

    /** @param  array<string, mixed>  ...$nodes */
    public function schema(array ...$nodes): self
    {
        foreach ($nodes as $node) {
            $this->graph[] = $node;
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title.' · '.config('app.name'),
            'description' => $this->description,
            'canonical' => $this->canonical,
            'type' => $this->type,
            'image' => $this->image ?? url('/og-image.png').'?v='.filemtime(public_path('og-image.png')),
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
