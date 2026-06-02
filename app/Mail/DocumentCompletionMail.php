<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\Mailables\Envelope;

class DocumentCompletionMail extends Mailable implements ShouldQueue
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

    public function headers(): Headers
    {
        $supportEmail = $this->data['support_email'] ?? config('mail.from.address');

        return new Headers(
            text: [
                'List-Unsubscribe' => sprintf(
                    '<mailto:%s?subject=%s>',
                    $supportEmail,
                    rawurlencode('Unsubscribe from DRS workflow notifications')
                ),
            ]
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.document-completion',
            text: 'emails.document-completion-text',
        );
    }
}
