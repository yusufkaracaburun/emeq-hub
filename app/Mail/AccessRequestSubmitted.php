<?php

namespace App\Mail;

use App\Models\AccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccessRequestSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AccessRequest $accessRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nieuwe koppel-aanvraag — '.$this->accessRequest->company,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.access-request-submitted',
        );
    }
}
