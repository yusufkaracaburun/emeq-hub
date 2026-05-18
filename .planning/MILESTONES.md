# Milestones — Emeq integration stack

Shipped versies, oudste eerst.

## v0.1 — Snelstart-SDK finale (2026-05-14)

**Status:** ✅ SHIPPED
**Archive:** [`.planning/milestones/v0.1-ROADMAP.md`](milestones/v0.1-ROADMAP.md) · [`.planning/milestones/v0.1-REQUIREMENTS.md`](milestones/v0.1-REQUIREMENTS.md)
**Git tag:** `v0.1`

**Delivered:**

- `emeq/snelstart-api` SDK met Pest-suite groen (107 passed / 187 assertions)
- Public op `github.com:yusufkaracaburun/emeq-snelstart-api`, `main` @ `16c9ecc`, upstream-tracking actief
- VCS-installeerbaar zonder authenticatie via composer-VCS-repository-entry (bewezen via fresh smoke-test)
- `SnelstartConnectorTest` her-schreven met directe `getRequestException()`-coverage (MockClient-pipeline gedropt)

**Key Decisions:**

- Drop Saloon `MockClient`-pipeline voor exception-mapping; directe PHPUnit-mocks op `Response` zijn schoner
- VCS-distributie zonder auth volstaat voor publieke SDKs in v0.1 — geen private composer-registry
- Snelstart `Dto/` + `Resources/` blijven leeg — OData QueryBuilder + `RawSnelstartRequest` dekken 96 endpoints
- **2026-05-14 scope-herziening:** v0.1 teruggebracht tot Snelstart-only; Mollie + Connect + Subscriptions + Hub naar v0.2 (~8-10 weken, plan in `.claude/plans/fancy-honking-spring.md`)

**Stats:**

- 1 phase · 3 plans · 19 commits · ~3300 LOC toegevoegd
- Timeline: 2026-05-14 (00:42 → 12:02 CEST, ~12 uur effectieve werk)

**Known Gaps / Deferred:**

- Phase 2 (Mollie-SDK foundation), Phase 3 (Mollie-SDK resources + webhooks), Phase 4 (Naschool wiring — Snelstart), Phase 5 (Naschool wiring — Mollie + flow-test) — verschoven naar v0.2
- 7 v1-requirements (MOLL-01..04, NSCH-01..03) deferred met herzieningen (zie v0.1-REQUIREMENTS.md Carry-forward)

---

## v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton (2026-05-17)

**Status:** ✅ SHIPPED
**Archive:** [`.planning/milestones/v0.2-ROADMAP.md`](milestones/v0.2-ROADMAP.md) · [`.planning/milestones/v0.2-REQUIREMENTS.md`](milestones/v0.2-REQUIREMENTS.md) · [`.planning/milestones/v0.2-MILESTONE-AUDIT.md`](milestones/v0.2-MILESTONE-AUDIT.md)
**Git tag:** `v0.2`

**Delivered:**

- `emeq/mollie-api` SDK gepubliceerd (multi-tenant resolver + dual creds API-key/OAuth, wrapt `mollie/mollie-api-php`)
- Hub-skeleton met `consumers`/`accounts`/`connections`-tabellen + Sanctum-PAT-auth + encrypted-at-rest credentials (Phase 3)
- Mollie Connect OAuth-broker via provider-agnostisch `OAuthFlow`-contract + `MollieConnectOAuthFlow` + lazy-refresh (Phase 4)
- Pass-through REST API live voor beide providers: `/v1/mollie/*` (7 resources, 22 routes, Phase 5a) + `/v1/snelstart/{path}` (Phase 5b) + Snelstart webhook-ingress `/webhooks/snelstart` (Phase 5c, Hub-side klaar; live productie-cert wacht op Snelstart-partner-respons ≤2026-05-26)
- Subscriptions in twee use-cases: Cashier-Mollie voor Emeq→Consumers (Phase 6) + multi-tenant `AccountSubscription` voor Accounts→eindgebruikers via Connect (Phase 7)
- Filament v4 admin-paneel `/admin` met 7 resources + Spatie laravel-permission 2-rol-model + `ProviderCredentialDescriptor` als single source of truth + Pennant feature-flag kill-switch (Phase 9 + Phase 10 polish)
- Phase 8 Hub-side substrate voor Naschool-wiring compleet per D-03 scope-fence (ConsumerOnboarding + StartOAuthFlowAction + partner-pages + onboard-wizard); live E2E door test-ouder + Naschool-repo wiring gedeferred naar v0.3 als `NSCH-LIVE-E2E`

**Key Decisions:**

- ❌ Reversed: eigen Saloon-wrapper voor Mollie → `emeq/mollie-api` wrapt `mollie/mollie-api-php` direct (laravel-mollie multi-tenant afgewezen; eigen Saloon ~70% overhead)
- ❌ Reversed: API-key-only voor Mollie in v0.1 → Mollie Connect vanaf v0.2 dag 1 (Emeq = Mollie Partner)
- 🆕 Subscriptions in twee use-cases (Cashier voor Emeq→Consumers + Connect voor Accounts→eindgebruikers)
- 🆕 `EmeqMollie`-facade naast `Mollie` (Cashier-Mollie) — coexist runtime, geen alias-collision
- 🆕 Provider-agnostisch `OAuthFlow`-contract met `FakeOAuthFlow` als test-fixture bewijst pattern-portability voor v0.3+
- 🆕 `pass_through_calls` als eigen tabel afgesplitst van `webhook_calls` (pass-through ≠ fan-out, ADR `pass-through-calls-table.md`)
- 🆕 Spatie laravel-permission ^6 met 2-rol-model (`super-admin`/`staff`) — drop `is_emeq_staff` boolean
- 🆕 `ProviderCredentialDescriptor` als single source of truth in `config/hub-providers.php` — nieuwe provider = config-row, geen nieuwe Resource-class
- 🆕 Pennant-based provider kill-switch via `feature.provider:{provider}` middleware-alias — auto-gedefinieerd op basis van `config('hub-providers')` keys
- 🆕 HMAC-verifier + middleware naar SDK (`emeq/snelstart-api`) per ADR `sdk-redistributability-boundary.md`
- 🆕 D-03 scope-fence Phase 8: Hub-side only; Naschool-repo werk + live E2E naar v0.3

**Stats:**

- 11 phases · 67 plans · 511 commits · ~498 tests in default suite (+ separate integration-suite) · +100k/-2.5k LOC
- Timeline: 2026-05-14 → 2026-05-17 (~4 dagen high-velocity execution)
- Git range: `9502627` (milestone-start) → `3e267fd` (Phase 5c verifier-close)

**Known Gaps / Deferred:**

- `NSCH-LIVE-E2E` — Naschool-repo composer-VCS-entries + Stancl-resolver + `EnrollmentConfirmed`-listener live wiring + e2e door test-ouder (Hub-side substrate compleet per D-03)
- Snelstart productie-certificering wacht op partner-respons (Gmail draft `r-8836998535038336548` ≤2026-05-26)
- VERIFICATION.md ontbreekt voor Phases 4, 6, 7 (claims via ACCEPTANCE-files; geen formele verifier-audit-artifact)
- 3 deferred Phase 5a human-UAT items gechained aan NSCH-LIVE-E2E
- 1 pre-existing incomplete test (`SanctumAbilityTest` Phase 3-03 placeholder; feitelijk gedekt door Phase 5b acceptance)

---

*Next milestone: v0.3 — start via `/gsd-new-milestone v0.3`. Backlog-kandidaten: `NSCH-LIVE-E2E`, `SNEL-V4`, `MOLL-CONNECT-RES`, andere providers (`emeq/moneybird-api`, `emeq/exact-api`, `emeq/ibanity-api`, `emeq/stripe-api`, `emeq/bizcuit-api`), Hub commerciële features.*
