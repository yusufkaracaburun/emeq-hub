<x-mail::message>
# Nieuwe demo-aanvraag

Er is een nieuwe aanvraag binnengekomen via de publieke /demo-pagina.

- **Bedrijf:** {{ $demoRequest['company'] }}
- **Contact:** {{ $demoRequest['contact_name'] }}
- **E-mail:** {{ $demoRequest['email'] }}
- **Voorkeursmoment:** {{ $demoRequest['preferred_slot'] }}

@if (! empty($demoRequest['message']))
**Bericht:**

{{ $demoRequest['message'] }}
@endif

Plan de demo in en bevestig per e-mail aan de aanvrager.
</x-mail::message>
