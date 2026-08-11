<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Canonieke relatie: een debiteur (klant) of crediteur (leverancier).
 *
 * Eén type met een `role`, geen twee klassen. Zowel Exact als Moneybird kennen
 * één relatie-entiteit met rolvlaggen, en het canonieke {@see Party} op de
 * schrijfzijde doet het al net zo. `/customers` en `/suppliers` zijn twee
 * ingangen op dezelfde vorm.
 *
 * Een relatie kan beide rollen hebben; `roles` draagt dat, `role` is de rol
 * waarop gefilterd werd.
 */
final readonly class Relation
{
    public const ROLE_DEBTOR = 'debtor';

    public const ROLE_CREDITOR = 'creditor';

    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $roles,
        public ?string $code = null,
        public ?string $vatNumber = null,
        public ?string $email = null,
        public array $attributes = [],
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'roles' => $this->roles,
            'code' => $this->code,
            'vat_number' => $this->vatNumber,
            'email' => $this->email,
            'attributes' => (object) $this->attributes,
        ];
    }
}
