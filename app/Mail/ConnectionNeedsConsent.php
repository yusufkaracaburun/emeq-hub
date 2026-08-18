<?php

namespace App\Mail;

use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConnectionNeedsConsent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Connection $needsConsentConnection) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Koppeling vraagt om her-consent — '.$this->needsConsentConnection->provider->value.' #'.$this->needsConsentConnection->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.connection-needs-consent',
        );
    }
}
