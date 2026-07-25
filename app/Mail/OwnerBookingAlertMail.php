<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Booking;
use App\Notifications\OwnerBookingAlert;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The owner's "someone booked / that booking is confirmed" reminder.
 *
 * One mailable covers both moments — the copy and accent switch on $event —
 * because the two are the same kind of message (a short notice, no action
 * required) and keeping them together stops the wording drifting apart.
 */
class OwnerBookingAlertMail extends BookingMailable
{
    /**
     * @param  string  $event  one of OwnerBookingAlert::EVENT_* — decides the
     *                         subject line, badge and body copy.
     */
    public function __construct(Booking $booking, public string $event)
    {
        parent::__construct($booking);
    }

    public function envelope(): Envelope
    {
        $confirmed = $this->event === OwnerBookingAlert::EVENT_CONFIRMED;

        return new Envelope(
            subject: sprintf(
                '%s — %s (%s)',
                $confirmed ? 'Booking confirmed' : 'New booking',
                (string) $this->booking->code,
                (string) $this->booking->customer_name,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.owner-alert',
            text: 'emails.booking.text.owner-alert',
            with: [
                ...$this->payload(),
                'event' => $this->event,
                'adminUrl' => $this->adminUrl(),
            ],
        );
    }

    /**
     * Convenience deep link to the booking in the admin panel — a link only,
     * so the reminder stays a reminder. Null when routing is unavailable (a
     * queued render outside an HTTP context).
     */
    private function adminUrl(): ?string
    {
        try {
            return route('admin.bookings.show', $this->booking->getKey());
        } catch (\Throwable) {
            return null;
        }
    }
}
