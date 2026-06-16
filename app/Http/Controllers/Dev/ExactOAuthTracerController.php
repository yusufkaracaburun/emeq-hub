<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Dev-only Exact Online OAuth2 + Seamless-connection tracer.
 *
 * Doel: een ECHTE OAuth-round-trip draaien met de test-app-creds én vastleggen
 * wát Exact precies naar de Seamless-lifecycle-URIs ("Starten" / "Niet meer
 * gebruiken") stuurt — method, query, body, headers. Die contracten staan niet
 * in publieke docs, dus we observeren ze i.p.v. ze te verzinnen.
 *
 * De OAuth-request-shapes (authorize-URL, token-exchange) zijn 1:1 overgenomen
 * uit Exact's eigen Postman-collection (packages/exact-api/OAUTH.postman_collection.json) —
 * de autoritatieve bron.
 *
 * Wegwerp-harnas: leeft alleen in local/testing (route-guard) en op de
 * `/dev/exact/*`-paden, los van de echte `/v1/oauth/exact/*`-endpoints die in de
 * Hub-wiring-slice landen. Captures gaan naar storage/logs/exact-tracer.log
 * (gitignored). Secrets worden ge-fingerprint, nooit raw gelogd.
 */
final class ExactOAuthTracerController
{
    private const AUTHORIZE_PATH = '/api/oauth2/auth';

    private const TOKEN_PATH = '/api/oauth2/token';

    /**
     * Seamless "Starten" + OAuth-init. Logt eerst wat Exact bij de launch
     * meestuurt, bouwt dan de authorize-URL en redirect naar Exact.
     */
    public function start(Request $request): RedirectResponse|Response
    {
        $this->capture('start (Seamless "Starten" / OAuth-init)', $request);

        $clientId = (string) config('services.exact.client_id');
        $redirectUri = (string) config('services.exact.redirect_uri');

        if ($clientId === '' || $redirectUri === '') {
            return response(
                "Ontbrekende config: zet EXACT_CLIENT_ID en EXACT_REDIRECT_URI in .env.\n".
                'EXACT_REDIRECT_URI moet je tunnel-callback zijn, bv. https://<tunnel>/dev/exact/callback',
                500,
            );
        }

        $state = Str::random(40);
        $request->session()->put('exact_tracer_state', $state);

        // Shape exact per Postman-collection request #1 (client_id, redirect_uri,
        // response_type=code). state toegevoegd als standaard-OAuth2 CSRF-guard.
        $authorizeUrl = rtrim((string) config('services.exact.auth_base_url'), '/').self::AUTHORIZE_PATH.'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ]);

        $this->logger()->info('[exact-tracer] redirecting to authorize', [
            'authorize_url_without_query' => rtrim((string) config('services.exact.auth_base_url'), '/').self::AUTHORIZE_PATH,
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ]);

        return redirect()->away($authorizeUrl);
    }

    /**
     * Redirect-URI / OAuth-callback. Vangt ALLES op wat Exact terugstuurt,
     * verifieert state, wisselt code in voor tokens en logt de token-respons
     * (ge-fingerprint).
     */
    public function callback(Request $request): Response
    {
        $this->capture('callback (redirect URI)', $request);

        $expectedState = $request->session()->pull('exact_tracer_state');
        $stateOk = $expectedState !== null && hash_equals((string) $expectedState, (string) $request->query('state', ''));

        if ($request->query('error') !== null) {
            return response("Exact gaf een error terug: {$request->query('error')} — {$request->query('error_description')}", 400);
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return response('Geen `code` in de callback. Zie storage/logs/exact-tracer.log voor wat Exact wél stuurde.', 400);
        }

        $response = Http::asForm()->post($this->tokenUrl(), [
            // Body verbatim uit Postman request #2 (First Token Request).
            'grant_type' => 'authorization_code',
            'client_id' => (string) config('services.exact.client_id'),
            'client_secret' => (string) config('services.exact.client_secret'),
            'redirect_uri' => (string) config('services.exact.redirect_uri'),
            'code' => $code,
        ]);

        $this->logTokenResponse('authorization_code exchange', $response->status(), $response->json() ?? []);

        if ($response->failed()) {
            return response("Token-exchange faalde (HTTP {$response->status()}). Body in de log. Body: ".$response->body(), 502);
        }

        $body = (array) $response->json();

        // Stash de raw bundle (dev-only cache) zodat /dev/exact/refresh 'm ná
        // expiry kan refreshen. Exact weigert refresh zolang de access_token nog
        // geldig is ("Rate limit exceeded: access_token not expired"), dus rotatie
        // is alleen ~10 min later te bevestigen.
        Cache::put('exact_tracer:last_token', [
            'access_token' => $body['access_token'] ?? null,
            'refresh_token' => $body['refresh_token'] ?? null,
            'expires_in' => (int) ($body['expires_in'] ?? 0),
            'issued_at' => now()->timestamp,
        ], now()->addHour());

        [$meStatus, $division] = $this->fetchDivision((string) ($body['access_token'] ?? ''));

        $lines = [
            'OAuth round-trip geslaagd ✅',
            'state-check: '.($stateOk ? 'OK' : 'MISMATCH (zie log)'),
            'token_type: '.($body['token_type'] ?? '(geen)'),
            'expires_in: '.($body['expires_in'] ?? '(geen)').' (verwacht "600")',
            'refresh_token aanwezig: '.(isset($body['refresh_token']) ? 'ja' : 'nee'),
            'response-keys: '.implode(', ', array_keys($body)),
            '/current/Me: HTTP '.$meStatus.' — division: '.($division ?? '(niet gevonden)'),
            '',
            'Token gestald. Wacht ~10 min (tot de access_token verlopen is) en open',
            'dan /dev/exact/refresh om de refresh-rotatie te bevestigen — Exact',
            'weigert refresh zolang de token nog geldig is.',
            '',
            'Detail (ge-fingerprint) staat in storage/logs/exact-tracer.log.',
        ];

        return response(implode("\n", $lines), 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Delayed refresh — bevestigt rotatie ná expiry. Leest het gestalde token,
     * roept de refresh aan en vergelijkt oud vs nieuw refresh_token. Re-stalt de
     * nieuwe bundle zodat je 'm na een volgende expiry opnieuw kunt proberen.
     */
    public function refresh(Request $request): Response
    {
        $this->capture('refresh (delayed rotatie-check)', $request);

        $stash = Cache::get('exact_tracer:last_token');

        if (! is_array($stash) || empty($stash['refresh_token'])) {
            return response('Geen gestald token. Doe eerst /dev/exact/start.', 400)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $oldRefresh = (string) $stash['refresh_token'];

        $r = Http::asForm()->post($this->tokenUrl(), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $oldRefresh,
            'client_id' => (string) config('services.exact.client_id'),
            'client_secret' => (string) config('services.exact.client_secret'),
        ]);
        $rbody = (array) $r->json();
        $this->logTokenResponse('delayed refresh', $r->status(), $rbody);

        if ($r->failed()) {
            $desc = (string) ($rbody['error_description'] ?? $r->body());
            $hint = str_contains($desc, 'not expired')
                ? "\naccess_token is nog niet verlopen — wacht tot ~10 min na /start en probeer opnieuw."
                : '';

            return response("Refresh geweigerd (HTTP {$r->status()}): {$desc}{$hint}", 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $newRefresh = (string) ($rbody['refresh_token'] ?? '');
        $rotated = $newRefresh !== '' && $newRefresh !== $oldRefresh;

        Cache::put('exact_tracer:last_token', [
            'access_token' => $rbody['access_token'] ?? null,
            'refresh_token' => $newRefresh,
            'expires_in' => (int) ($rbody['expires_in'] ?? 0),
            'issued_at' => now()->timestamp,
        ], now()->addHour());

        return response(implode("\n", [
            'Delayed refresh ✅ HTTP '.$r->status(),
            'rotatie: '.($rotated ? 'NIEUW refresh_token ✅ (oud ≠ nieuw)' : 'ZELFDE refresh_token ⚠️ (geen rotatie)'),
            'nieuwe expires_in: '.($rbody['expires_in'] ?? '(geen)'),
            'response-keys: '.implode(', ', array_keys($rbody)),
            '',
            'Nieuwe bundle opnieuw gestald — na ~10 min kun je /dev/exact/refresh herhalen.',
            'Detail (ge-fingerprint) staat in storage/logs/exact-tracer.log.',
        ]), 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * @return array{0: int, 1: int|string|null} [http-status, CurrentDivision]
     */
    private function fetchDivision(string $accessToken): array
    {
        if ($accessToken === '') {
            return [0, null];
        }

        $me = Http::withToken($accessToken)->acceptJson()->get($this->meUrl());
        $division = data_get($me->json(), 'd.results.0.CurrentDivision');

        $this->logger()->info('[exact-tracer] division probe (/api/v1/current/Me)', [
            'status' => $me->status(),
            'current_division' => $division,
            'me_keys' => array_keys((array) data_get($me->json(), 'd.results.0', [])),
        ]);

        return [$me->status(), $division];
    }

    private function tokenUrl(): string
    {
        return rtrim((string) config('services.exact.auth_base_url'), '/').self::TOKEN_PATH;
    }

    private function meUrl(): string
    {
        return rtrim((string) config('services.exact.auth_base_url'), '/').'/api/v1/current/Me';
    }

    /**
     * Seamless "Niet meer gebruiken" (deprovision). Puur logger — legt vast wat
     * Exact stuurt zodat de echte revoke-handler in de Hub-slice op feiten bouwt.
     */
    public function stop(Request $request): Response
    {
        $this->capture('stop (Seamless "Niet meer gebruiken")', $request);

        return response('Deprovision-signaal gelogd. Zie storage/logs/exact-tracer.log.', 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * "Meer informatie" / "Probeer nu" landing. Logger + minimale pagina.
     */
    public function info(Request $request): Response
    {
        $this->capture('info ("Meer informatie" / "Probeer nu")', $request);

        return response('Emeq × Exact Online — test-integratie. (Dev-tracer landingspagina.)', 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Legt method, path, query, body en relevante headers van een inkomend
     * verzoek vast. Secrets/cookies worden geredigeerd.
     */
    private function capture(string $label, Request $request): void
    {
        $this->logger()->info("[exact-tracer] {$label}", [
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $request->query(),
            'body' => $this->redact((array) $request->post()),
            'headers' => collect($request->headers->all())
                ->except(['authorization', 'cookie'])
                ->map(fn ($v) => is_array($v) ? implode(',', $v) : $v)
                ->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function logTokenResponse(string $label, int $status, array $body): void
    {
        $this->logger()->info("[exact-tracer] {$label}", [
            'status' => $status,
            'keys' => array_keys($body),
            'token_type' => $body['token_type'] ?? null,
            'expires_in' => $body['expires_in'] ?? null,
            'access_token' => isset($body['access_token']) ? $this->fingerprint((string) $body['access_token']) : null,
            'refresh_token' => isset($body['refresh_token']) ? $this->fingerprint((string) $body['refresh_token']) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach (['client_secret', 'code', 'refresh_token', 'access_token'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = $this->fingerprint((string) $data[$key]);
            }
        }

        return $data;
    }

    /**
     * sha256-prefix + lengte — genoeg om rotatie te herkennen zonder het secret
     * te lekken.
     */
    private function fingerprint(string $value): string
    {
        return 'fp:'.mb_substr(hash('sha256', $value), 0, 12).' (len '.mb_strlen($value).')';
    }

    private function logger(): LoggerInterface
    {
        return Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/exact-tracer.log'),
        ]);
    }
}
