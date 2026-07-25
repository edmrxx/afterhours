<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\BookingReservedMail;
use Illuminate\Mail\Mailable;

/**
 * The slot is held, not yet paid for. Sent to the customer the moment
 * reserve() succeeds, so they have the booking code, the amount due, and the
 * hold deadline in hand even if they close the payment tab.
 */
class BookingReserved extends BookingNotification
{
    public function toMail(object $notifiable): Mailable
    {
        return (new BookingReservedMail($this->booking))
            ->to($this->recipientEmail($notifiable));
    }

    public function toSms(object $notifiable): string
    {
        $summary = $this->summary();

        $appName = (string) config('app.name', 'PHEA');

        // A cross-court booking cannot be described by one court plus one time
        // range: "Court 1 - 3 Aug, 9AM, 2PM" hides which time belongs to which
        // court. Name every court beside its own slot so the payer knows
        // exactly what is being held, staying terse for SMS. A single-court
        // booking never enters this branch and keeps its wording verbatim.
        if (! empty($summary['is_multi_court'])) {
            $held = implode('; ', array_map(
                static fn (array $slot): string => trim(sprintf(
                    '%s %s %s',
                    (string) ($slot['court_name'] ?? 'Court'),
                    (string) ($slot['date_short'] ?? ''),
                    (string) ($slot['time_range'] ?? ''),
                )),
                $summary['slots'] ?? [],
            ));

            return sprintf(
                '%s: We are holding %s for you (booking %s). Pay %s by %s to confirm it.',
                $appName,
                $held,
                (string) $summary['code'],
                (string) $summary['amount_plain'],
                (string) $summary['hold_expires_at'],
            );
        }

        // Method-neutral by necessity: at reserve time the customer has not
        // reached the payment page yet, so there is no chosen app to name and
        // the site may publish more than one. Naming GCash here would send a
        // GoTyme payer to the wrong app before we ever asked them.
        return sprintf(
            '%s: We are holding %s - %s, %s for you (booking %s). Pay %s by %s to confirm it.',
            $appName,
            (string) $summary['court_name'],
            (string) $summary['date_short'],
            (string) $summary['time_range_full'],
            (string) $summary['code'],
            (string) $summary['amount_plain'],
            (string) $summary['hold_expires_at'],
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
