<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The good news email: payment verified, court locked in.
 */
class BookingConfirmedMail extends BookingMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Your booking is confirmed — %s', (string) $this->booking->code),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.confirmed',
            text: 'emails.booking.text.confirmed',
            with: [
                ...$this->payload(),
                'statusUrl' => $this->statusUrl(),
            ],
        );
    }

    private function statusUrl(): ?string
    {
        try {
            return route('public.booking.status', $this->booking->code);
        } catch (\Throwable) {
            return null;
        }
    }
}
