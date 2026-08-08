<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DemoRequestSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $demoRequest  Gevalideerde velden uit StoreDemoRequestRequest.
     */
    public function __construct(public array $demoRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nieuwe demo-aanvraag — '.$this->demoRequest['company'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.demo-request-submitted',
        );
    }
}
