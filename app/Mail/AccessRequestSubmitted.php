<?php

namespace App\Mail;

use App\Models\AccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Interne melding van een koppel-aanvraag. Queued: het versturen loopt via een
 * externe API en mag de request in de Octane-worker niet blokkeren.
 */
class AccessRequestSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public AccessRequest $accessRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nieuwe koppel-aanvraag — '.$this->accessRequest->company,
            // Zonder replyTo gaat "beantwoorden" naar het afzenderdomein terug
            // in plaats van naar de aanvrager.
            replyTo: [new Address($this->accessRequest->email, $this->accessRequest->contact_name)],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.access-request-submitted',
        );
    }
}
