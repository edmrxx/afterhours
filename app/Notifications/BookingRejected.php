<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\BookingRejectedMail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;

/**
 * Payment could not be matched, so the slot went back on sale. Carries the
 * admin's reason through to the customer whenever one was given.
 */
class BookingRejected extends BookingNotification
{
    public function toMail(object $notifiable): Mailable
    {
        return (new BookingRejectedMail($this->booking))
            ->to($this->recipientEmail($notifiable));
    }

    public function toSms(object $notifiable): string
    {
        $summary = $this->summary();
        $reason = trim((string) ($summary['rejection_reason'] ?? ''));

        return sprintf(
            '%s: We could not verify the payment for booking %s (%s on %s, %s), so the slot was released.%s Reply or call us if this looks wrong.',
            (string) config('app.name', 'PHEA'),
            (string) $summary['code'],
            (string) $summary['court_name'],
            (string) $summary['date_short'],
            (string) $summary['time_range_full'],
            $reason === '' ? '' : ' Reason: '.Str::limit($reason, 80).'.',
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
