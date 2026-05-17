<?php

namespace App\Console\Commands;

use App\Sanctum\TokenAbilities;
use App\Services\ConsumerOnboarding;
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

    public function handle(ConsumerOnboarding $onboarding): int
    {
        $slug = (string) $this->option('slug');
        $name = (string) $this->option('name');

        if ($slug === '' || $name === '') {
            $this->error('--slug en --name zijn verplicht.');

            return self::INVALID;
        }

        $abilities = $this->resolveAbilities();
        $invalid = array_values(array_diff($abilities, TokenAbilities::all()));

        if ($invalid !== []) {
            $this->error('Onbekende abilities: '.implode(', ', $invalid));
            $this->line('Geldige abilities: '.implode(', ', TokenAbilities::all()));

            return self::INVALID;
        }

        $tokenName = (string) $this->option('token-name');

        try {
            $result = $onboarding->onboard([
                'name' => $name,
                'slug' => $slug,
                'token_name' => $tokenName,
                'abilities' => $abilities,
            ]);
        } catch (QueryException $e) {
            $this->error("Aanmaken Consumer mislukt: {$e->getMessage()}");

            return self::FAILURE;
        }

        $consumer = $result['consumer'];

        $this->info("Consumer aangemaakt: id={$consumer->id}, slug={$consumer->slug}");
        $this->info("Token name: {$tokenName}");
        $this->info('Abilities: '.implode(', ', $abilities));
        $this->warn("Plain-text token (toon eenmalig): {$result['plain_token']}");

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
