---
phase: 05c-snelstart-webhook-handler
plan: 02
type: execute
wave: 2
depends_on: [05c-01]
files_modified:
  - config/services.php
  - app/Webhooks/SnelstartSignatureVerifier.php
  - app/Http/Middleware/VerifySnelstartSignature.php
  - bootstrap/app.php
  - tests/Feature/SnelstartSignatureVerifierTest.php
  - tests/Feature/VerifySnelstartSignatureMiddlewareTest.php
autonomous: true
requirements: [HUB-06]
tags:
  - laravel
  - middleware
  - security
  - hmac
  - phpunit

must_haves:
  truths:
    - "Een raw POST-body met een geldige HMAC-SHA256-signature (over de raw body, hex-encoded) en bekende secret passeert de middleware en bereikt de route-action"
    - "Een raw POST-body met een verkeerde of ontbrekende signature wordt afgekapt op de middleware met respons 401 + lege body, zonder dat de controller of audit-laag wordt aangeroepen"
    - "Hardfail-guard: een lege of ontbrekende `SNELSTART_WEBHOOK_SECRET` levert 500 + audit-row met `webhook_secret_not_configured` op (analoog aan MollieWebhookController D-08 stap 1)"
    - "Header-naam en algorithme zijn config-driven; partner-respons #1 kan via env-var verschuiven zonder code-deploy"
    - "Secret-rotatie: zowel `SNELSTART_WEBHOOK_SECRET` als `SNELSTART_WEBHOOK_SECRET_NEXT` accepteren elkaar binnen de rotation-window"
  artifacts:
    - path: "app/Webhooks/SnelstartSignatureVerifier.php"
      provides: "Pure-PHP HMAC-verifier met `verify(string $rawBody, ?string $headerValue, string|array $secrets): bool`"
      exports: ["verify", "sign"]
    - path: "app/Http/Middleware/VerifySnelstartSignature.php"
      provides: "Laravel-middleware `verify.snelstart.signature` die de verifier-class aanroept en 401/500 afkapt"
      contains: "VerifySnelstartSignature"
    - path: "config/services.php"
      provides: "`services.snelstart.webhook_secret` + `services.snelstart.webhook_secret_next` + `services.snelstart.webhook_signature_header` + `services.snelstart.webhook_signature_algo` + `services.snelstart.webhook_event_id_key` (5 keys totaal)"
      contains: "'webhook_secret'"
    - path: "bootstrap/app.php"
      provides: "`verify.snelstart.signature` alias geregistreerd in `withMiddleware`"
      contains: "verify.snelstart.signature"
  key_links:
    - from: "VerifySnelstartSignature::handle()"
      to: "SnelstartSignatureVerifier::verify()"
      via: "DI-container call"
      pattern: "service binding"
---

<objective>
HMAC-verificatie voor Snelstart-webhooks — een class voor de math, een middleware voor de HTTP-laag, config voor het beslismodel.

Purpose: HUB-06 success criterion 2 — "Invalid HMAC → 401, lege body, géén audit-row". CONTEXT decision "Locked — Invalid signature handling" + "❓ Aanname (vraag #1) HMAC-header-naam + algorithme" + "❓ Aanname (vraag #2) Webhook-secret-lifecycle" worden hier geland — beide ❓'s zijn config-driven defensief gebouwd, partner-respons verschuift alleen env-defaults.

Output: verifier-class + middleware + config-keys + alias-registratie + 2 unit-/feature-tests. **Geen** route of controller (die zit in plan 03).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md
@CLAUDE.md
@config/services.php
@bootstrap/app.php
@app/Http/Middleware/RequireCashierWebhookSecret.php
@app/Http/Controllers/Webhooks/MollieWebhookController.php

<interfaces>
<!-- Mollie pattern dat we hier spiegelen voor Snelstart -->

From vendor/emeq/mollie-api/src/Webhooks/MollieWebhookSignature.php:
```php
final class MollieWebhookSignature {
    public static function verify(Request $request, string|array $signingSecrets): bool;
    public static function sign(string $payload, string $signingSecret): string;
}
```

From app/Http/Middleware/RequireCashierWebhookSecret.php:
```php
// Pattern: middleware leest config, doet hash_equals, returnt 403 bij mismatch
class RequireCashierWebhookSecret { public function handle(Request $r, Closure $next): Response; }
```

From config/services.php (huidige structuur):
```php
'mollie' => [
    'connect' => [...],
    'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET'),
],
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Config — `services.snelstart.webhook_*`</name>
  <files>config/services.php, .env.example</files>
  <read_first>
    - config/services.php (huidige `services.snelstart`-block bestaat al voor 5b; we voegen webhook-keys toe)
    - .env.example (voor SNELSTART_*-keys naast bestaande SNELSTART_BASE_URL etc.)
    - .planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md (sectie `❓ Aanname (vraag #1)` voor defaults)
  </read_first>
  <action>
    **1. `config/services.php`** — breid de bestaande `services.snelstart`-array uit (NIET vervangen) met:

    ```php
    'snelstart' => [
        // ... bestaande keys (base_url, api_base_url, scope, etc.)
        'webhook_secret' => env('SNELSTART_WEBHOOK_SECRET'),
        'webhook_secret_next' => env('SNELSTART_WEBHOOK_SECRET_NEXT'),
        'webhook_signature_header' => env('SNELSTART_WEBHOOK_SIGNATURE_HEADER', 'X-SnelStart-Signature'),
        'webhook_signature_algo' => env('SNELSTART_WEBHOOK_SIGNATURE_ALGO', 'sha256'),
        'webhook_event_id_key' => env('SNELSTART_WEBHOOK_EVENT_ID_KEY', 'eventId'),
    ],
    ```

    De `webhook_signature_header` + `webhook_signature_algo` defaults volgen CONTEXT.md aanname #1; `webhook_event_id_key` volgt aanname #4 (event-payload-veld; default camelCase OData-conventie). Partner-respons mag alle defaults wijzigen via env-vars.

    **2. `.env.example`** — voeg toe na bestaande `SNELSTART_*`-keys:

    ```
    SNELSTART_WEBHOOK_SECRET=
    SNELSTART_WEBHOOK_SECRET_NEXT=
    SNELSTART_WEBHOOK_SIGNATURE_HEADER=X-SnelStart-Signature
    SNELSTART_WEBHOOK_SIGNATURE_ALGO=sha256
    SNELSTART_WEBHOOK_EVENT_ID_KEY=eventId
    ```

    Run pint na de wijziging.
  </action>
  <verify>
    <automated>php artisan config:show services.snelstart 2>&1 | grep -E "webhook_(secret|signature)"</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "'webhook_secret'" config/services.php` >= 1 (binnen `'snelstart' =>`-block — verifieerbaar via gefilterde grep met context)
    - `grep -cE "'webhook_signature_(header|algo)'" config/services.php` == 2
    - `grep -c "'webhook_event_id_key'" config/services.php` == 1
    - `php artisan config:show services.snelstart.webhook_signature_header` toont `X-SnelStart-Signature` als default
    - `php artisan config:show services.snelstart.webhook_signature_algo` toont `sha256` als default
    - `php artisan config:show services.snelstart.webhook_event_id_key` toont `eventId` als default
    - `.env.example` bevat `SNELSTART_WEBHOOK_SECRET=`
  </acceptance_criteria>
  <done>Vier config-keys zijn leesbaar via `config('services.snelstart.webhook_*')`; defaults werken zonder set env-vars.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: `App\Webhooks\SnelstartSignatureVerifier`</name>
  <files>app/Webhooks/SnelstartSignatureVerifier.php, tests/Feature/SnelstartSignatureVerifierTest.php</files>
  <behavior>
    - `verify($rawBody, $header, $secret, $algo='sha256')` returnt `true` enkel als `hash_hmac($algo, $rawBody, $secret)` (hex) exact-match met `$header` is via `hash_equals()`
    - Ondersteunt `string|array` voor `$secret` — match op één van beide is voldoende (rotation-window)
    - Returnt `false` bij `null`/empty header, mismatching hex, of empty body
    - `sign($payload, $secret, $algo='sha256')` retourneert hex (geen `algo=`-prefix — Snelstart's exacte format ❓)
  </behavior>
  <read_first>
    - vendor/emeq/mollie-api/src/Webhooks/MollieWebhookSignature.php (timing-safe + array-of-secrets-pattern)
    - app/Http/Middleware/RequireCashierWebhookSecret.php (hash_equals-pattern in Hub)
  </read_first>
  <action>
    **1. Class `app/Webhooks/SnelstartSignatureVerifier.php`** (nieuwe namespace `App\Webhooks`):

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Webhooks;

    /**
     * HMAC-verifier voor Snelstart-webhook-ingress.
     *
     * Header-naam en algoritme zijn config-driven (services.snelstart.webhook_*).
     * Tot Snelstart-partner-respons de exacte spec bevestigt, default = X-SnelStart-Signature
     * + sha256 over raw body, hex-encoded (CONTEXT.md aanname #1).
     */
    final class SnelstartSignatureVerifier
    {
        /**
         * @param  string|string[]  $secrets  Eén secret of een array (rotatie-window).
         */
        public static function verify(
            string $rawBody,
            ?string $headerValue,
            string|array $secrets,
            string $algo = 'sha256',
        ): bool {
            if ($headerValue === null || $headerValue === '') {
                return false;
            }

            $candidates = is_array($secrets) ? $secrets : [$secrets];
            $candidates = array_values(array_filter($candidates, static fn (?string $s): bool => is_string($s) && $s !== ''));

            if ($candidates === []) {
                return false;
            }

            foreach ($candidates as $secret) {
                $expected = hash_hmac($algo, $rawBody, $secret);
                if (hash_equals($expected, $headerValue)) {
                    return true;
                }
            }

            return false;
        }

        public static function sign(string $payload, string $secret, string $algo = 'sha256'): string
        {
            return hash_hmac($algo, $payload, $secret);
        }
    }
    ```

    **2. Test `tests/Feature/SnelstartSignatureVerifierTest.php`**:

    Vier scenarios via dataset of separate methods:
    1. `test_valid_signature_passes` — body+secret → `sign()`-output → `verify()` returnt `true`
    2. `test_invalid_signature_fails` — body+secret → match-attempt met andere secret → `verify()` returnt `false`
    3. `test_null_or_empty_header_fails` — `verify($body, null, $secret)` en `verify($body, '', $secret)` beide returnen `false`
    4. `test_rotation_window_accepts_either_secret` — body gesigneerd met secret-A; `verify()` met `[secret-A, secret-B]` returnt `true`; idem met `[secret-B, secret-A]` returnt `true`
    5. `test_empty_secret_array_fails` — `verify($body, $valid_header, [])` returnt `false`
    6. `test_different_algo_works` — `sign($body, $secret, 'sha512')` + `verify(..., 'sha512')` → `true`; default-algo-verify op same signature → `false`

    Run pint + `php artisan test --compact --filter=SnelstartSignatureVerifierTest`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=SnelstartSignatureVerifierTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "final class SnelstartSignatureVerifier" app/Webhooks/SnelstartSignatureVerifier.php` == 1
    - `grep -c "hash_equals" app/Webhooks/SnelstartSignatureVerifier.php` >= 1 (timing-safe vergelijking, niet `===`)
    - `grep -c "hash_hmac" app/Webhooks/SnelstartSignatureVerifier.php` >= 1
    - `php artisan test --compact --filter=SnelstartSignatureVerifierTest` exit 0, ≥6 tests passed
  </acceptance_criteria>
  <done>Verifier is pure-PHP, timing-safe, rotation-aware, alle 6 scenarios groen.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Middleware `VerifySnelstartSignature` + alias-registratie</name>
  <files>app/Http/Middleware/VerifySnelstartSignature.php, bootstrap/app.php, tests/Feature/VerifySnelstartSignatureMiddlewareTest.php</files>
  <behavior>
    - Hardfail-guard: ontbrekende `services.snelstart.webhook_secret` → 500 + audit-row met `direction=inbound` + `upstream_error=webhook_secret_not_configured` + `connection_id/account_id/consumer_id = NULL`
    - Invalid HMAC → 401, lege body, **géén** audit-row (anti-amplification per CONTEXT decision)
    - Valid HMAC → `$next($request)` wordt aangeroepen
    - Middleware leest header-naam + algo uit config (geen hardcoded `X-SnelStart-Signature`)
  </behavior>
  <read_first>
    - app/Http/Middleware/RequireCashierWebhookSecret.php (analoge hardfail-guard pattern)
    - app/Http/Controllers/Webhooks/MollieWebhookController.php (D-08 stap 1 audit-on-misconfigured-secret-pattern; **NB: Mollie audit naar Spatie `webhook_calls`-tabel; Snelstart audit naar `pass_through_calls` per 05c-CONTEXT decision "Audit-tabel reuse" — analog covers the FLOW (hard-fail + 500 + audit-row), NOT the audit-target**)
    - app/Webhooks/SnelstartSignatureVerifier.php (zojuist gebouwd in Task 2)
    - bootstrap/app.php (alias-array-pattern)
  </read_first>
  <action>
    **1. Middleware `app/Http/Middleware/VerifySnelstartSignature.php`**:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Http\Middleware;

    use App\Models\PassThroughCall;
    use App\Webhooks\SnelstartSignatureVerifier;
    use Closure;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    /**
     * Verifieert Snelstart-webhook-signature volgens CONTEXT.md decisions:
     *  - secret ontbreekt → 500 + audit (webhook_secret_not_configured), géén leak
     *  - signature mismatch → 401 + lege body + GEEN audit-row (anti-amplification)
     *  - valid → next handler
     *
     * Config-driven: header-name + algo via services.snelstart.webhook_*.
     */
    final class VerifySnelstartSignature
    {
        public function handle(Request $request, Closure $next): Response
        {
            $primary = config('services.snelstart.webhook_secret');
            $secondary = config('services.snelstart.webhook_secret_next');
            $headerName = (string) config('services.snelstart.webhook_signature_header', 'X-SnelStart-Signature');
            $algo = (string) config('services.snelstart.webhook_signature_algo', 'sha256');

            $secrets = array_values(array_filter(
                [$primary, $secondary],
                static fn (?string $s): bool => is_string($s) && $s !== '',
            ));

            if ($secrets === []) {
                PassThroughCall::create([
                    'direction' => 'inbound',
                    'provider' => 'snelstart',
                    'method' => $request->getMethod(),
                    'path' => $request->path(),
                    'status' => 500,
                    'duration_ms' => 0,
                    'upstream_error' => 'webhook_secret_not_configured',
                ]);

                return response('', 500);
            }

            $rawBody = $request->getContent();
            $headerValue = $request->header($headerName);

            $valid = SnelstartSignatureVerifier::verify($rawBody, $headerValue, $secrets, $algo);

            if (! $valid) {
                // GEEN audit-row — anti-amplification (CONTEXT decision).
                return response('', 401);
            }

            return $next($request);
        }
    }
    ```

    **2. `bootstrap/app.php`** — voeg toe aan de `$middleware->alias([...])` array:

    ```php
    'verify.snelstart.signature' => VerifySnelstartSignature::class,
    ```

    + bovenaan `use App\Http\Middleware\VerifySnelstartSignature;`.

    **3. Test `tests/Feature/VerifySnelstartSignatureMiddlewareTest.php`** — gebruik een test-route `Route::post('/__test/snelstart-webhook', fn () => response('ok'))->middleware('verify.snelstart.signature')` geregistreerd via `$this->beforeApplicationDestroyed` of een `setUp`-routes-block:

    Scenarios met `RefreshDatabase`:
    1. `test_valid_signature_passes_through` — sign body met secret uit config; `postJson(..., body, ['X-SnelStart-Signature' => $sig])` → 200; `PassThroughCall::count() === 0` (geen audit-laag in middleware-tests; controller is out-of-scope)
    2. `test_invalid_signature_returns_401_empty_body` — fout-signature → 401; `$response->content() === ''`; `PassThroughCall::count() === 0`
    3. `test_missing_header_returns_401` — geen `X-SnelStart-Signature`-header → 401; geen audit
    4. `test_missing_secret_returns_500_with_audit_row` — `config(['services.snelstart.webhook_secret' => null, 'services.snelstart.webhook_secret_next' => null])`; → 500; `PassThroughCall::inbound()->where('upstream_error', 'webhook_secret_not_configured')->count() === 1`
    5. `test_rotation_window_accepts_secondary_secret` — body gesigneerd met `secret_next`-waarde; primary actief; verifier accepteert via array-pad → 200
    6. `test_custom_header_name_works` — `config(['services.snelstart.webhook_signature_header' => 'X-Custom-Sig'])`; verifier zoekt op `X-Custom-Sig`; default-header (`X-SnelStart-Signature`) wordt genegeerd

    Run pint + `php artisan test --compact --filter=VerifySnelstartSignatureMiddlewareTest`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=VerifySnelstartSignatureMiddlewareTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "final class VerifySnelstartSignature" app/Http/Middleware/VerifySnelstartSignature.php` == 1
    - `grep -c "'verify.snelstart.signature'" bootstrap/app.php` == 1
    - `grep -c "use App\\\\Http\\\\Middleware\\\\VerifySnelstartSignature" bootstrap/app.php` == 1
    - `grep -c "webhook_secret_not_configured" app/Http/Middleware/VerifySnelstartSignature.php` >= 1
    - `php artisan test --compact --filter=VerifySnelstartSignatureMiddlewareTest` exit 0, ≥6 tests passed
    - `php artisan route:list --except-vendor` exit 0 (geen alias-registratie-fouten)
  </acceptance_criteria>
  <done>Middleware gedraagt zich correct in 6 scenarios; alias geregistreerd; bestaande middleware-aliases intact.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Public internet ↔ Hub `/webhooks/snelstart` | Eerste laag van defense; signature is de auth. Geen Sanctum, geen `throttle:api` (Snelstart kan bursten). |
| Hub config ↔ Snelstart secret | `SNELSTART_WEBHOOK_SECRET` in env; raw value mag niet in logs/audit/error-messages verschijnen. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05c-04 | Spoofing | Forged HMAC | mitigate | Timing-safe `hash_equals` in verifier; geen `===`-vergelijking. 401 leakt geen detail over miss/mismatch. |
| T-05c-05 | Information disclosure | Audit-row bij invalid signature | mitigate | Géén audit-write op invalid-pad — voorkomt amplification waarbij aanvaller via 5xx-DB-fouten kan unique IDs leren. |
| T-05c-06 | Denial of service | Burst van forged requests | accept | `throttle:api` bewust uit — verifier is goedkoop (sha256 over ~few-KB body). Future: Redis-counter per remote-IP, alleen alerting; geen 429. |
| T-05c-07 | Elevation of privilege | Lege/null secret laat alles door | mitigate | Hardfail-guard returnt 500 + audit + stop voor verifier wordt aangeroepen (analoog Mollie D-08 stap 1). |
| T-05c-08 | Tampering | Secret-rotatie vergeet primary uit env | accept | Operator-fout; documenteer in runbook (later phase). Verifier accepteert beide tijdens window. |
</threat_model>

<verification>
- Verifier-class: 6+ unit-tests groen
- Middleware: 6+ feature-tests groen
- `php artisan config:show services.snelstart` toont alle 5 webhook-keys (`webhook_secret`, `webhook_secret_next`, `webhook_signature_header`, `webhook_signature_algo`, `webhook_event_id_key`)
- `php artisan route:list --except-vendor` exit 0 (geen alias-misconfig)
- Pint clean
</verification>

<success_criteria>
- `App\Webhooks\SnelstartSignatureVerifier` bestaat als final class met `verify()` + `sign()`
- `verify.snelstart.signature`-alias gemount in `bootstrap/app.php`
- Hardfail-guard (geen secret → 500 + audit), invalid-HMAC (→ 401 zonder audit), valid-HMAC (→ next) zijn alle drie getest
- Volledige Hub-testsuite groen (geen regressie op bestaande middleware-tests)
</success_criteria>

<output>
Na completion: schrijf `.planning/phases/05c-snelstart-webhook-handler/05c-02-SUMMARY.md`; vermeld de 4 config-keys + de alias-naam zodat plan 03 ze direct kan toepassen op de route.
</output>
