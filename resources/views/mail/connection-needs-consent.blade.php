<x-mail::message>
# Koppeling vraagt om her-consent

Een token-refresh weigerde met `invalid_grant` — Exact heeft de hele
refresh-token-chain ingetrokken. Een volgende refresh-poging lost dit niet
op; alleen een nieuwe consent-flow door een mens herstelt de koppeling.

- **Provider:** {{ $needsConsentConnection->provider->value }}
- **Connection:** #{{ $needsConsentConnection->id }}
- **Consumer:** {{ $needsConsentConnection->account?->consumer?->name ?? 'onbekend' }}
- **Account (extern):** {{ $needsConsentConnection->account?->external_id ?? 'onbekend' }}

Vraag de eindgebruiker opnieuw te koppelen via de connect-handoff.
</x-mail::message>
