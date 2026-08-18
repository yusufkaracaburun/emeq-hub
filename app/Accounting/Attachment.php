<?php

declare(strict_types=1);

namespace App\Accounting;

final readonly class Attachment
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $content,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            filename: (string) $data['filename'],
            mimeType: (string) $data['mime_type'],
            content: (string) $data['content'],
        );
    }
}
