<?php

declare(strict_types=1);

namespace App\Accounting\Validation;

/**
 * Eén bevinding over een geëxtraheerd draft-document. De Hub flagt + stelt voor,
 * maar muteert nooit: `current` is de aangeleverde waarde, `suggestion` een concrete
 * voorgestelde correctie (of null als er geen veilige suggestie is). De consumer past
 * een suggestie pas toe na bevestiging door de gebruiker.
 *
 * `severity` en `blocking` beantwoorden andere vragen en kantelen niet in elkaars
 * betekenis: `severity` is hoe ernstig de bevinding is, `blocking` is of de boek-POST
 * dit document weigert. Elke error is per definitie blocking; een warning kan beide
 * kanten op (bv. `exact.relation.new` blokkeert zonder auto-create, niet mét). Geen
 * default — elke aanroepplek beslist bewust, zodat een nieuwe finding nooit stilzwijgend
 * verkeerd geclassificeerd blijft staan.
 */
final readonly class Finding
{
    public function __construct(
        public string $code,
        public Severity $severity,
        public bool $blocking,
        public string $path,
        public string $message,
        public mixed $current = null,
        public mixed $suggestion = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'blocking' => $this->blocking,
            'path' => $this->path,
            'message' => $this->message,
            'current' => $this->current,
            'suggestion' => $this->suggestion,
        ];
    }
}
