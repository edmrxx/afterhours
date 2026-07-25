<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\OwnerBookingAlertMail;
use App\Models\Booking;
use Illuminate\Mail\Mailable;

/**
 * A plain heads-up to the owner's personal inbox: "someone booked" and,
 * later, "that booking was confirmed".
 *
 * Deliberately mail-only and information-only — it is a reminder, not a work
 * item. The verifier alert (NewBookingSubmitted) is what actually asks a human
 * to act; this exists purely so the owner knows activity is happening without
 * having to watch the admin panel. It rides on the same on-demand mail route
 * as that fallback, so `via()` resolves to `['mail']` alone (no SMS number is
 * ever attached), and it inherits the queue, back-off and swallow-on-failure
 * behaviour of every booking notification from BookingNotification.
 */
class OwnerBookingAlert extends BookingNotification
{
    /** A booking was just placed and is now on hold, awaiting payment. */
    public const EVENT_RESERVED = 'reserved';

    /** A booking's payment was verified and it is now confirmed. */
    public const EVENT_CONFIRMED = 'confirmed';

    /**
     * @param  string  $event  one of the EVENT_* constants — which moment in the
     *                         booking's life this heads-up is announcing.
     */
    public function __construct(Booking $booking, public string $event)
    {
        parent::__construct($booking);
    }

    public function toMail(object $notifiable): Mailable
    {
        return (new OwnerBookingAlertMail($this->booking, $this->event))
            ->to($this->recipientEmail($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event, ...$this->summary()];
    }
}
