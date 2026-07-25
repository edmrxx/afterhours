<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Court;
use App\Models\User;

/**
 * Authorisation for the Courts module.
 *
 * Permissions are the only access primitive (docs/ARCHITECTURE.md §2) — role
 * names never appear here, so a new role gets access purely by being granted
 * `courts.*` in the roles screen.
 *
 * `$user->can()` (rather than spatie's `hasPermissionTo()`) is deliberate: it
 * routes through the Gate, which spatie hooks with a `before` callback that
 * degrades an unknown permission name to `false` instead of throwing.
 *
 * Note that the routes are *already* guarded by the `permission:` middleware.
 * This policy is the second lock — it keeps controller actions, Blade/Inertia
 * gating and any future API surface honest without relying on route middleware.
 *
 * Domain rules (e.g. "a court with live bookings may not be deleted") live in
 * the controller, not here: they need to surface as a friendly flash message
 * explaining *why*, not as a bare 403.
 */
class CourtPolicy
{
    /**
     * A deactivated account keeps its permissions but must not act. The
     * `active` middleware logs them out mid-session; this closes the window
     * for anything the middleware does not cover (queues, API tokens).
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('courts.view');
    }

    public function view(User $user, Court $court): bool
    {
        return $user->can('courts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('courts.create');
    }

    public function update(User $user, Court $court): bool
    {
        return $user->can('courts.update');
    }

    public function delete(User $user, Court $court): bool
    {
        return $user->can('courts.delete');
    }

    /**
     * Restoring a court puts it back on the public site, so it is an update in
     * spirit but a delete-grade action in consequence.
     */
    public function restore(User $user, Court $court): bool
    {
        return $user->can('courts.delete');
    }

    /**
     * Nothing in the application force-deletes a court: bookings and audit
     * history reference it, and the bin is the recovery path.
     */
    public function forceDelete(User $user, Court $court): bool
    {
        return false;
    }
}
