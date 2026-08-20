<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\Provider;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Integrations\OAuth\OAuthFlowRegistry;
use App\Models\Account;
use App\Models\Connection;
use App\Support\ProviderCredentialDescriptor;
use App\Support\ProviderGate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StartOAuthFlowAction
{
    /** @return array<string, string> key => label */
    public static function oauthCapableProviders(): array
    {
        $providers = [];

        foreach (ProviderCredentialDescriptor::all() as $descriptor) {
            if ($descriptor->oauthFlowKey === null) {
                continue;
            }
            if (! ProviderGate::enabled($descriptor->key)) {
                continue;
            }
            $providers[$descriptor->key] = ucfirst($descriptor->key);
        }

        return $providers;
    }

    public static function forAccount(): Action
    {
        return Action::make('startOAuthFlow')
            ->label('Koppel met provider…')
            ->icon(Heroicon::OutlinedLink)
            ->modalHeading('Provider kiezen')
            ->schema([
                Select::make('provider')
                    ->label('Provider')
                    ->helperText('Alleen providers met OAuth-flow zijn beschikbaar.')
                    ->options(self::oauthCapableProviders())
                    ->required(),
            ])
            ->modalSubmitActionLabel('Start koppeling')
            ->modalCancelActionLabel('Annuleren')
            ->visible(fn (): bool => auth()->user()?->can('manage-connections') ?? false)
            ->action(fn (Account $record, array $data): RedirectResponse|Redirector => self::dispatch($record, $data['provider']));
    }

    public static function forConnection(): Action
    {
        return Action::make('startOAuthFlow')
            ->label('Start OAuth-koppeling')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (Connection $record): bool => (auth()->user()?->can('manage-connections') ?? false)
                && $record->provider === Provider::Mollie
                && $record->access_token === null
                && $record->revoked_at === null
            )
            ->action(fn (Connection $record): RedirectResponse|Redirector => self::dispatch($record->account, $record->provider->value, $record));
    }

    public static function dispatch(Account $account, string $provider, ?Connection $existing = null): RedirectResponse|Redirector
    {
        try {
            $flow = app(OAuthFlowRegistry::class)->for($provider);
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Geen OAuth-flow beschikbaar')
                ->body("Provider {$provider} heeft geen OAuth-koppeling. Gebruik de Snelstart-credential-flow via de onboard-wizard of POST /v1/connections.")
                ->warning()
                ->send();

            return back();
        } catch (ProviderDisabledException $e) {
            Notification::make()
                ->title("Provider {$provider} is tijdelijk uitgeschakeld")
                ->body($e->getMessage())
                ->warning()
                ->send();

            return back();
        }

        $state = Str::random(48);
        $scopes = (array) config("services.{$provider}.connect.scopes");

        try {
            $url = $flow->getAuthorizationUrl($account, $scopes, $state);
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title("OAuth-flow voor {$provider} faalde")
                ->body('Authorize-URL kon niet worden opgebouwd — bekijk de applicatie-logs (`php artisan pail` of de Logs-pagina in de admin).')
                ->danger()
                ->send();

            return back();
        }

        $existing ??= $account->connections()
            ->where('provider', $provider)
            ->whereNull('revoked_at')
            ->first();

        if ($existing !== null) {
            $existing->update([
                'status' => 'pending',
                'oauth_state' => $state,
                'oauth_state_expires_at' => now()->addMinutes(30),
            ]);
        } else {
            $account->connections()->create([
                'provider' => $provider,
                'status' => 'pending',
                'oauth_state' => $state,
                'oauth_state_expires_at' => now()->addMinutes(30),
            ]);
        }

        return redirect()->away($url);
    }
}
