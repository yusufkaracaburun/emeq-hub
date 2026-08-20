# Global Rules — emeq-hub

## Taal

- Code, identifiers, technische comments: **Engels** (API-vocabulair, OSS-pattern).
- Commit-messages, PR-beschrijvingen, planning-docs, conversatie: **Nederlands**.
- Domeintermen volgen de partner-API: Snelstart spreekt Nederlands (Relaties, Verkoopfacturen) — niet vertalen. Mollie spreekt Engels (Payments, Customers) — niet vertalen.

## Anti-AI cliché's

Vermijd vaste AI- en template-zinnen in commits, docs, en code-comments:

- "In this article" / "Laten we eens kijken" / "It is important to"
- "Furthermore" / "Moreover" / "Daarnaast" / "Bovendien" (mechanisch als opener)
- "Discover how" / "Transform" / "Seamless" / "Naadloos"
- "Innovative solution" / "In the modern world" / "Efficient and effective"
- "Custom-made" / "All-in-one" / "Revolutionary" / "Game-changer"
- "Dive in" / "Explore" / "Unlock" / "Empower"

## Geen verzonnen partner-features

- Wat in code of docs staat over een partner-API (Snelstart, Mollie, Moneybird, Ibanity, Exact) moet **exact** kloppen met hun officiële documentatie of OpenAPI spec.
- Geen "vermoedelijke" endpoints, geen verzonnen response-velden, geen aangenomen rate-limits. Bij twijfel: vraag de user of fetch de docs.

## Security

- **Tokens, clientKeys, subscriptionKeys** worden **versleuteld at rest** opgeslagen (`encrypted` cast op Eloquent properties).
- Raw secrets verschijnen **nooit** in logs, exception-messages, of error responses. Gebruik fingerprints (sha256, eerste 12 chars) voor debugging.
- Webhook-secrets per Connection — niet één globale secret per Consumer.

## OAuth & API integratie

- OAuth2-flows volgen RFC 6749 tenzij de partner expliciet anders documenteert (Snelstart's `grant_type=clientkey` is een gedocumenteerde afwijking).
- Refresh-tokens automatisch verlengen ruim vóór expiry — niet wachten op een 401.
- Per partner één `OAuthFlow`-implementatie; geen ad-hoc curls in controllers.

## Multi-tenant scope

- Connection-resolution gebeurt via **explicit** Consumer-token + Connection-ID — nooit impliciet via session of current_user.
- Een Connection hoort bij precies één Account die hoort bij precies één Consumer. Cross-consumer-leakage = security incident.
