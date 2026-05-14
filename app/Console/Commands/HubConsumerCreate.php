<?php

namespace App\Console\Commands;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class HubConsumerCreate extends Command
{
    protected $signature = 'hub:consumer:create
                            {--slug= : Unieke slug (kebab-case identifier)}
                            {--name= : Vrije weergave-naam}
                            {--abilities=* : Comma-separated of meermaals (default: *)}
                            {--token-name=cli-default : Naam van het PAT-record}';

    protected $description = 'Maak een Consumer + Personal Access Token aan vanaf de CLI';

    public function handle(): int
    {
        $slug = (string) $this->option('slug');
        $name = (string) $this->option('name');

        if ($slug === '' || $name === '') {
            $this->error('--slug en --name zijn verplicht.');

            return self::INVALID;
        }

        try {
            $consumer = Consumer::create(['slug' => $slug, 'name' => $name]);
        } catch (QueryException $e) {
            $this->error("Aanmaken Consumer mislukt: {$e->getMessage()}");

            return self::FAILURE;
        }

        $abilities = $this->resolveAbilities();
        $tokenName = (string) $this->option('token-name');
        $token = $consumer->createToken($tokenName, $abilities);

        $this->info("Consumer aangemaakt: id={$consumer->id}, slug={$consumer->slug}");
        $this->info("Token name: {$tokenName}");
        $this->info('Abilities: '.implode(', ', $abilities));
        $this->warn("Plain-text token (toon eenmalig): {$token->plainTextToken}");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveAbilities(): array
    {
        /** @var list<string> $raw */
        $raw = (array) $this->option('abilities');

        if ($raw === []) {
            return [TokenAbilities::ADMIN];
        }

        return collect($raw)
            ->flatMap(fn (string $item): array => explode(',', $item))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all();
    }
}
