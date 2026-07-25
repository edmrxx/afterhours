<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Booking;

/**
 * Every way the booking state machine can legitimately refuse a request.
 *
 * The messages are customer-facing copy: they are shown as-is on the public
 * site, so they stay warm, short and free of internal jargon.
 */
class BookingException extends DomainException
{
    protected string $reason = 'booking_error';

    /**
     * Lost the race for a slot — somebody else's transaction won the lock.
     */
    public static function slotUnavailable(): self
    {
        $e = new self('Sorry, that time slot was just taken by someone else. Please pick another one.');
        $e->reason = 'slot_unavailable';

        return $e;
    }

    /**
     * The slot exists but is not on the market (admin blocked it).
     */
    public static function slotBlocked(): self
    {
        $e = new self('That time slot is not open for booking. Please choose a different time.');
        $e->reason = 'slot_blocked';

        return $e;
    }

    /**
     * Reserving something that has already started or finished.
     */
    public static function slotInPast(): self
    {
        $e = new self('That time slot has already passed. Please pick an upcoming schedule.');
        $e->reason = 'slot_in_past';

        return $e;
    }

    /**
     * The hold clock ran out before the customer finished.
     */
    public static function holdExpired(): self
    {
        $e = new self('Your reservation hold has expired and the slot has been released. Please book again.');
        $e->reason = 'hold_expired';

        return $e;
    }

    /**
     * A transition that the state machine does not allow.
     */
    public static function invalidTransition(Booking $booking, string $from, string $to): self
    {
        $e = new self(sprintf(
            'Booking %s cannot be moved to "%s" while it is "%s".',
            (string) $booking->code,
            str_replace('_', ' ', $to),
            str_replace('_', ' ', $from),
        ));
        $e->reason = 'invalid_transition';

        return $e;
    }

    /**
     * Payment reference submitted for a booking that is not awaiting payment.
     */
    public static function notAwaitingPayment(Booking $booking): self
    {
        $e = new self(sprintf(
            'Booking %s is no longer awaiting payment, so a new reference cannot be submitted.',
            (string) $booking->code,
        ));
        $e->reason = 'not_awaiting_payment';

        return $e;
    }

    /**
     * Cancellation attempted once the booking is already settled.
     */
    public static function notCancellable(Booking $booking): self
    {
        $e = new self(sprintf(
            'Booking %s can no longer be cancelled. Please contact us if you need help.',
            (string) $booking->code,
        ));
        $e->reason = 'not_cancellable';

        return $e;
    }

    /**
     * The slot row backing a booking vanished (hard delete / data repair).
     */
    public static function slotMissing(Booking $booking): self
    {
        $e = new self(sprintf(
            'The schedule linked to booking %s could not be found. Please contact us.',
            (string) $booking->code,
        ));
        $e->reason = 'slot_missing';

        return $e;
    }
}
