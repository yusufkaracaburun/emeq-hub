<?php

namespace App\Mail;

use App\Models\DemoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DemoRequestSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public DemoRequest $demoRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nieuwe demo-aanvraag — '.$this->demoRequest->company,
            replyTo: [new Address($this->demoRequest->email, $this->demoRequest->contact_name)],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.demo-request-submitted',
        );
    }
}
