<x-mail::message>
# Nieuwe koppel-aanvraag

Er is een nieuwe aanvraag binnengekomen via de publieke /koppelen-pagina.

- **Bedrijf:** {{ $accessRequest->company }}
- **Contact:** {{ $accessRequest->contact_name }}
- **E-mail:** {{ $accessRequest->email }}
@if ($accessRequest->app_url)
- **App-URL:** {{ $accessRequest->app_url }}
@endif
- **Integraties:** {{ implode(', ', $accessRequest->providers) }}

@if ($accessRequest->message)
**Bericht:**

{{ $accessRequest->message }}
@endif

<x-mail::button :url="config('app.url').'/admin'">
Bekijk in admin
</x-mail::button>

Onboard via de OnboardConsumer-wizard.
</x-mail::message>
