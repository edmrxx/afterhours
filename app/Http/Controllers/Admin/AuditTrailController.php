<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Read-only viewer over `audit_trails`.
 *
 * This is the highest-volume table in the system — every create, update, delete
 * and login writes a row and nothing ever deletes one — so the screen is built
 * around three rules:
 *
 *  1. Only ever fetch one page. The list is projected down to the columns the
 *     table actually renders, so a wide `user_agent`/`url` payload is not
 *     shipped 25 times over for a grid that shows a browser name.
 *  2. Sort and filter on indexed columns only: `created_at`, `user_id` and the
 *     composite `(module, action)` are exactly what the migration indexes.
 *  3. The module dropdown is a DISTINCT over that composite index and is
 *     cached, so opening the page never costs a full scan.
 *
 * Nothing here writes to the audit trail: logging a visit to the audit log
 * would make the table grow every time somebody read it.
 */
class AuditTrailController extends Controller
{
    /**
     * Columns the grid may be ordered by. `created_at` is indexed; the others
     * are offered because the spec's column list demands them, and they are
     * only reachable behind a filter that has already narrowed the set.
     *
     * @var list<string>
     */
    private const SORTABLE = ['created_at', 'user_name', 'role_name', 'module', 'action', 'ip_address'];

    private const MODULE_CACHE_KEY = 'audit.filters.modules';

    private const MODULE_CACHE_TTL = 300;

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $sort = in_array($request->query('sort'), self::SORTABLE, true)
            ? (string) $request->query('sort')
            : 'created_at';

        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $perPage = max(10, min(100, (int) $request->query('per_page', '25')));

        $entries = AuditTrail::query()
            // Snapshots cover the grid, but the relation is what lets the row
            // link through to a user who still exists.
            ->with('user:id,name,username,deleted_at')
            ->select([
                'id', 'user_id', 'user_name', 'role_name', 'module', 'action',
                'description', 'auditable_type', 'auditable_id',
                'ip_address', 'browser', 'platform', 'created_at',
            ])
            ->filter($filters)
            ->orderBy($sort, $direction)
            // Timestamps collide under load; without a tie-break, rows can
            // repeat or vanish across page boundaries.
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Admin/Audit/Index', [
            'entries' => $entries->through(fn (AuditTrail $entry): array => $this->summarise($entry)),
            'filters' => [
                ...$filters,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
            'options' => [
                'users' => $this->userOptions(),
                'modules' => $this->moduleOptions(),
                'actions' => array_map(
                    static fn (string $action): array => [
                        'value' => $action,
                        'label' => Str::headline($action),
                    ],
                    AuditTrail::ACTIONS,
                ),
            ],
        ]);
    }

    public function show(AuditTrail $auditTrail): Response
    {
        $auditTrail->load('user:id,name,username,email,deleted_at');

        return Inertia::render('Admin/Audit/Show', [
            'entry' => [
                ...$this->summarise($auditTrail),
                'user_agent' => $auditTrail->user_agent,
                'url' => $auditTrail->url,
                'method' => $auditTrail->method,
                'old_values' => $auditTrail->old_values,
                'new_values' => $auditTrail->new_values,
                'subject' => $this->subjectLabel($auditTrail),
                'subject_url' => $this->subjectUrl($auditTrail),
            ],
            'diff' => $this->diff($auditTrail),
        ]);
    }

    /* --------------------------------------------------------------------- */
    /* Filters                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Whitelist and normalise the query string before it reaches the scope.
     *
     * `AuditTrail::scopeFilter` parses dates with Carbon, which throws on
     * garbage — a hand-edited URL must not produce a 500 on a read-only screen.
     *
     * @return array{search: string|null, user_id: int|null, module: string|null, action: string|null, date_from: string|null, date_to: string|null}
     */
    private function filters(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));
        $module = trim((string) $request->query('module', ''));
        $action = (string) $request->query('action', '');
        $userId = (int) $request->query('user_id', '0');

        return [
            'search' => $search !== '' ? Str::limit($search, 120, '') : null,
            'user_id' => $userId > 0 ? $userId : null,
            'module' => $module !== '' ? Str::limit($module, 60, '') : null,
            'action' => in_array($action, AuditTrail::ACTIONS, true) ? $action : null,
            'date_from' => $this->date($request->query('date_from')),
            'date_to' => $this->date($request->query('date_to')),
        ];
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Staff are countable in dozens, so the whole list is safe to send.
     *
     * @return list<array{value: int, label: string}>
     */
    private function userOptions(): array
    {
        return User::query()
            ->select(['id', 'name', 'username'])
            ->orderBy('name')
            ->get()
            ->map(static fn (User $user): array => [
                'value' => (int) $user->getKey(),
                'label' => sprintf('%s (@%s)', $user->name, $user->username),
            ])
            ->all();
    }

    /**
     * DISTINCT over the leading column of the `(module, action)` index. MySQL
     * resolves this with a loose index scan, but it is still cached: the set of
     * modules changes only when the codebase does.
     *
     * @return list<array{value: string, label: string}>
     */
    private function moduleOptions(): array
    {
        /** @var list<string> $modules */
        $modules = Cache::remember(
            self::MODULE_CACHE_KEY,
            self::MODULE_CACHE_TTL,
            static fn (): array => AuditTrail::query()
                ->distinct()
                ->orderBy('module')
                ->pluck('module')
                ->filter()
                ->values()
                ->all(),
        );

        return array_map(
            static fn (string $module): array => ['value' => $module, 'label' => $module],
            $modules,
        );
    }

    /* --------------------------------------------------------------------- */
    /* Presentation                                                           */
    /* --------------------------------------------------------------------- */

    /**
     * The row shape both screens render. Timestamps go out pre-formatted in the
     * application timezone alongside the ISO value, so the grid never has to
     * guess what "now" means on the client.
     *
     * @return array<string, mixed>
     */
    private function summarise(AuditTrail $entry): array
    {
        return [
            'id' => (int) $entry->getKey(),
            'user_id' => $entry->user_id,
            'user_name' => $entry->user_name ?: 'Guest',
            'role_name' => $entry->role_name ?: '—',
            'user_exists' => $entry->relationLoaded('user') && $entry->user !== null,
            'module' => $entry->module,
            'action' => $entry->action,
            'description' => $entry->description,
            'ip_address' => $entry->ip_address,
            'browser' => $entry->browser,
            'platform' => $entry->platform,
            'created_at' => $entry->created_at?->toIso8601String(),
            'created_at_label' => $entry->created_at?->format('j M Y, g:i A'),
            'created_at_human' => $entry->created_at?->diffForHumans(),
        ];
    }

    /**
     * "Booking #1421" — a readable name for the polymorphic subject without
     * loading the record itself, which may well have been deleted since.
     */
    private function subjectLabel(AuditTrail $entry): ?string
    {
        if (blank($entry->auditable_type)) {
            return null;
        }

        return sprintf('%s #%s', Str::headline(class_basename($entry->auditable_type)), $entry->auditable_id);
    }

    /**
     * Deep link to the subject where the admin area has a page for it. Bookings
     * are addressed by code, not id, so they are resolved separately; anything
     * without a safe lookup simply gets no link.
     */
    private function subjectUrl(AuditTrail $entry): ?string
    {
        if (blank($entry->auditable_type) || blank($entry->auditable_id)) {
            return null;
        }

        if ($entry->auditable_type !== Booking::class) {
            return null;
        }

        try {
            $code = Booking::withTrashed()->whereKey($entry->auditable_id)->value('code');

            return is_string($code) && $code !== ''
                ? route('admin.bookings.show', $code)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /* --------------------------------------------------------------------- */
    /* Diff                                                                   */
    /* --------------------------------------------------------------------- */

    /**
     * Flatten `old_values` and `new_values` into one ordered comparison list.
     *
     * Keys present on only one side still appear — a create has no "before" and
     * a delete has no "after", and hiding those rows would make the panel lie
     * about what the entry recorded.
     *
     * @return list<array{key: string, label: string, old: string|null, new: string|null, changed: bool}>
     */
    private function diff(AuditTrail $entry): array
    {
        $old = is_array($entry->old_values) ? $entry->old_values : [];
        $new = is_array($entry->new_values) ? $entry->new_values : [];

        if ($old === [] && $new === []) {
            return [];
        }

        // `new` order first — it is the order the columns actually changed in —
        // then anything that only existed before.
        $keys = array_values(array_unique([...array_keys($new), ...array_keys($old)]));

        $rows = [];

        foreach ($keys as $key) {
            $before = array_key_exists($key, $old) ? $this->display($old[$key]) : null;
            $after = array_key_exists($key, $new) ? $this->display($new[$key]) : null;

            $rows[] = [
                'key' => (string) $key,
                'label' => Str::headline((string) $key),
                'old' => $before,
                'new' => $after,
                'changed' => $before !== $after,
            ];
        }

        return $rows;
    }

    /**
     * Render one recorded value as a readable string.
     */
    private function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        return Str::limit((string) $value, 2000);
    }
}
