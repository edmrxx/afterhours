<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the admin the moment a customer submits their payment reference.
 * It is a work item, so it leads with the reference number to check.
 */
class NewBookingSubmittedMail extends BookingMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Payment to verify — %s (%s)',
                (string) $this->booking->code,
                (string) $this->booking->customer_name,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.new-submitted',
            text: 'emails.booking.text.new-submitted',
            with: [
                ...$this->payload(),
                'verifyUrl' => $this->verifyUrl(),
            ],
        );
    }

    /**
     * Deep link straight to the verification screen.
     */
    private function verifyUrl(): ?string
    {
        try {
            return route('admin.bookings.show', $this->booking->getKey());
        } catch (\Throwable) {
            return null;
        }
    }
}
