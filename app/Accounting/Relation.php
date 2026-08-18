<?php

declare(strict_types=1);

namespace App\Accounting;

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

    /** @return array<string, mixed> */
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
