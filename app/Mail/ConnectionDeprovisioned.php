<?php

namespace App\Mail;

use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
