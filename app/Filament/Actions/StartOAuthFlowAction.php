<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\Provider;
use App\Models\Account;
use App\Models\Connection;
use App\OAuth\Exceptions\ProviderDisabledException;
use App\OAuth\OAuthFlowRegistry;
use App\Support\ProviderCredentialDescriptor;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Pennant\Feature;

/**
 * Plan 08-03 — Shared Filament Action voor OAuth-init.
 *
 * D-05 + UI-SPEC S2: één Action-class met twee static factories:
 *  - forAccount(): primary CTA op AccountResource ("Koppel met provider…")
 *  - forConnection(): secondary CTA op ConnectionResource ("Start OAuth-koppeling")
 *
 * Beide hergebruiken Phase-4 OAuthFlowRegistry + InitController-logica via
 * de gedeelde dispatch()-method; geen duplicate OAuth-flow-implementatie.
 *
 * Descriptor-driven via ProviderCredentialDescriptor::all() — alleen providers
 * met oauthFlowKey !== null verschijnen in de provider-dropdown.
 */
class StartOAuthFlowAction
{
    /**
     * Whitelist providers met OAuth-flow (descriptor-driven).
     *
     * Filtert tevens uit op Pennant feature-flag — een provider die via de
     * documented kill-switch (CLAUDE.md "Feature-flags / kill-switch") is
     * uitgeschakeld verschijnt niet in de dropdown, zodat staff zich niet
     * door een 503-notification heen worstelt (CR-03).
     *
     * @return array<string, string> key => label
     */
    public static function oauthCapableProviders(): array
    {
        $providers = [];

        foreach (ProviderCredentialDescriptor::all() as $descriptor) {
            if ($descriptor->oauthFlowKey === null) {
                continue;
            }
            if (! Feature::active("provider-{$descriptor->key}-enabled")) {
                continue;
            }
            $providers[$descriptor->key] = ucfirst($descriptor->key);
        }

        return $providers;
    }

    /**
     * Primary CTA op AccountResource — modal met provider-keuze.
     */
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

    /**
     * Secondary CTA op ConnectionResource — alleen voor pending Mollie zonder access_token.
     */
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

    /**
     * Single source-of-truth voor OAuth-init — copy van InitController-pattern.
     *
     * Hergebruikt bestaande Connection als $existing meegegeven (forConnection-pad);
     * anders maakt een nieuwe pending Connection aan op het Account.
     *
     * Public static voor directe testability — anders moet je via Livewire-mount-stack
     * gaan, wat een onnodige indirectie is voor unit-coverage van de init-flow.
     */
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
            // CR-03: Pennant kill-switch — provider tijdelijk uitgeschakeld.
            // `oauthCapableProviders()` filtert hierop, dus de dropdown zou de
            // optie niet moeten tonen; deze catch dekt de race tussen flag-toggle
            // en form-submit (én de forConnection()-CTA die de dropdown overslaat).
            Notification::make()
                ->title("Provider {$provider} is tijdelijk uitgeschakeld")
                ->body($e->getMessage())
                ->warning()
                ->send();

            return back();
        }

        $state = Str::random(48);
        // (array)-cast: niet elke provider heeft een connect.scopes-config (Exact
        // gebruikt geen scopes → null). getAuthorizationUrl() verwacht een array;
        // null zou een TypeError geven. No-op voor Mollie's array.
        $scopes = (array) config("services.{$provider}.connect.scopes");

        // CR-04-equivalent: bouw de authorize-URL VÓÓR we de pending Connection
        // wegschrijven. Een runtime-fout in getAuthorizationUrl() (network, config
        // missing, etc.) liet voorheen een orphan pending-row achter op elke retry.
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

        if ($existing !== null) {
            $existing->update([
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
