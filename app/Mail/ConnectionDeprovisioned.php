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
 * Interne melding: een eindgebruiker heeft de koppeling beëindigd via de
 * partner-kant (Exact App Center "Niet meer gebruiken"). De bevestiging naar
 * de eindgebruiker zelf is aan de consumer-app (via de connection.revoked-
 * fanout) — de Hub kent bewust geen eindgebruiker-PII. Queued: mail mag de
 * Octane-worker niet blokkeren.
 */
class ConnectionDeprovisioned extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Connection $revokedConnection) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Koppeling beëindigd via App Center — '.$this->revokedConnection->provider->value.' #'.$this->revokedConnection->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.connection-deprovisioned',
        );
    }
}
