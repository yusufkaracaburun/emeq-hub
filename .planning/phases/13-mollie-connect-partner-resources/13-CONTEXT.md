# Phase 13: Mollie Connect partner-resources — Context

**Gathered:** 2026-05-18
**Status:** Ready for planning (autonomous synthesis — geen interactieve discuss-phase, user-instructie "no questions")
**Requirements:** MOLL-05, MOLL-06
**Depends on:** Phase 4 (OAuth-broker + `MollieConnectOAuthFlow`), Phase 5a (pass-through-pattern + `AbstractMolliePassThroughController` + `MollieUpstreamErrorMapper`), Phase 11 (geen directe blokker, maar SDK-versie-baseline is `emeq/snelstart-api` v0.2.0; Mollie-SDK blijft op huidige versie)

<domain>
## Phase Boundary

Een Consumer (host-app zoals Naschool) kan via de Hub een Mollie-Connect-merchant onboarden zonder rechtstreeks met Mollie's partner-API te praten. De Hub exposeert vijf pass-through-resources onder `/v1/mollie/connect/*` die de **partner-access-token** van Emeq gebruiken (niet de Connection-access-token van een individuele merchant). Alle 5 resources zitten achter Sanctum (Bearer + `mollie:write` of `mollie:read` ability) en delen het Phase-5a-pattern: idempotency-forward, `MollieUpstreamErrorMapper`, audit naar `pass_through_calls`, Scramble OpenAPI-rendering.

**In scope (5 resources):**

| Route | Mollie-endpoint | Hub-ability | Token-type |
|---|---|---|---|
| `POST /v1/mollie/connect/client-links` | `POST /v2/client-links` | `mollie:write` | **partner** |
| `GET  /v1/mollie/connect/onboarding/me` | `GET /v2/onboarding/me` | `mollie:read` | **partner** (de "me" = Emeq-platform) |
| `GET  /v1/mollie/connect/organizations/me` | `GET /v2/organizations/me` | `mollie:read` | **partner** |
| `GET  /v1/mollie/connect/organizations/{id}` | `GET /v2/organizations/{id}` | `mollie:read` | **partner** |
| `GET  /v1/mollie/connect/profiles` | `GET /v2/profiles` | `mollie:read` | **partner** |
| `POST /v1/mollie/connect/profiles` | `POST /v2/profiles` | `mollie:write` | **partner** |
| `GET  /v1/mollie/connect/profiles/{id}` | `GET /v2/profiles/{id}` | `mollie:read` | **partner** |
| `GET  /v1/mollie/connect/permissions` | `GET /v2/permissions` | `mollie:read` | **partner** |
| `GET  /v1/mollie/connect/permissions/{id}` | `GET /v2/permissions/{id}` | `mollie:read` | **partner** |

(Exacte route-paden te valideren bij planning tegen de Mollie-Connect-docs sectie van `packages/mollie-api/docs/partners/oauth-overview.md`; bovenstaande is best-effort uit Phase-5a-pattern + Mollie's REST-conventies.)

**Niet in 13:**
- Per-Consumer partner-tokens — v0.3 = één Emeq-platform-token via env (`MOLLIE_PARTNER_ACCESS_TOKEN`); per-Consumer-rotatie pas wanneer een tweede SaaS-app productie-friction toont.
- Filament-admin-UI om de partner-token te roteren — manueel via env voor v0.3.
- Connect-onboarding-UI of self-service-flow voor merchants — alleen de pass-through-routes; de daadwerkelijke onboarding-UI is Consumer-side (Naschool of een toekomstige Hub-portal).
- `X-Emeq-Account-Id` header op `/v1/mollie/connect/*` — Connect-routes hebben géén Account-context (de Emeq-platform is de caller, niet een merchant).
- Webhook-routes voor Connect-events (organization.*, profile.*) — Mollie levert ze, maar v0.3 heeft geen Consumer-fan-out-use-case. Backlog.
- Refactor of consolidatie van de bestaande Phase-5a `/v1/mollie/*` routes — Phase 13 voegt alleen `/connect/`-sub-prefix toe; bestaande merchant-routes blijven onveranderd.

</domain>

<decisions>
## Implementation Decisions

### Token-resolver

- **D-01: Route-prefix bepaalt token-type.** `/v1/mollie/connect/*` → partner-access-token. Alle bestaande `/v1/mollie/*`-routes (Phase 5a) blijven Connection-access-token. Geen per-route lookup-table — de routing-laag is de single source of truth. Voordeel: een toekomstige Connect-resource toevoegen = nieuwe route onder `/connect/`, geen resolver-config-edit.

- **D-02: Nieuwe service `App\Mollie\MollieAccessTokenResolver`** met één publieke methode `resolveFor(string $tokenType): string` waar `$tokenType ∈ {'partner', 'connection'}`. `'partner'` leest `config('services.mollie.partner_access_token')` (env `MOLLIE_PARTNER_ACCESS_TOKEN`); `'connection'` delegeert naar `app(MollieConnectionContext::class)->current()->access_token` (via `HubMollieCredentialResolver` voor lazy refresh). Resolver gooit `MissingPartnerTokenException` als partner-token niet geconfigureerd is — geen silent-fallback naar Connection-token (security-fence).

- **D-03: Twee abstract base-controllers, gescheiden hiërarchie.** Phase 5a heeft `AbstractMolliePassThroughController` (Connection-scoped). Phase 13 introduceert `AbstractMollieConnectPassThroughController` die:
  - Géén `MollieConnectionContext` aanroept.
  - Geen `ResolveMollieAccount`-middleware nodig heeft (route-group draait zonder die middleware).
  - Vóór elke SDK-call: `Mollie::client()->setAccessToken($resolver->resolveFor('partner'))`.
  - Idempotency-forward + error-mapping + audit-log via dezelfde traits/helpers als de Phase-5a-base (waar mogelijk extracten naar shared traits — anders gewoon kleine code-duplicatie accepteren; chirurgische DRY > generieke abstract-explosion).
  - **Niet** generieke base-class voor beide paden bouwen tenzij na implementatie ≥3 echt-gedeelde methods overblijven; voorkomt premature abstractie.

### Partner-token bron

- **D-04: Statische env-var `MOLLIE_PARTNER_ACCESS_TOKEN`** in `.env` + `config/services.php` key `mollie.partner_access_token`. Encrypted-at-rest niet van toepassing (env-file is buiten DB; productie-secret-management via Laravel Cloud env-vault). Audit-log slaat alleen `sha256($token)[0..12]` als `partner_token_fingerprint` op — nooit raw.

- **D-05: Boot-time validatie.** `MollieServiceProvider` of `App\Providers\AppServiceProvider::boot()` controleert: als `MOLLIE_PARTNER_ACCESS_TOKEN` ontbreekt EN er bestaat een geregistreerde route met prefix `mollie/connect`, log een `warning` (geen exception). Reden: dev-omgevingen zonder partner-token mogen booten; pas eerste call faalt met 503 `partner_token_missing` (mapped error-code).

- **D-06: Geen "partner Connection"-row in DB voor v0.3.** Een dedicated `Connection { purpose: 'partner', provider: 'mollie' }` is overkill bij één Emeq-platform-token. Promote naar DB-laag wanneer (a) tweede Consumer eigen partner-token nodig heeft, of (b) token-rotatie via UI gewenst.

### Route-group + middleware

- **D-07: Nieuwe route-group in `routes/api.php`** ná de bestaande Mollie-merchant-routes:
  ```php
  Route::middleware('auth:sanctum')->prefix('mollie/connect')->name('mollie.connect.')->group(function () {
      // Connect-routes, géén resolve.mollie.account middleware
  });
  ```
  Sanctum-ability-checks via abilities-middleware per route of in base-controller (zelfde patroon als Phase 5a).

- **D-08: Pennant feature-flag `provider:mollie` blijft de kill-switch.** De bestaande `EnsureProviderEnabled` middleware op `/v1/mollie/*` (vanaf Phase 8) dekt automatisch ook `/v1/mollie/connect/*` zolang we het Pennant-key `provider:mollie` houden. Geen aparte `provider:mollie-connect`-flag — Connect-resources gaan met merchant-routes mee als provider down is.

### Idempotency + error-mapping + audit

- **D-09: Hergebruik Phase 5a `MollieUpstreamErrorMapper` zonder wijziging.** Mollie's Connect-endpoints retourneren dezelfde exception-types (`ValidationException`, `AuthenticationException`, etc.) — de mapper-tabel uit `mollie-passthrough-api.md` ADR dekt ze 1-op-1.

  Eén toevoeging: `MissingPartnerTokenException` (nieuwe Hub-exception) → 503 `partner_token_missing`. Niet 500, want het is een configuratie-issue (Hub-side fix), niet een upstream-fout.

- **D-10: Idempotency-Key auto-forward identiek aan Phase 5a.** Consumer-header → SDK `withIdempotencyKey()` → Mollie. Geen wijziging aan de keygen-binding (`UuidV7IdempotencyKeyGenerator`).

- **D-11: Audit-log naar `pass_through_calls`.** Provider = `'mollie'`. Toevoegen: kolom `token_type` (`'partner' | 'connection'`) in een nieuwe forward-only migration — geeft eerste-klas-zicht in admin-paneel + queries op partner-token-usage. Plus `partner_token_fingerprint` voor partner-rijen (Connection-rijen blijven `request_fingerprint` zoals nu). `connection_id`-kolom is `nullable` voor partner-rijen.

  **Alternatief overwogen:** geen schema-change, gebruik `metadata` jsonb-kolom. Verworpen: querybaarheid (admin wil "alle partner-calls vandaag" als snelle filter, niet als jsonb-extract).

### OpenAPI / Scramble grouping

- **D-12: Scramble-tag override per controller via `@tags`-docblock OF dedicated `OperationTransformer`.** De tag-naam = `Mollie · Connect`. Implementatie-keuze bij planning na inspectie van `config/scramble.php` + bestaande tag-overrides voor `Mollie · Payments` etc.

  **Acceptance:** `/docs/api`-renderen toont `Mollie · Connect` als eigen sectie tussen `Mollie · …`-merchant-groepen; SCRAMBLE-NESTED-GROUPS (backlog) is **niet** vereist voor v0.3 (we hebben nog steeds ≤4 providers).

### ADR

- **D-13: Nieuwe ADR `.docs/decisions/mollie-connect-partner-resources.md`** (NIET `mollie-passthrough-api.md` uitbreiden). Reden: token-resolver-keuze + route-prefix-conventie + partner-token-bron zijn een eigen subject; bevuilen van de pass-through-ADR maakt 'm minder leesbaar voor toekomstige providers. ADR cross-refereert `mollie-passthrough-api.md` als baseline.

  ADR-inhoud (sections per project-conventie): Status / Keuze (route-prefix-rule + resolver-shape + env-token) / Context / Alternatieven afgewogen / Consequences.

  Per `.ai/rules` werkdocument-conventie: ADR leeft lokaal in `.docs/decisions/` (gitignored), traceability via gecommitte SUMMARY + STATE.md Resolved Blockers.

### Sanctum-abilities

- **D-14: Bestaande `mollie:read` / `mollie:write` abilities dekken ook Connect-routes.** Geen aparte `mollie-connect:*` abilities. Reden: een Consumer die Mollie mag aanroepen, mag ook Mollie-Connect aanroepen — er is geen scenario waar je merchant-Mollie wel mag en Connect-Mollie niet. Vereenvoudigt PAT-issuance.

### Tests

- **D-15: Test-strategie.**
  - Per route één feature-test in `tests/Feature/Api/V1/Mollie/Connect/<Resource>Test.php`.
  - `MollieAccessTokenResolverTest` (unit) dekt: (a) `'partner'` returnt config-value, (b) `'connection'` returnt context-token, (c) ontbrekende partner-token gooit exception, (d) Connection-context-leeg gooit exception.
  - **Integration-test "beide paden expliciet"** (MOLL-06 success criterion #2): één test in `tests/Feature/Api/V1/Mollie/Connect/TokenResolverIntegrationTest.php` die:
    1. Een merchant-route (bv. `GET /v1/mollie/payments`) hit en assert dat de `Mollie::client()` met de Connection-token werd geconfigureerd (spy/mock).
    2. Een Connect-route (bv. `GET /v1/mollie/connect/permissions`) hit en assert partner-token werd gebruikt.
  - Mock-strategie: `MollieApiClient::fake()` met een spy die de gebruikte access-token-header capture't.

### Claude's Discretion

- Exacte controller-shape per Connect-resource (single-action vs resource-controller met `index/show/store`) — kies wat het cleanst is per resource. Permissions/Profiles vermoedelijk resource-controller; ClientLinks/Onboarding single-action.
- Form-Request-laag per write-endpoint: ClientLinks (`POST /v2/client-links` accepteert `owner.email`, `owner.givenName`, `owner.familyName`, `name`, `address.*`) en Profiles (`POST /v2/profiles` accepteert `name`, `website`, `email`, `phone`, `businessCategory`). Mollie's docs zijn leidend voor velden — Form Request valideert minimaal `required` + types.
- Of `MollieAccessTokenResolver` ook door bestaande Phase-5a-controllers gebruikt wordt (refactor `AbstractMolliePassThroughController` om via resolver i.p.v. directe `Mollie::client()`) — voorkeur: ja in dezelfde phase voor consistentie, mits het minimale change is. Anders backlog.
- ADR-bestandsnaam confirmeren (`mollie-connect-partner-resources.md` voorgesteld); kortere naam mag.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plan-source + scope (autoritief)
- `.planning/ROADMAP.md` §"Phase 13: Mollie Connect partner-resources" — goal, 4 success criteria
- `.planning/REQUIREMENTS.md` — MOLL-05 + MOLL-06
- `.docs/decisions/mollie-passthrough-api.md` — LOCKED baseline pass-through-pattern + error-envelope-tabel

### Architectuur-invariants
- `.ai/rules/global.md` — tokens encrypted-at-rest, fingerprint-only in logs, multi-tenant Consumer↔Account↔Connection chain
- `.ai/rules/engineering.md` — chirurgisch wijzigen, conflicten oppervlakken, lezen vóór schrijven
- `.ai/project` rules — geen Hub-domein in SDK; geen verzonnen partner-features
- `CLAUDE.md` — Mollie-docs leven in `packages/mollie-api/docs/partners/` (SDK-redistributability per v0.2 refactor)

### Mollie partner-docs (lokaal aanwezig)
- `packages/mollie-api/docs/partners/oauth-overview.md` — partner-vs-merchant scope-tabel, OAuth-endpoint-paden
- `packages/mollie-api/docs/partners/api-idempotency.md` — Idempotency-Key-header-semantiek (= identiek voor Connect-endpoints)
- `packages/mollie-api/docs/partners/errors.md` — Mollie's foutcode-tabel
- `packages/mollie-api/docs/partners/README.md` — index

**Te raadplegen tijdens planning (online Mollie-docs of fetchen indien lokaal ontbreekt):**
- https://docs.mollie.com/reference/v2/client-links-api/create-client-link (request-body-shape)
- https://docs.mollie.com/reference/v2/onboarding-api/get-onboarding-status
- https://docs.mollie.com/reference/v2/organizations-api/get-organization (path-variants `/me` en `/{id}`)
- https://docs.mollie.com/reference/v2/profiles-api/list-profiles + create-profile + get-profile
- https://docs.mollie.com/reference/v2/permissions-api/list-permissions + get-permission

### Hub-substrate (Phase 4 + 5a output — fundering)
- `app/Mollie/MollieConnectionContext.php` — bestaande context-service, blijft voor `/v1/mollie/*` (merchant-pad)
- `app/Mollie/HubMollieCredentialResolver.php` — lazy refresh-laag, blijft ongewijzigd
- `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` — pattern-template voor Connect-equivalent (NIET kopiëren — extracten naar shared trait waar mogelijk)
- `app/Support/Mollie/MollieUpstreamErrorMapper.php` — hergebruiken zonder wijziging (D-09)
- `app/Support/Mollie/MollieHeaderForwarder.php` — Connect-routes hebben dezelfde whitelist (te bevestigen tegen Mollie Connect-headers bij planning)
- `app/Http/Middleware/ResolveMollieAccount.php` — **niet** op Connect-routes plakken (D-07)
- `app/Sanctum/TokenAbilities.php` — bestaande `MOLLIE_READ` + `MOLLIE_WRITE` constants (D-14)
- `app/Providers/AppServiceProvider.php` — uitbreiden met `MollieAccessTokenResolver`-binding (singleton) + partner-token-config-validation
- `config/services.php` — nieuwe key `mollie.partner_access_token` (env `MOLLIE_PARTNER_ACCESS_TOKEN`)
- `routes/api.php` — uitbreiden met Connect-route-group (D-07)
- `bootstrap/app.php` — `feature.provider:mollie` middleware-alias dekt automatisch nieuwe route-group via Pennant-feature-key (D-08)
- `config/scramble.php` — tag-grouping voor `Mollie · Connect` (D-12)
- `database/migrations/<datum>_add_token_type_to_pass_through_calls_table.php` — nieuwe forward-only migration (D-11)

### Sibling phase-context (referentie-pattern)
- `.planning/milestones/v0.2-phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md` — Phase 5a-pattern (D-01 t/m D-15) waar Phase 13 op aansluit
- `.planning/milestones/v0.2-phases/04-mollie-connect-oauth-broker/04-CONTEXT.md` — Phase 4 OAuth-broker (D-13/D-14/D-16: contract-grenzen `OAuthFlow` vs credential-resolvers)

### Test-conventies
- `tests/Feature/Api/V1/Mollie/` — bestaande Mollie-feature-test-folder, sub-folder `Connect/` toevoegen
- `tests/Concerns/` — eventuele helper-trait `BindsMollieConnectPartnerToken` mag hier landen als geneste tests 'm hergebruiken

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`AbstractMolliePassThroughController` (Phase 5a)** — referentie voor: tenant-resolutie-trigger (skippen in Connect-base), exception-mapping-flow, idempotency-extraction, audit-write. Connect-base extract't of dupliceert het minimum aan logic.
- **`MollieUpstreamErrorMapper`** — pakt Mollie's exception-hiërarchie 1-op-1; Connect-endpoints werpen dezelfde types. Geen wijziging.
- **`MollieHeaderForwarder`** — Mollie's `If-None-Match`/`If-Match`-pad is voor merchant-resources; Connect-resources gebruiken (vermoedelijk) dezelfde header-set. Te bevestigen.
- **`pass_through_calls`-tabel + `PassThroughCall`-model** — provider-agnostisch; uitbreiden met `token_type` + nullable `partner_token_fingerprint`.
- **`Mollie::client()->setAccessToken($token)`** — Mollie SDK accepteert per-call access-token-swap. Connect-base bouwt de client met partner-token vóór elke call (of bindt 'n scoped instance).
- **`OAuthFlowRegistry`** — niet gebruikt door Connect-pad (geen OAuth-refresh-cyclus voor partner-token; partner-token is long-lived). Genoemd hier ter referentie: blijft voor merchant-pad.

### Established Patterns

- **Per-resource controllers in `app/Http/Controllers/Api/V1/Mollie/<Resource>Controller.php`** — Connect-equivalent: `app/Http/Controllers/Api/V1/Mollie/Connect/<Resource>Controller.php`.
- **Form Requests in `app/Http/Requests/Api/V1/Mollie/`** → `app/Http/Requests/Api/V1/Mollie/Connect/<CreateResource>Request.php`.
- **Support-laag in `app/Support/Mollie/`** — `MollieAccessTokenResolver` past hier? Of in `app/Mollie/`? Voorkeur: `app/Mollie/MollieAccessTokenResolver.php` (siblings: `MollieConnectionContext`, `HubMollieCredentialResolver`).
- **Tests in `tests/Feature/Api/V1/Mollie/`** + sub-folder `Connect/`.
- **ADR-conventie**: `.docs/decisions/<topic>.md`, lokaal-only (gitignored per `CLAUDE.md`).

### Integration Points

- **`routes/api.php`**: nieuwe Connect-route-group ná bestaande Mollie-merchant-block.
- **`config/services.php`**: nieuwe `mollie.partner_access_token` key.
- **`app/Providers/AppServiceProvider.php`**: binding voor `MollieAccessTokenResolver` (singleton); boot-warning bij ontbrekende partner-token (D-05).
- **`config/scramble.php`**: tag-grouping uitbreiden voor `Mollie · Connect`.
- **Pennant-feature `provider:mollie`**: dekt Connect-routes automatisch via `EnsureProviderEnabled`-middleware-alias (geen wijziging).
- **Filament admin-paneel (Phase 9 HUB-04)**: `PassThroughCallResource` of `ConnectionStatsWidget` kan nieuwe `token_type`-kolom als filter exposen — optioneel polish, niet vereist voor Phase 13 closure.

</code_context>

<specifics>
## Specific Ideas

- **MollieAccessTokenResolver-shape (suggested):**
  ```php
  final class MollieAccessTokenResolver
  {
      public function __construct(
          private readonly MollieConnectionContext $context,
          private readonly string|null $partnerToken,
      ) {}

      public function resolveFor(string $tokenType): string
      {
          return match ($tokenType) {
              'partner' => $this->partnerToken ?? throw new MissingPartnerTokenException(),
              'connection' => $this->context->current()?->access_token
                  ?? throw new MissingConnectionContextException(),
              default => throw new \InvalidArgumentException("Unknown token type: {$tokenType}"),
          };
      }
  }
  ```

  Binding in `AppServiceProvider::register()`:
  ```php
  $this->app->singleton(MollieAccessTokenResolver::class, fn () => new MollieAccessTokenResolver(
      $this->app->make(MollieConnectionContext::class),
      config('services.mollie.partner_access_token'),
  ));
  ```

- **AbstractMollieConnectPassThroughController-skelet:**
  ```php
  abstract class AbstractMollieConnectPassThroughController extends Controller
  {
      public function __construct(
          protected readonly MollieAccessTokenResolver $tokenResolver,
          protected readonly MollieUpstreamErrorMapper $errorMapper,
          protected readonly PassThroughCallWriter $audit,
      ) {}

      protected function client(): MollieApiClient
      {
          $client = app(MollieApiClient::class); // of Mollie::client(), te checken
          $client->setAccessToken($this->tokenResolver->resolveFor('partner'));
          return $client;
      }

      protected function dispatchMollieCall(callable $fn): mixed { /* try/catch + map */ }
  }
  ```

- **SC-2 integration-test-skelet (beide paden):**
  ```php
  // TokenResolverIntegrationTest::test_merchant_route_uses_connection_token
  $connection = Connection::factory()->mollie()->active()->create([...]);
  $pat = $consumer->createToken('test', ['mollie:read'])->plainTextToken;
  MollieApiClient::fake(spy: $spy = new TokenCapturingSpy());
  $this->withToken($pat)->getJson('/v1/mollie/payments', ['X-Emeq-Account-Id' => ...]);
  $this->assertSame($connection->access_token, $spy->lastUsedAccessToken);

  // TokenResolverIntegrationTest::test_connect_route_uses_partner_token
  config(['services.mollie.partner_access_token' => 'access_partner_xyz']);
  $this->withToken($pat)->getJson('/v1/mollie/connect/permissions');
  $this->assertSame('access_partner_xyz', $spy->lastUsedAccessToken);
  ```

- **SC-1 happy-path-bewijs per resource:** `POST /v1/mollie/connect/client-links` met realistische payload (`owner.email`, `name`) tegen `MollieApiClient::fake()` → assert response heeft `_links.clientLink.href` en HTTP 201.

- **Migration-shape (D-11):**
  ```php
  Schema::table('pass_through_calls', function (Blueprint $table) {
      $table->string('token_type', 16)->nullable()->after('provider')->index();
      $table->string('partner_token_fingerprint', 16)->nullable()->after('request_fingerprint');
  });
  ```
  Forward-only; bestaande rijen krijgen NULL (= implicit `'connection'` per oudere semantiek).

- **Scramble-tag-aanpak (D-12, optie A):** controller-class-level docblock `@tags Mollie · Connect`. Optie B: `Scramble::registerOperationTransformer(...)` in `AppServiceProvider`. Bij planning checken welke patroon Phase 5a gebruikt voor `Mollie · …`-groepen — consistent blijven.

- **MissingPartnerTokenException-mapping:** `App\Exceptions\Mollie\MissingPartnerTokenException` extends `\RuntimeException`. `MollieUpstreamErrorMapper` (of nieuwe `MollieConnectErrorMapper`) mapt naar 503 + body `{ error: 'partner_token_missing', message: 'Mollie partner-access-token niet geconfigureerd op Hub. Contact Emeq-staff.' }`.

</specifics>

<deferred>
## Noted for Later

- **Per-Consumer partner-tokens** — promoteren wanneer een tweede SaaS-app (na Naschool) eigen Mollie-Connect-account heeft of Emeq's platform-token niet wil delen.
- **Filament-admin UI voor partner-token rotatie** — manueel via env voldoende voor v0.3.
- **Connect-onboarding-UI of self-service-flow** — backlog. Phase 13 levert alleen de pass-through-routes; Naschool of een Hub-portal bouwt de UI.
- **Webhook-routes voor Connect-events** (`organization.*`, `profile.*`) — Mollie levert ze, maar v0.3 heeft geen Consumer-fan-out-use-case. Promote wanneer Naschool of een andere Consumer een use-case toont.
- **Refactor van `AbstractMolliePassThroughController` om óók via `MollieAccessTokenResolver` te gaan** — als de phase ruimte heeft, meenemen; anders backlog (`MOLL-PT-RESOLVER-REFACTOR`).
- **`pass_through_calls.token_type`-filter in Filament-admin** — UX-polish, niet vereist voor Phase 13 closure.
- **SCRAMBLE-NESTED-GROUPS-trigger** (5+ providers) — Phase 13 voegt geen provider toe; trigger blijft op huidige threshold.
- **OAuth `client_credentials`-grant pad voor partner-token** — alternatief voor statische token (D-04). Promote wanneer Mollie dat aanbeveelt of partner-token-rotatie automatisering vereist.

</deferred>

---

*Phase: 13-mollie-connect-partner-resources*
*Context gathered: 2026-05-18 — autonomous synthesis vanuit Phase 5a/4-CONTEXT + REQUIREMENTS (MOLL-05/06) + Mollie-partner-docs (`packages/mollie-api/docs/partners/oauth-overview.md`). Geen interactieve discuss-phase per user-instructie "no questions".*
