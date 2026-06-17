<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Bijlage op een FinancialDocument — bestandsnaam, mime-type en inline base64-inhoud.
 * Inline base64 (geen URL) zodat de POST self-contained blijft; de adapter uploadt 'm
 * ná de boeking naar het boekhoudpakket.
 */
final readonly class Attachment
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $content,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            filename: (string) $data['filename'],
            mimeType: (string) $data['mime_type'],
            content: (string) $data['content'],
        );
    }
}
