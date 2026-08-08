<x-mail::message>
# Koppeling beëindigd via het App Center

Een eindgebruiker heeft de koppeling beëindigd via de partner-kant
("Niet meer gebruiken"). De tokens zijn ingetrokken en de webhook-subscriptions
worden opgeruimd; de consumer-app is genotificeerd via de webhook-fanout.

- **Provider:** {{ $revokedConnection->provider->value }}
- **Connection:** #{{ $revokedConnection->id }}
- **Consumer:** {{ $revokedConnection->account?->consumer?->name ?? 'onbekend' }}
- **Account (extern):** {{ $revokedConnection->account?->external_id ?? 'onbekend' }}
- **Ingetrokken op:** {{ $revokedConnection->revoked_at?->format('d-m-Y H:i') }}

Geen actie nodig, tenzij dit onverwacht is — check dan de audit in het
admin-paneel (Inbound webhook events, topic `deprovision`).
</x-mail::message>
