<?php

namespace App\Mail;

use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Interne melding: een refresh weigerde met invalid_grant — Exact heeft de
 * hele refresh-token-chain ingetrokken. Alleen een nieuwe consent-flow door
 * een mens herstelt de koppeling; zonder deze mail merkt niemand het tot de
 * volgende API-call faalt.
 */
class ConnectionNeedsConsent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Connection $connection) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Koppeling vraagt om her-consent — '.$this->connection->provider->value.' #'.$this->connection->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.connection-needs-consent',
        );
    }
}
