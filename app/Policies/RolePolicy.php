<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Authorisation for the Roles module.
 *
 * Roles are pure data: the system is designed to grow Manager, Supervisor,
 * Cashier, Encoder, Accountant… as rows, never as code. This policy therefore
 * gates on `roles.*` permissions only, with exactly two structural guards:
 *
 *  - the administrator role is permanent — it can be neither deleted nor
 *    stripped of permissions, or the application becomes unadministrable;
 *  - a role still assigned to accounts cannot be deleted, because those
 *    accounts would silently lose every permission they had.
 *
 * The Spatie `Role` model lives in the vendor namespace, so Laravel's
 * convention-based policy discovery cannot find this class. `RoleController`
 * binds it explicitly with `Gate::policy()`.
 */
class RolePolicy
{
    /** The role that must always exist with the full permission set. */
    public const PROTECTED_ROLE = 'Admin';

    public function viewAny(User $user): bool
    {
        return $user->can('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.create');
    }

    public function update(User $user, Role $role): Response
    {
        if (! $user->can('roles.update')) {
            return Response::deny('You do not have permission to edit roles.');
        }

        return Response::allow();
    }

    public function delete(User $user, Role $role): Response
    {
        if (! $user->can('roles.delete')) {
            return Response::deny('You do not have permission to delete roles.');
        }

        if ($this->isProtected($role)) {
            return Response::deny(sprintf(
                'The %s role is built into the system and cannot be deleted.',
                self::PROTECTED_ROLE,
            ));
        }

        $assigned = $this->assignedUserCount($role);

        if ($assigned > 0) {
            return Response::deny(sprintf(
                'The %s role is still assigned to %d %s. Move %s to another role first, then delete it.',
                $role->name,
                $assigned,
                $assigned === 1 ? 'account' : 'accounts',
                $assigned === 1 ? 'that account' : 'those accounts',
            ));
        }

        return Response::allow();
    }

    /**
     * The administrator role may be edited (it is useful to see it) but its
     * permission set is immutable — stripping it is the classic way to lock
     * yourself out for good.
     */
    public function isProtected(Role $role): bool
    {
        return (string) $role->name === self::PROTECTED_ROLE;
    }

    /**
     * How many *live* accounts currently hold this role.
     *
     * Counted straight off the pivot rather than through `Role::users()`:
     * that relation reads `$this->attributes['guard_name']` and therefore
     * cannot be used from an un-hydrated model or a `withCount()` subquery.
     *
     * The join back to `users` is load-bearing. Deleting a user is a soft
     * delete, which leaves the `model_has_roles` row in place — counting the
     * bare pivot would make a role undeletable forever because of somebody who
     * left the club a year ago.
     */
    public function assignedUserCount(Role $role): int
    {
        return DB::table(self::pivotTable().' as pivot')
            ->join((new User)->getTable().' as u', 'u.id', '=', 'pivot.'.self::morphKeyColumn())
            ->where('pivot.'.self::rolePivotColumn(), $role->getKey())
            ->where('pivot.model_type', (new User)->getMorphClass())
            ->whereNull('u.deleted_at')
            ->count();
    }

    public static function pivotTable(): string
    {
        return (string) config('permission.table_names.model_has_roles');
    }

    public static function rolePivotColumn(): string
    {
        return (string) (config('permission.column_names.role_pivot_key') ?? 'role_id');
    }

    public static function morphKeyColumn(): string
    {
        return (string) (config('permission.column_names.model_morph_key') ?? 'model_id');
    }
}
