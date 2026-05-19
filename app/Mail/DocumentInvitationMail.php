<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DocumentInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly array $data)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->data['subject_line'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.document-invitation',
            text: 'emails.document-invitation-text',
        );
    }
}
