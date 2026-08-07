<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Authorisation for the booking module.
 *
 * Permissions are the only access primitive (docs/ARCHITECTURE.md §2) — this
 * policy never looks at a role name, so a new "Cashier" role starts working the
 * moment it is granted `bookings.verify`, with no code change.
 *
 * The policy answers "may this user perform this kind of action at all?".
 * Whether a *particular* booking is currently in a state that allows the action
 * is a domain question, and belongs to BookingService, which owns the state
 * machine and refuses with a friendly BookingException.
 */
class BookingPolicy
{
    public const VIEW = 'bookings.view';

    public const VERIFY = 'bookings.verify';

    public const CREATE = 'bookings.create';

    public const DELETE = 'bookings.delete';

    /**
     * The admin booking list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(self::VIEW);
    }

    /**
     * A single booking's detail screen.
     *
     * The model is nullable on every ability below so the same names can be
     * asked as a bare capability question — `Gate::allows('verify', Booking::class)`
     * — when the UI needs to decide whether to render a button at all, before
     * any particular booking is in hand.
     */
    public function view(User $user, ?Booking $booking = null): bool
    {
        return $user->can(self::VIEW);
    }

    /**
     * Manual payment verification — the single permission behind confirm,
     * reject and complete, so the three halves of the verify loop can never
     * drift apart.
     */
    public function verify(User $user, ?Booking $booking = null): bool
    {
        return $user->can(self::VERIFY);
    }

    public function confirm(User $user, ?Booking $booking = null): bool
    {
        return $this->verify($user, $booking);
    }

    public function reject(User $user, ?Booking $booking = null): bool
    {
        return $this->verify($user, $booking);
    }

    public function complete(User $user, ?Booking $booking = null): bool
    {
        return $this->verify($user, $booking);
    }

    /**
     * Removing junk records. Deliberately a separate, rarer permission than
     * verification: staff clear the queue, they do not erase history.
     */
    public function delete(User $user, ?Booking $booking = null): bool
    {
        return $user->can(self::DELETE);
    }

    public function restore(User $user, ?Booking $booking = null): bool
    {
        return $user->can(self::DELETE);
    }

    public function forceDelete(User $user, ?Booking $booking = null): bool
    {
        return $user->can(self::DELETE);
    }

    /**
     * Keying a booking in at the desk, for a customer who arranged it with the
     * club directly instead of walking the public checkout.
     *
     * Its own permission rather than a reuse of `verify`: this hands out court
     * time — potentially free court time — which is a different power from
     * signing off on a payment somebody already made, and the club may well
     * want to grant one without the other.
     */
    public function create(User $user): bool
    {
        return $user->can(self::CREATE);
    }

    /**
     * Editing a booking's fields directly would bypass the state machine.
     */
    public function update(User $user, ?Booking $booking = null): bool
    {
        return false;
    }
}
