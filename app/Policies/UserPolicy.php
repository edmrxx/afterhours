<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorisation for the Users module.
 *
 * Two layers live here and they are deliberately different in kind:
 *
 *  1. **Permission mapping** — `users.view`, `users.create`, `users.update`,
 *     `users.delete`. Never a role-name check, so an unlimited number of future
 *     roles (Manager, Supervisor, Cashier…) works without touching this file.
 *
 *  2. **Structural guards** — rules that hold no matter what permissions the
 *     actor has, because breaking them makes the system unusable:
 *     you may not delete or deactivate yourself, and the very last account
 *     holding the administrator role can never be removed or locked out.
 *
 * The nuanced methods return an `Illuminate\Auth\Access\Response` carrying a
 * human sentence, so controllers can surface the actual reason via
 * `Gate::inspect(...)->message()` instead of a bare 403.
 */
class UserPolicy
{
    /**
     * The role that must always have at least one active holder.
     *
     * This is the one place a role name legitimately appears: it is not an
     * access check, it is the "the system must stay administrable" invariant.
     */
    public const PROTECTED_ROLE = 'Admin';

    /* --------------------------------------------------------------------- */
    /* Permission mapping                                                     */
    /* --------------------------------------------------------------------- */

    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.update');
    }

    /* --------------------------------------------------------------------- */
    /* Structural guards                                                      */
    /* --------------------------------------------------------------------- */

    public function delete(User $user, User $model): Response
    {
        if (! $user->can('users.delete')) {
            return Response::deny('You do not have permission to delete users.');
        }

        if ($user->is($model)) {
            return Response::deny('You cannot delete your own account.');
        }

        if ($this->isLastAdministrator($model)) {
            return Response::deny(sprintf(
                'This is the last remaining %s account — deleting it would leave the system with no administrator.',
                self::PROTECTED_ROLE,
            ));
        }

        return Response::allow();
    }

    /**
     * Flip `is_active`. Deactivating is the destructive direction, so the
     * guards only bite when the account is currently on.
     */
    public function toggleStatus(User $user, User $model): Response
    {
        if (! $user->can('users.update')) {
            return Response::deny('You do not have permission to change account status.');
        }

        if (! $model->is_active) {
            return Response::allow();
        }

        if ($user->is($model)) {
            return Response::deny('You cannot deactivate your own account.');
        }

        if ($this->isLastActiveAdministrator($model)) {
            return Response::deny(sprintf(
                'This is the last active %s account — deactivating it would lock everyone out of the admin area.',
                self::PROTECTED_ROLE,
            ));
        }

        return Response::allow();
    }

    public function resetPassword(User $user, User $model): Response
    {
        if (! $user->can('users.update')) {
            return Response::deny('You do not have permission to reset passwords.');
        }

        return Response::allow();
    }

    /**
     * May the actor move this account to a different role?
     *
     * Nobody escalates (or demotes) themselves, and the last administrator
     * cannot be moved out of the administrator role — that is the same
     * unadministrable-system failure as deleting them.
     */
    public function assignRole(User $user, User $model): Response
    {
        if (! $user->can('users.update')) {
            return Response::deny('You do not have permission to assign roles.');
        }

        if ($model->exists && $user->is($model)) {
            return Response::deny('You cannot change your own role.');
        }

        if ($model->exists && $this->isLastAdministrator($model)) {
            return Response::deny(sprintf(
                'This is the last remaining %s account — its role cannot be changed.',
                self::PROTECTED_ROLE,
            ));
        }

        return Response::allow();
    }

    /* --------------------------------------------------------------------- */
    /* Invariant helpers — also used by the controller to shape the UI        */
    /* --------------------------------------------------------------------- */

    /**
     * Is this the only account left holding the administrator role?
     */
    public function isLastAdministrator(User $model): bool
    {
        if (! $this->isAdministrator($model)) {
            return false;
        }

        return ! User::query()
            ->role(self::PROTECTED_ROLE)
            ->whereKeyNot($model->getKey())
            ->exists();
    }

    /**
     * Is this the only *active* account left holding the administrator role?
     */
    public function isLastActiveAdministrator(User $model): bool
    {
        if (! $this->isAdministrator($model) || ! $model->is_active) {
            return false;
        }

        return ! User::query()
            ->role(self::PROTECTED_ROLE)
            ->where('is_active', true)
            ->whereKeyNot($model->getKey())
            ->exists();
    }

    /**
     * `loadMissing` rather than a bare `hasRole`, because `preventLazyLoading`
     * is on in local and a route-model-bound user arrives with no relations.
     */
    private function isAdministrator(User $model): bool
    {
        if (! $model->exists) {
            return false;
        }

        $model->loadMissing('roles');

        return $model->roles->contains(
            fn ($role): bool => (string) $role->name === self::PROTECTED_ROLE,
        );
    }
}
