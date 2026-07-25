<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\NewBookingSubmittedMail;
use Illuminate\Mail\Mailable;

/**
 * Tells the people who can verify payments that a payment reference is waiting.
 *
 * Sent to every active user holding `bookings.verify` (and to the fallback
 * address in `booking.admin_email` when nobody does), the moment the customer
 * submits their reference.
 */
class NewBookingSubmitted extends BookingNotification
{
    public function toMail(object $notifiable): Mailable
    {
        return (new NewBookingSubmittedMail($this->booking))
            ->to($this->recipientEmail($notifiable));
    }

    /**
     * Terse by design — this is a nudge to go and look, not the record itself.
     */
    public function toSms(object $notifiable): string
    {
        $summary = $this->summary();

        return sprintf(
            '%s: New payment to verify. %s - %s, %s %s. Ref %s, %s.',
            (string) config('app.name', 'PHEA'),
            (string) $summary['code'],
            (string) $summary['court_name'],
            (string) $summary['date_short'],
            (string) $summary['time_range_full'],
            (string) ($summary['payment_reference'] ?: 'none'),
            (string) $summary['amount_plain'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->summary();
    }
}
