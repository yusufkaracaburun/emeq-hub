<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Provider;
use App\Integrations\Exact\ExactUserId;
use App\Integrations\Exact\OAuth\ExactOAuthFlow;
use App\Integrations\Webhooks\InboundWebhookRecorder;
use App\Jobs\Webhooks\ForwardConnectionRevokedToConsumerJob;
use App\Mail\ConnectionDeprovisioned;
use App\Models\Connection;
use App\Support\Seo\SeoMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ExactDeprovisionController extends Controller
{
    private const SESSION_KEY = 'exact_stop.connection_id';

    public function __construct(
        private readonly ExactOAuthFlow $oauthFlow,
        private readonly InboundWebhookRecorder $recorder,
    ) {}

    public function confirm(Request $request): Response
    {
        $exactUserId = ExactUserId::normalize($request->query('UserId'));
        $connection = $this->matchConnection($exactUserId);

        if ($connection === null) {
            if ($exactUserId !== null) {
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

        if ($connection === null) {
            return redirect()->route('exact.stop');
        }

        $this->oauthFlow->revoke($connection);

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

    /** @param  string|null  $normalized  al door ExactUserId::normalize() gehaald */
    private function matchConnection(?string $normalized): ?Connection
    {
        if ($normalized === null) {
            return null;
        }

        return Connection::query()
            ->where('provider', Provider::Exact)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereIn('metadata->exact_user_id', ExactUserId::storageCandidates($normalized))
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
