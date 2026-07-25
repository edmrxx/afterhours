<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Payment could not be verified. Tone matters here: explain, give the reason
 * when there is one, and point the customer at a way forward.
 */
class BookingRejectedMail extends BookingMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('About your booking %s', (string) $this->booking->code),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.rejected',
            text: 'emails.booking.text.rejected',
            with: [
                ...$this->payload(),
                'reason' => $this->reason(),
                'rebookUrl' => $this->rebookUrl(),
            ],
        );
    }

    private function reason(): ?string
    {
        $reason = trim((string) $this->booking->rejection_reason);

        return $reason === '' ? null : $reason;
    }

    private function rebookUrl(): ?string
    {
        try {
            return route('public.courts.index');
        } catch (\Throwable) {
            return null;
        }
    }
}
