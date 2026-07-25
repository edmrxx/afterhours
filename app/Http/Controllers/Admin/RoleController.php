<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Services\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role management — the mechanism that makes the permission system open-ended.
 *
 * The application never asks "is this user an Admin?"; it asks "does this user
 * hold `bookings.verify`?". Roles are therefore just named permission bundles,
 * and an operator can invent Manager, Supervisor, Cashier, Encoder or
 * Accountant here with no deployment. The permission matrix is likewise built
 * from whatever rows exist in `permissions`, grouped by the token before the
 * dot, so a new permission appears in the UI the moment it is seeded.
 */
class RoleController extends Controller
{
    private const SORTABLE = ['name', 'created_at'];

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    private const DEFAULT_PER_PAGE = 25;

    private const MODULE = 'Roles';

    /**
     * Preferred order of the permission groups in the matrix.
     *
     * Presentation only. A module that is not listed still renders — it simply
     * sorts alphabetically after the known ones, so nothing is ever hidden by
     * being absent from this array.
     *
     * @var list<string>
     */
    private const MODULE_ORDER = [
        'dashboard', 'courts', 'slots', 'bookings', 'users', 'roles', 'settings', 'audit', 'reports',
    ];

    /**
     * Preferred order of the actions inside a group.
     *
     * Read-then-write-then-destroy: `delete` sorts last so the destructive box
     * is never the one a hurried operator ticks by muscle memory.
     *
     * @var list<string>
     */
    private const ACTION_ORDER = ['view', 'create', 'update', 'generate', 'verify', 'export', 'delete'];

    public function __construct(private readonly AuditTrailService $audit)
    {
        // The Spatie Role model lives in the vendor namespace, so Laravel's
        // convention-based policy discovery cannot reach App\Policies\RolePolicy.
        // Binding it here keeps the module self-contained.
        Gate::policy(Role::class, RolePolicy::class);
    }

    /* --------------------------------------------------------------------- */
    /* Listing                                                                */
    /* --------------------------------------------------------------------- */

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $sort = in_array($request->query('sort'), self::SORTABLE, true)
            ? (string) $request->query('sort')
            : 'name';

        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;

        $rolesTable = (new Role)->getTable();
        $usersTable = (new User)->getTable();
        $pivot = RolePolicy::pivotTable();
        $roleKey = RolePolicy::rolePivotColumn();
        $morphKey = RolePolicy::morphKeyColumn();

        $roles = Role::query()
            ->select($rolesTable.'.*')
            ->where('guard_name', 'web')
            /*
             | Counted with a correlated subquery rather than withCount('users'):
             | Spatie's users() relation reads $this->attributes['guard_name'],
             | which is not available on the un-hydrated model withCount builds.
             |
             | The join to `users` excludes soft-deleted accounts, so the count
             | shown here agrees with the one RolePolicy::delete() enforces.
             */
            ->selectSub(
                DB::table($pivot.' as pivot')
                    ->selectRaw('count(*)')
                    ->join($usersTable.' as u', 'u.id', '=', 'pivot.'.$morphKey)
                    ->whereColumn('pivot.'.$roleKey, $rolesTable.'.id')
                    ->where('pivot.model_type', (new User)->getMorphClass())
                    ->whereNull('u.deleted_at'),
                'users_count',
            )
            ->withCount('permissions')
            ->when($search !== '', function ($query) use ($search, $rolesTable): void {
                $query->where($rolesTable.'.name', 'like', '%'.addcslashes($search, '%_\\').'%');
            })
            ->orderBy($sort, $direction)
            ->orderBy($rolesTable.'.id', 'asc')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Role $role): array => [
                'id' => $role->id,
                'name' => (string) $role->name,
                'permissions_count' => (int) $role->permissions_count,
                'users_count' => (int) $role->users_count,
                'is_protected' => (string) $role->name === RolePolicy::PROTECTED_ROLE,
                'created_at_human' => $role->created_at?->toFormattedDateString(),
            ]);

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles,
            'permissionTotal' => Permission::query()->where('guard_name', 'web')->count(),
            'filters' => [
                'search' => $search,
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
        return Inertia::render('Admin/Roles/Form', [
            'role' => null,
            'groups' => $this->permissionGroups(),
            'isProtected' => false,
            'protectedRole' => RolePolicy::PROTECTED_ROLE,
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        /** @var Role $role */
        $role = DB::transaction(function () use ($data): Role {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);

            // syncPermissions flushes the Spatie permission cache for us.
            $role->syncPermissions($data['permissions']);

            return $role;
        });

        $this->audit->log(
            module: self::MODULE,
            action: 'create',
            description: sprintf(
                "Created role '%s' with %d %s.",
                $role->name,
                count($data['permissions']),
                count($data['permissions']) === 1 ? 'permission' : 'permissions',
            ),
            model: $role,
            newValues: ['name' => $role->name, 'permissions' => $data['permissions']],
        );

        return to_route('admin.roles.index')
            ->with('success', sprintf('The %s role is ready to assign.', $role->name));
    }

    /* --------------------------------------------------------------------- */
    /* Update                                                                 */
    /* --------------------------------------------------------------------- */

    public function edit(Role $role): Response
    {
        Gate::authorize('update', $role);

        $role->loadMissing('permissions:id,name');

        $policy = new RolePolicy;

        return Inertia::render('Admin/Roles/Form', [
            'role' => [
                'id' => $role->id,
                'name' => (string) $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
                'users_count' => $policy->assignedUserCount($role),
                'created_at_human' => $role->created_at?->toFormattedDateString(),
            ],
            'groups' => $this->permissionGroups(),
            'isProtected' => $policy->isProtected($role),
            'protectedRole' => RolePolicy::PROTECTED_ROLE,
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $data = $request->validated();

        $role->loadMissing('permissions:id,name');

        $before = [
            'name' => (string) $role->name,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];

        $protected = (new RolePolicy)->isProtected($role);

        // Belt and braces alongside UpdateRoleRequest: the protected role always
        // ends up with the complete catalogue, whatever arrived in the payload.
        $permissions = $protected
            ? Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all()
            : array_values($data['permissions']);

        DB::transaction(function () use ($role, $data, $permissions, $protected): void {
            if (! $protected && (string) $role->name !== (string) $data['name']) {
                $role->forceFill(['name' => $data['name']])->save();
            }

            $role->syncPermissions($permissions);
        });

        $after = [
            'name' => (string) $role->name,
            'permissions' => collect($permissions)->sort()->values()->all(),
        ];

        if ($before !== $after) {
            $added = array_values(array_diff($after['permissions'], $before['permissions']));
            $removed = array_values(array_diff($before['permissions'], $after['permissions']));

            $this->audit->log(
                module: self::MODULE,
                action: 'update',
                description: sprintf(
                    "Updated role '%s' (+%d, -%d permissions).",
                    $role->name,
                    count($added),
                    count($removed),
                ),
                model: $role,
                oldValues: $before,
                newValues: $after,
            );
        }

        return to_route('admin.roles.index')
            ->with('success', sprintf('The %s role has been updated.', $role->name));
    }

    /* --------------------------------------------------------------------- */
    /* Delete                                                                 */
    /* --------------------------------------------------------------------- */

    public function destroy(Role $role): RedirectResponse
    {
        $gate = Gate::inspect('delete', $role);

        if ($gate->denied()) {
            return back()->with('error', $gate->message());
        }

        $role->loadMissing('permissions:id,name');

        $snapshot = [
            'name' => (string) $role->name,
            'permissions' => $role->permissions->pluck('name')->all(),
        ];

        $role->delete();

        // Spatie caches the whole role/permission map; a stale entry would let
        // a just-deleted role keep granting access for the rest of the TTL.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->audit->log(
            module: self::MODULE,
            action: 'delete',
            description: sprintf("Deleted role '%s'.", $snapshot['name']),
            oldValues: $snapshot,
        );

        return back()->with('success', sprintf('The %s role has been deleted.', $snapshot['name']));
    }

    /* --------------------------------------------------------------------- */
    /* Permission matrix                                                      */
    /* --------------------------------------------------------------------- */

    /**
     * Build the checkbox matrix straight from the `permissions` table.
     *
     * Grouping is derived from the name (`bookings.verify` → group `bookings`,
     * action `verify`), so seeding a new permission is the only step required
     * to surface it here.
     *
     * @return list<array{key: string, label: string, permissions: list<array{name: string, label: string, description: string}>}>
     */
    private function permissionGroups(): array
    {
        $groups = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->groupBy(fn (Permission $permission): string => Str::before((string) $permission->name, '.'))
            ->map(function ($permissions, string $key): array {
                $items = $permissions
                    ->map(function (Permission $permission) use ($key): array {
                        $action = Str::contains((string) $permission->name, '.')
                            ? Str::after((string) $permission->name, '.')
                            : (string) $permission->name;

                        return [
                            'name' => (string) $permission->name,
                            'label' => Str::headline($action),
                            'description' => $this->describe($key, $action),
                            'weight' => $this->actionWeight($action),
                        ];
                    })
                    ->sortBy([['weight', 'asc'], ['label', 'asc']])
                    ->map(fn (array $item): array => [
                        'name' => $item['name'],
                        'label' => $item['label'],
                        'description' => $item['description'],
                    ])
                    ->values()
                    ->all();

                return [
                    'key' => $key,
                    'label' => Str::headline($key),
                    'weight' => $this->moduleWeight($key),
                    'permissions' => $items,
                ];
            })
            ->sortBy([['weight', 'asc'], ['label', 'asc']])
            ->values();

        return $groups
            ->map(fn (array $group): array => [
                'key' => $group['key'],
                'label' => $group['label'],
                'permissions' => $group['permissions'],
            ])
            ->all();
    }

    private function moduleWeight(string $key): int
    {
        $index = array_search($key, self::MODULE_ORDER, true);

        return $index === false ? count(self::MODULE_ORDER) : $index;
    }

    private function actionWeight(string $action): int
    {
        $index = array_search($action, self::ACTION_ORDER, true);

        return $index === false ? count(self::ACTION_ORDER) : $index;
    }

    /**
     * One-line plain-English gloss for a permission checkbox.
     *
     * Generic by construction — an unknown action still gets a readable
     * sentence, so new permissions never render as a bare slug.
     */
    private function describe(string $module, string $action): string
    {
        $plural = Str::headline($module);
        $singular = Str::lower(Str::singular($plural));

        return match ($action) {
            'view' => sprintf('See everything under %s.', $plural),
            'create' => sprintf('Create a new %s.', $singular),
            'update' => sprintf('Edit an existing %s.', $singular),
            'delete' => sprintf('Remove a %s.', $singular),
            'generate' => 'Run the bulk slot generator.',
            'verify' => 'Confirm or reject submitted payments.',
            'export' => sprintf('Download %s as a file.', $plural),
            default => sprintf('%s within %s.', Str::headline($action), $plural),
        };
    }
}
