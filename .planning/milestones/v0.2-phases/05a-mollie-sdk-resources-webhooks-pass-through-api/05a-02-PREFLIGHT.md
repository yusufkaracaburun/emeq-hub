# Plan 05a-02 Pre-flight verifie

## V1 — RateLimitException retry-after
Methode: **geen getter — niet exposed**
Bron: vendor/emeq/mollie-api/src/Exceptions/RateLimitException.php:10-12 (`final class RateLimitException extends MollieException {}` — leeg class-body)

Vendor (Mollie) gebruikt `Mollie\Api\Exceptions\TooManyRequestsException` (vendor/mollie/mollie-api-php/src/Exceptions/TooManyRequestsException.php:8-21) — die exposeert óók geen `getRetryAfter()`-property; de Response zit als `$response` op de parent `ApiException` maar de SDK-wrapper geeft 'm niet door.

**Implicatie Plan 05a-02:** niet van toepassing. Plan 05a-02 raakt geen mapper, alleen de webhook-controller. Mapper (`MollieUpstreamErrorMapper`) is al gepubliceerd in Plan 05a-01 met een leeg `headers`-array voor de 429-pad; consistent. Geen wijziging nodig in 05a-02.

## V2 — Mollie::class singleton/bind
Binding: **singleton**
Bron: vendor/emeq/mollie-api/src/MollieServiceProvider.php:31 (`$this->app->singleton(Mollie::class, function ...)`)

**Cruciale nuance** (vendor/emeq/mollie-api/src/Mollie.php:54-60): `Mollie::client()` bouwt elke call een **verse** `MollieApiClient` via `$client = new MollieApiClient();` (regel 60) — niet een gecachte instance. De singleton houdt enkel de `resolver` + `config` + `container` vast (constructor-deps), niet de client.

`HubMollieCredentialResolver` (Phase 4) is via `bind`, niet `singleton` (per Plan 05a-01 verifieer-punt) — `resolver->resolve()` leest verse context elke aanroep.

**Implicatie Task 2:** **GEEN `forgetInstance(Mollie::class)` nodig** in `MollieWebhookController` na `MollieConnectionContext::set()`. Dezelfde redenering als `ResolveMollieAccount`-middleware (Plan 05a-01). Direct na `set()` mag `Mollie::client()->payments->get($id)` worden aangeroepen.

## V3 — webhook-payload-prefix
Mollie stuurt voor: payments=`tr_*`, subscriptions=**`tr_*` (renewal-payment-id, niet `sub_*`)**, refunds=**`tr_*` (parent-payment-id)**
Bron: `.docs/partners/mollie/webhooks-overview.md` regel 87-88 (anti-spoofing-sectie: *"De webhook-body bevat alleen `{id: 'tr_...'}`"*) + Mollie's webhook-best-practices (legacy en next-gen webhooks gebruiken beide de Payment-id als trigger-id).

**Implicatie Task 2:** **huidige aanname OK voor v0.2**. Anti-spoofing-fetch via `Mollie::client()->payments->get($payload['id'])` blijft correct. Documenteer in controller-docblock dat v0.3+ resource-type-detectie via `id`-prefix (`tr_`/`sub_`/`re_`) moet ondersteunen indien Mollie's next-gen webhooks-API ook subscription-level events met `sub_`-prefix gaat sturen — voor v0.2 zijn alle inkomende webhook-id's Payment-id's.

---

*Pre-flight uitgevoerd: 2026-05-14*
*Geen blokkerende bevindingen — Task 1+2+3 mogen starten.*
