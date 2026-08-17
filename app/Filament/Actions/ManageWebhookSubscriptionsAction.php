<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\Provider;
use App\Integrations\Exact\ExactWebhookSubscriptionManager;
use App\Models\Connection;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class ManageWebhookSubscriptionsAction
{
    public static function make(): Action
    {
        return Action::make('webhookSubscriptions')
            ->label('Webhooks')
            ->icon(Heroicon::OutlinedBell)
            ->modalHeading('Webhook-abonnementen')
            ->modalDescription('Bepaalt waarover Exact deze koppeling een seintje stuurt. Een vinkje aan maakt het abonnement bij Exact aan, een vinkje uit zegt het daar ook echt op.')
            ->modalSubmitActionLabel('Opslaan')
            ->visible(fn (Connection $record): bool => $record->provider === Provider::Exact
                && $record->revoked_at === null)
            ->fillForm(fn (Connection $record): array => ['topics' => self::currentState($record)])
            ->schema(fn (Connection $record): array => self::schemaFor($record))
            ->action(function (array $data, Connection $record): void {
                $selected = array_keys(array_filter((array) ($data['topics'] ?? [])));

                try {
                    $result = app(ExactWebhookSubscriptionManager::class)->apply($record, array_values($selected));
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Exact weigerde de wijziging')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Webhook-abonnementen bijgewerkt')
                    ->body(self::summarise($result))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return list<Section>
     */
    private static function schemaFor(Connection $record): array
    {
        [$plan, $error] = self::plan($record);

        $toggles = [];

        foreach (self::offeredTopics($plan, $record) as $topic) {
            $toggles[] = Toggle::make("topics.{$topic}")
                ->label($topic)
                ->helperText(self::helperFor($topic, $plan));
        }

        if ($toggles === []) {
            $toggles[] = Toggle::make('topics.__none')
                ->label('Geen topics beschikbaar')
                ->disabled()
                ->helperText('Er zijn geen geconfigureerde topics en Exact heeft er ook geen staan.');
        }

        $section = Section::make('Topics')
            ->description($error === null
                ? "Exact stuurt notificaties naar {$plan['callback_url']}"
                : "Live-standen konden niet worden opgehaald ({$error}). Onderstaande vinkjes tonen de laatst bekende stand uit de Hub, niet die van Exact.")
            ->columns(2)
            ->schema($toggles);

        return [$section];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private static function offeredTopics(array $plan, Connection $record): array
    {
        $topics = array_unique(array_merge(
            $plan['configured'] ?? [],
            array_keys($plan['remote'] ?? []),
            array_keys($plan['stored'] ?? []),
        ));

        sort($topics);

        return array_values($topics);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private static function helperFor(string $topic, array $plan): ?string
    {
        $id = $plan['remote'][$topic] ?? null;

        if ($id !== null) {
            return in_array($topic, $plan['configured'] ?? [], true)
                ? "Actief — {$id}"
                : "Actief bij Exact, maar niet door de Hub geconfigureerd — {$id}";
        }

        return in_array($topic, array_keys($plan['stored'] ?? []), true)
            ? 'De Hub kent hier nog een abonnement voor dat Exact niet meer heeft.'
            : null;
    }

    /**
     * @return array<string, bool>
     */
    private static function currentState(Connection $record): array
    {
        [$plan] = self::plan($record);

        $state = [];

        foreach (self::offeredTopics($plan, $record) as $topic) {
            $state[$topic] = isset($plan['remote'][$topic]);
        }

        return $state;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private static function plan(Connection $record): array
    {
        $manager = app(ExactWebhookSubscriptionManager::class);

        try {
            return [$manager->plan($record), null];
        } catch (Throwable $e) {
            $stored = ($record->metadata ?? [])['exact_webhooks'] ?? [];

            return [[
                'callback_url' => $manager->callbackUrl(),
                'configured' => (array) config('services.exact.webhook_topics', []),
                'remote' => is_array($stored) ? $stored : [],
                'stored' => is_array($stored) ? $stored : [],
            ], $e->getMessage()];
        }
    }

    /**
     * @param  array{added: list<string>, removed: list<string>}  $result
     */
    private static function summarise(array $result): string
    {
        $parts = [];

        if ($result['added'] !== []) {
            $parts[] = 'aangemaakt: '.implode(', ', $result['added']);
        }

        if ($result['removed'] !== []) {
            $parts[] = 'opgezegd: '.implode(', ', $result['removed']);
        }

        return $parts === [] ? 'Niets gewijzigd.' : ucfirst(implode(' · ', $parts));
    }
}
