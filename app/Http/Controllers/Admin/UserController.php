<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Staff account management.
 *
 * Nothing here knows the name of a role beyond the single "the system must stay
 * administrable" invariant in `UserPolicy`. Roles come from the database, so a
 * Manager, Cashier or Accountant created next month is assignable immediately
 * with no code change.
 */
class UserController extends Controller
{
    /** Columns a client may sort by — never trust `sort` straight from the URL. */
    private const SORTABLE = [
        'name',
        'username',
        'email',
        'is_active',
        'last_login_at',
        'created_at',
    ];

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    private const DEFAULT_PER_PAGE = 25;

    private const MODULE = 'Users';

    /** @var \Illuminate\Support\Collection<int, Role>|null */
    private ?Collection $roleCache = null;

    public function __construct(private readonly AuditTrailService $audit) {}

    /* --------------------------------------------------------------------- */
    /* Listing                                                                */
    /* --------------------------------------------------------------------- */

    public function index(Request $request): Response
    {
        $roles = $this->roles();

        $search = trim((string) $request->query('search', ''));

        // Echoed back to the client, so an unrecognised value is dropped rather
        // than reflected into a select that has no matching option.
        $statusFilter = in_array($request->query('status'), ['active', 'inactive'], true)
            ? (string) $request->query('status')
            : '';

        /*
         | Spatie's role() scope THROWS RoleDoesNotExist for an unknown name, so
         | the filter is resolved to a real model first. A bookmarked URL whose
         | role has since been deleted therefore degrades to "all roles" instead
         | of a 500.
         */
        $requestedRole = trim((string) $request->query('role', ''));

        $roleFilterModel = $requestedRole === ''
            ? null
            : $roles->first(fn (Role $role): bool => (string) $role->name === $requestedRole);

        $roleFilter = $roleFilterModel?->name ?? '';

        $sort = in_array($request->query('sort'), self::SORTABLE, true)
            ? (string) $request->query('sort')
            : 'created_at';

        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $perPage = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;

        $currentId = (int) $request->user()->getKey();

        /*
         | One extra query instead of one per row: the "last administrator" lock
         | on a list of 100 users must never become 100 exists() calls. There
         | are only ever a handful of administrators, so pulling their ids and
         | deriving both invariants in PHP is cheaper than two more round trips.
         */
        $administratorRole = $roles->first(
            fn (Role $role): bool => (string) $role->name === UserPolicy::PROTECTED_ROLE,
        );

        $administrators = $administratorRole === null
            ? collect()
            : User::query()->role($administratorRole)->get(['id', 'is_active']);

        $lastAdministratorId = $administrators->count() === 1
            ? (int) $administrators->first()->id
            : null;

        $activeAdministrators = $administrators->where('is_active', true);

        $lastActiveAdministratorId = $activeAdministrators->count() === 1
            ? (int) $activeAdministrators->first()->id
            : null;

        $users = User::query()
            // Narrow select + eager-loaded roles: the role column is rendered
            // for every row, so a lazy `roles` relation would be a hard N+1.
            ->select([
                'id', 'name', 'username', 'email', 'phone', 'avatar_path',
                'is_active', 'must_change_password', 'last_login_at', 'created_at',
            ])
            ->with('roles:id,name')
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('username', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($roleFilterModel !== null, fn ($query) => $query->role($roleFilterModel))
            ->when($statusFilter === 'active', fn ($query) => $query->where('is_active', true))
            ->when($statusFilter === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy($sort, $direction)
            // Stable tiebreaker so paging never repeats or drops a row.
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'initials' => $user->initials(),
                'avatar_url' => null,
                'is_active' => (bool) $user->is_active,
                'must_change_password' => (bool) $user->must_change_password,
                'roles' => $user->roles->pluck('name')->all(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_login_human' => $user->last_login_at?->diffForHumans(),
                'created_at_human' => $user->created_at?->toFormattedDateString(),
                'is_self' => (int) $user->id === $currentId,
                'is_last_admin' => $lastAdministratorId !== null && (int) $user->id === $lastAdministratorId,
                'is_last_active_admin' => $lastActiveAdministratorId !== null
                    && (int) $user->id === $lastActiveAdministratorId,
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'roleOptions' => $this->roleOptions($roles),
            'filters' => [
                'search' => $search,
                'role' => $roleFilter,
                'status' => $statusFilter,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
        ]);
    }

    /* --------------------------------------------------------------------- */
    /* Create                                                                 */
    /* --------------------------------------------------------------------- */

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'user' => null,
            'roleOptions' => $this->roleOptions(),
            'canChangeRole' => true,
            'canChangeStatus' => true,
            'roleLockReason' => null,
            'statusLockReason' => null,
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $generated = null;
        $password = $data['password'] ?? null;

        if (blank($password)) {
            $password = $generated = $this->generatePassword();
        }

        /** @var User $user */
        $user = DB::transaction(function () use ($data, $password): User {
            $user = new User;

            $user->forceFill([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => $password,
                'is_active' => (bool) $data['is_active'],
                // Always true: the inviter knows this password, so the invitee
                // has to replace it before the account is really theirs.
                'must_change_password' => true,
            ])->save();

            $user->syncRoles([$data['role']]);

            return $user;
        });

        $this->audit->log(
            module: self::MODULE,
            action: 'create',
            description: sprintf("Created user '%s' (%s) with the %s role.", $user->name, $user->username, $data['role']),
            model: $user,
            newValues: [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $data['role'],
                'is_active' => (bool) $user->is_active,
            ],
        );

        $redirect = to_route('admin.users.index')
            ->with('success', sprintf('%s can now sign in as “%s”.', $user->name, $user->username));

        if ($generated !== null) {
            $redirect->with('info', sprintf(
                'Temporary password for %s: %s — share it once, it must be changed on first sign-in.',
                $user->username,
                $generated,
            ));
        }

        return $redirect;
    }

    /* --------------------------------------------------------------------- */
    /* Update                                                                 */
    /* --------------------------------------------------------------------- */

    public function edit(Request $request, User $user): Response
    {
        $user->loadMissing('roles:id,name');

        $roleGate = Gate::inspect('assignRole', $user);
        $statusGate = Gate::inspect('toggleStatus', $user);

        $statusLocked = $user->is_active && $statusGate->denied();

        return Inertia::render('Admin/Users/Form', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'initials' => $user->initials(),
                'is_active' => (bool) $user->is_active,
                'must_change_password' => (bool) $user->must_change_password,
                'role' => $user->roles->first()?->name,
                'last_login_human' => $user->last_login_at?->diffForHumans(),
                'created_at_human' => $user->created_at?->toFormattedDateString(),
                'is_self' => $request->user()?->is($user) === true,
            ],
            'roleOptions' => $this->roleOptions(),
            'canChangeRole' => $roleGate->allowed(),
            'canChangeStatus' => ! $statusLocked,
            'roleLockReason' => $roleGate->allowed() ? null : $roleGate->message(),
            'statusLockReason' => $statusLocked ? $statusGate->message() : null,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $user->loadMissing('roles:id,name');

        $before = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->roles->first()?->name,
            'is_active' => (bool) $user->is_active,
        ];

        $newRole = $data['role'] ?? $before['role'];
        $passwordChanged = filled($data['password'] ?? null);

        DB::transaction(function () use ($user, $data, $newRole, $passwordChanged): void {
            $user->forceFill([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_active' => (bool) $data['is_active'],
            ]);

            if ($passwordChanged) {
                // An administrator-set password is a temporary credential by
                // definition — the owner must rotate it on next sign-in.
                $user->forceFill([
                    'password' => $data['password'],
                    'must_change_password' => true,
                ]);
            }

            $user->save();

            if ($newRole !== null && $newRole !== $user->roles->first()?->name) {
                $user->syncRoles([$newRole]);
            }
        });

        $after = [
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'role' => $newRole,
            'is_active' => (bool) $data['is_active'],
        ];

        $changed = array_keys(array_filter(
            $after,
            fn (mixed $value, string $key): bool => $before[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($passwordChanged) {
            $changed[] = 'password';
        }

        if ($changed !== []) {
            $this->audit->log(
                module: self::MODULE,
                action: 'update',
                description: sprintf(
                    "Updated user '%s' (%s).",
                    $user->name,
                    implode(', ', array_map(static fn (string $key): string => str_replace('_', ' ', $key), $changed)),
                ),
                model: $user,
                oldValues: array_intersect_key($before, array_flip($changed)),
                newValues: array_intersect_key($after, array_flip($changed)),
            );
        }

        return to_route('admin.users.index')
            ->with('success', sprintf('%s has been updated.', $user->name));
    }

    /* --------------------------------------------------------------------- */
    /* Status                                                                 */
    /* --------------------------------------------------------------------- */

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        $gate = Gate::inspect('toggleStatus', $user);

        if ($gate->denied()) {
            return back()->with('error', $gate->message());
        }

        $next = ! $user->is_active;

        $user->forceFill(['is_active' => $next])->save();

        $this->audit->log(
            module: self::MODULE,
            action: $next ? 'activate' : 'deactivate',
            description: sprintf(
                "%s user '%s' (%s).",
                $next ? 'Activated' : 'Deactivated',
                $user->name,
                $user->username,
            ),
            model: $user,
            oldValues: ['is_active' => ! $next],
            newValues: ['is_active' => $next],
        );

        return back()->with(
            'success',
            $next
                ? sprintf('%s can sign in again.', $user->name)
                : sprintf('%s has been deactivated and will be signed out.', $user->name),
        );
    }

    /* --------------------------------------------------------------------- */
    /* Password reset                                                         */
    /* --------------------------------------------------------------------- */

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $gate = Gate::inspect('resetPassword', $user);

        if ($gate->denied()) {
            return back()->with('error', $gate->message());
        }

        $validated = $request->validate([
            'password' => ['nullable', 'string', Password::min(8)->letters()->numbers(), 'max:72'],
        ]);

        $generated = null;
        $password = $validated['password'] ?? null;

        if (blank($password)) {
            $password = $generated = $this->generatePassword();
        }

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
        ])->save();

        $this->audit->log(
            module: self::MODULE,
            action: 'update',
            description: sprintf(
                "Reset the password for '%s' (%s) and forced a change on next sign-in.",
                $user->name,
                $user->username,
            ),
            model: $user,
            newValues: ['must_change_password' => true],
        );

        $redirect = back()->with('success', sprintf('Password reset for %s.', $user->name));

        if ($generated !== null) {
            $redirect->with('info', sprintf(
                'Temporary password for %s: %s — share it once, it must be changed on first sign-in.',
                $user->username,
                $generated,
            ));
        }

        return $redirect;
    }

    /* --------------------------------------------------------------------- */
    /* Delete                                                                 */
    /* --------------------------------------------------------------------- */

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $gate = Gate::inspect('delete', $user);

        if ($gate->denied()) {
            return back()->with('error', $gate->message());
        }

        $user->loadMissing('roles:id,name');

        $snapshot = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->roles->first()?->name,
        ];

        // Soft delete: audit history keeps pointing at a real row, and the
        // username stays reserved so it cannot be silently recycled.
        $user->delete();

        $this->audit->log(
            module: self::MODULE,
            action: 'delete',
            description: sprintf("Deleted user '%s' (%s).", $snapshot['name'], $snapshot['username']),
            model: $user,
            oldValues: $snapshot,
        );

        return back()->with('success', sprintf('%s has been removed.', $snapshot['name']));
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Every role in the system, straight from the table — never a hardcoded list.
     *
     * @param  \Illuminate\Support\Collection<int, Role>|null  $roles
     * @return list<array{value: string, label: string}>
     */
    private function roleOptions(?Collection $roles = null): array
    {
        return ($roles ?? $this->roles())
            ->map(fn (Role $role): array => [
                'value' => (string) $role->name,
                'label' => (string) $role->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Every assignable role, fetched once per request.
     *
     * @return \Illuminate\Support\Collection<int, Role>
     */
    private function roles(): Collection
    {
        return $this->roleCache ??= Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * A strong but relayable temporary credential.
     *
     * Symbols are off deliberately: this password is dictated over the phone or
     * pasted into a chat by the inviter, and it lives for exactly one sign-in
     * before `must_change_password` forces a replacement.
     */
    private function generatePassword(): string
    {
        return Str::password(14, letters: true, numbers: true, symbols: false, spaces: false);
    }
}
