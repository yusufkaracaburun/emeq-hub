<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Connect\ProviderNotConnectableException;
use App\Actions\Connect\RevokeConnection;
use App\Actions\Connect\StartProviderConnection;
use App\Enums\Provider;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Jobs\Webhooks\ForwardConnectionRevokedToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Support\Connect\ConnectLinkFactory;
use App\Support\Connect\ProviderConnectStatus;
use App\Support\Seo\SeoMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Handoff-pagina: de eindgebruiker van een consumer-app kiest hier zelf welk
 * systeem hij koppelt. De consumer-app hoeft alleen te linken (zie
 * `POST /v1/connect-sessions`); zijn klant doet de rest.
 *
 * Niet publiek: beide routes draaien onder `signed`, de handtekening legt vast
 * om welk Account het gaat. De Consumer wordt daaruit afgeleid via de relatie,
 * nooit uit de URL — de keten `Consumer → Account → Connection` blijft zo
 * intact zonder eindgebruiker-auth in de Hub.
 *
 * De pagina staat bewust niet in PublicPages: geen sitemap, geen index
 * (SetNoIndexHeaders zet noindex op alles buiten de marketing-surface).
 */
class ConnectHandoffController extends Controller
{
    public function __construct(
        private readonly ProviderConnectStatus $statuses,
        private readonly ConnectLinkFactory $links,
    ) {}

    public function show(Request $request, Account $account): Response
    {
        // Alleen providers die daadwerkelijk via een authorize-redirect te
        // koppelen zijn. Snelstart (clientkey) en providers met een actieve
        // kill-switch vallen af — een knop tonen die zeker faalt is misleidend.
        $providers = collect($this->statuses->for($account))
            ->filter(fn (array $provider): bool => $provider['connectable'])
            ->map(fn (array $provider): array => [
                ...$provider,
                'start_url' => $this->links->startUrl($request, $account, $provider['key'], 'connect.start'),
                'disconnect_url' => $provider['status'] === 'connected'
                    ? $this->links->startUrl($request, $account, $provider['key'], 'connect.disconnect')
                    : null,
            ])
            ->values()
            ->all();

        // Eén beheerscherm voor alle gevallen: de status staat per rij, dus een
        // aparte "alles gekoppeld"-variant voegt niets toe. Het geslaagd-moment
        // na een koppeling leeft bovendien al op /oauth/connected/{connection}.
        return Inertia::render('connect/index', [
            'state' => 'manage',
            'consumerName' => $account->consumer?->name,
            'accountName' => $account->display_name,
            'providers' => $providers,
            'returnUrl' => $this->returnUrl($request),
            'expiresAt' => $this->links->inheritedExpiry($request)->toIso8601String(),
            'seo' => SeoMeta::make(
                'Je koppelingen',
                'Beheer welke systemen zijn gekoppeld.',
            ),
        ]);
    }

    public function start(
        Request $request,
        Account $account,
        string $provider,
        StartProviderConnection $startConnection,
    ): HttpResponse {
        $providerEnum = Provider::tryFrom($provider);

        abort_if($providerEnum === null, 404);

        try {
            $result = $startConnection->handle($account, $providerEnum, $this->returnUrl($request));
        } catch (ProviderNotConnectableException) {
            abort(404);
        } catch (ProviderDisabledException) {
            abort(503);
        }

        // Weg van de eigen site naar de partner-authorize-URL: Inertia moet dit
        // als volledige browser-navigatie doen, niet als XHR-visit.
        return Inertia::location($result['redirect_url']);
    }

    /**
     * De eindgebruiker trekt zijn eigen koppeling in. De getekende link bewijst
     * om welk Account het gaat — dezelfde bewijslast als voor koppelen, dus
     * geen apart autorisatiemodel.
     *
     * Anders dan bij `DELETE /v1/connections/{id}` moet de consumer-app hier
     * wél actief genotificeerd worden: die heeft de actie niet zelf gestart en
     * zou anders met een dode koppeling in zijn UI blijven staan.
     */
    public function disconnect(
        Request $request,
        Account $account,
        string $provider,
        RevokeConnection $revokeConnection,
    ): RedirectResponse {
        $providerEnum = Provider::tryFrom($provider);

        abort_if($providerEnum === null, 404);

        $connection = $account->connections()
            ->where('provider', $providerEnum->value)
            ->whereNull('revoked_at')
            ->first();

        // Al ingetrokken (of nooit gekoppeld): geen fout tonen, gewoon terug
        // naar de pagina — die laat dan vanzelf de losgekoppelde staat zien.
        if ($connection instanceof Connection) {
            $revokeConnection->handle($connection);

            ForwardConnectionRevokedToConsumerJob::dispatch(
                $connection,
                'end_user_handoff',
                (string) Str::uuid(),
            );
        }

        // Vervaltijd overnemen, niet opnieuw zetten: ontkoppelen is idempotent,
        // dus een verse TTL zou een gelekte link onbeperkt verlengbaar maken.
        return redirect()->to($this->links->mint(
            $account,
            $this->returnUrl($request),
            $this->links->inheritedExpiry($request),
        )['url']);
    }

    /**
     * De return-URL reist mee in de getekende query en is bij het minten al
     * door ReturnUrlResolver tegen `Consumer.app_url` gevalideerd; de
     * handtekening maakt hem hier tamper-proof.
     */
    private function returnUrl(Request $request): ?string
    {
        $returnUrl = $request->query('return_url');

        return is_string($returnUrl) && $returnUrl !== '' ? $returnUrl : null;
    }
}
