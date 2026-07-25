<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\BookingConfirmedMail;
use Illuminate\Mail\Mailable;

/**
 * Payment verified, court locked in. Sent to the customer by email when they
 * gave one, and by SMS whenever the gateway is switched on.
 */
class BookingConfirmed extends BookingNotification
{
    public function toMail(object $notifiable): Mailable
    {
        return (new BookingConfirmedMail($this->booking))
            ->to($this->recipientEmail($notifiable));
    }

    public function toSms(object $notifiable): string
    {
        $summary = $this->summary();

        return sprintf(
            '%s: Booking %s is CONFIRMED. %s on %s, %s. Paid %s. Show this code at the desk. See you!',
            (string) config('app.name', 'PHEA'),
            (string) $summary['code'],
            (string) $summary['court_name'],
            (string) $summary['date_short'],
            (string) $summary['time_range_full'],
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
