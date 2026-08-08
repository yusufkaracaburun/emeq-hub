<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Provider;
use App\Jobs\Webhooks\ForwardConnectionRevokedToConsumerJob;
use App\Mail\ConnectionDeprovisioned;
use App\Models\Connection;
use App\OAuth\Exact\ExactOAuthFlow;
use App\Support\Seo\SeoMeta;
use App\Webhooks\InboundWebhookRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exact App Center Seamless-deprovisioning ("Niet meer gebruiken"). Exact
 * redirect de gebruiker naar /exact/stop?Country=&Language=&UserId=; we
 * matchen op het bij connect opgeslagen Exact-UserID (metadata, uit /Me).
 *
 * De te revoken connection reist via de sessie — niet via een POST-param —
 * zodat de CSRF-beschermde confirm-POST geen tamperbare connection-verwijzing
 * accepteert. Geen match (of al beëindigd) toont de zachte variant; de query-
 * params verschijnen nergens in de UI.
 */
class ExactDeprovisionController extends Controller
{
    private const SESSION_KEY = 'exact_stop.connection_id';

    public function __construct(
        private readonly ExactOAuthFlow $oauthFlow,
        private readonly InboundWebhookRecorder $recorder,
    ) {}

    public function confirm(Request $request): Response
    {
        $exactUserId = $request->query('UserId');
        $connection = $this->matchConnection($exactUserId);

        if ($connection === null) {
            // Alleen auditen als Exact daadwerkelijk een UserId meestuurde:
            // dan is dit een deprovision-poging die we niet konden matchen
            // (triage-waardig), geen kale crawler-hit.
            if (filled($exactUserId)) {
                $this->recorder->record(
                    Provider::Exact->value,
                    $request,
                    200,
                    InboundWebhookRecorder::OUTCOME_UNKNOWN_TENANT,
                    topic: 'deprovision',
                    action: 'redirect',
                );
            }

            $request->session()->forget(self::SESSION_KEY);

            return $this->page('soft');
        }

        $request->session()->put(self::SESSION_KEY, $connection->id);

        return $this->page('confirm');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $connectionId = $request->session()->pull(self::SESSION_KEY);

        $connection = $connectionId !== null
            ? Connection::query()
                ->whereKey($connectionId)
                ->where('provider', Provider::Exact)
                ->whereNull('revoked_at')
                ->first()
            : null;

        // Sessie verlopen of connection intussen al ingetrokken → terug naar
        // de GET, die zonder UserId de zachte variant toont.
        if ($connection === null) {
            return redirect()->route('exact.stop');
        }

        // Bewust niet via de OAuthFlowRegistry: die gooit bij een actieve
        // kill-switch (Pennant) een ProviderDisabledException, en ontkoppelen
        // moet juist ook dan blijven werken.
        $this->oauthFlow->revoke($connection);

        // Nette afhandeling: consumer-app notificeren (die stuurt de
        // eindgebruiker-bevestiging — de Hub kent bewust geen PII), interne
        // ops-melding, en een audit-rij met de fanout-uitkomst.
        $eventId = (string) Str::uuid();
        $hasCallback = filled($connection->account?->consumer?->webhook_callback_url);

        ForwardConnectionRevokedToConsumerJob::dispatch($connection, 'exact_app_center', $eventId);
        Mail::to(config('mail.notify_address'))->send(new ConnectionDeprovisioned($connection));

        $this->recorder->record(
            Provider::Exact->value,
            $request,
            302,
            InboundWebhookRecorder::OUTCOME_PROCESSED,
            $eventId,
            'deprovision',
            'revoked',
            $connection,
            $hasCallback ? InboundWebhookRecorder::FANOUT_DISPATCHED : InboundWebhookRecorder::FANOUT_SKIPPED,
        );

        return redirect()->route('exact.stop.done');
    }

    public function done(): Response
    {
        return $this->page('done');
    }

    private function matchConnection(?string $exactUserId): ?Connection
    {
        if ($exactUserId === null || $exactUserId === '') {
            return null;
        }

        return Connection::query()
            ->where('provider', Provider::Exact)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where('metadata->exact_user_id', $exactUserId)
            ->latest('id')
            ->first();
    }

    private function page(string $state): Response
    {
        $meta = match ($state) {
            'confirm' => ['Emeq Hub ontkoppelen van Exact Online?', 'Bevestig het beëindigen van de koppeling tussen Emeq Hub en je Exact-administratie.'],
            'done' => ['Koppeling beëindigd', 'Emeq Hub heeft geen toegang meer tot deze Exact-administratie.'],
            default => ['Geen actieve koppeling gevonden', 'Er is geen Emeq Hub-koppeling gekoppeld aan dit Exact-account.'],
        };

        return Inertia::render('exact/stop', [
            'state' => $state,
            'seo' => SeoMeta::make($meta[0], $meta[1]),
        ]);
    }
}
