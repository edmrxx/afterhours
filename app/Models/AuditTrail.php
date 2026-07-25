<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The audit log itself is deliberately NOT auditable — recording writes to the
 * audit table would recurse and double every action.
 */
class AuditTrail extends Model
{
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    public const ACTION_LOGIN = 'login';

    public const ACTION_LOGOUT = 'logout';

    public const ACTION_CREATE = 'create';

    public const ACTION_VIEW = 'view';

    public const ACTION_UPDATE = 'update';

    public const ACTION_DELETE = 'delete';

    public const ACTION_ACTIVATE = 'activate';

    public const ACTION_DEACTIVATE = 'deactivate';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_LOGIN,
        self::ACTION_LOGOUT,
        self::ACTION_CREATE,
        self::ACTION_VIEW,
        self::ACTION_UPDATE,
        self::ACTION_DELETE,
        self::ACTION_ACTIVATE,
        self::ACTION_DEACTIVATE,
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'user_name',
        'role_name',
        'module',
        'action',
        'description',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'browser',
        'platform',
        'url',
        'method',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
            'user_id' => 'integer',
            'auditable_id' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Relationships                                                       */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /* ------------------------------------------------------------------ */
    /* Scopes                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Drives the audit index screen. Every key is optional; unknown or blank
     * values are ignored so the caller can pass $request->all() safely.
     *
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<self>
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['search'])).'%';

                $q->where(function (Builder $inner) use ($like): void {
                    $inner->where('user_name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('module', 'like', $like)
                        ->orWhere('ip_address', 'like', $like);
                });
            })
            ->when(filled($filters['module'] ?? null), fn (Builder $q) => $q->where('module', $filters['module']))
            ->when(filled($filters['action'] ?? null), fn (Builder $q) => $q->where('action', $filters['action']))
            ->when(filled($filters['user_id'] ?? null), fn (Builder $q) => $q->where('user_id', (int) $filters['user_id']))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay()))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay()));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
