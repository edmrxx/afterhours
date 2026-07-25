<?php

declare(strict_types=1);

namespace App\Traits;

use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drop-in audit coverage for an Eloquent model.
 *
 * Usage:
 *
 *     class Court extends Model
 *     {
 *         use Auditable;
 *
 *         protected static string $auditModule = 'Courts';
 *     }
 *
 * What it guarantees:
 *
 *  - Only genuinely changed attributes are recorded on update; a `save()` that
 *    rewrites identical values produces no row.
 *  - Secrets never reach the audit table. `password`, `remember_token` and
 *    everything in the model's `$hidden` array are stripped from both sides of
 *    the diff, and from the values entirely.
 *  - It stays silent when nobody is logged in and no system actor has been
 *    declared. Public booking writes are audited by `BookingService` with far
 *    better prose than a generic diff could produce, so the trait must not
 *    duplicate them.
 *
 * @mixin Model
 */
trait Auditable
{
    /**
     * Attributes never worth recording, on top of the model's own `$hidden`.
     *
     * @var list<string>
     */
    protected static array $auditNeverLog = [
        'password',
        'password_confirmation',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
        'created_at',
        'updated_at',
    ];

    /**
     * Register the model hooks. Laravel calls this automatically.
     */
    public static function bootAuditable(): void
    {
        static::created(static function (Model $model): void {
            $model->recordAuditEvent('create');
        });

        static::updated(static function (Model $model): void {
            $model->recordAuditEvent('update');
        });

        static::deleted(static function (Model $model): void {
            $model->recordAuditEvent('delete');
        });

        // Only present when the model soft-deletes.
        if (method_exists(static::class, 'restored')) {
            static::restored(static function (Model $model): void {
                $model->recordAuditEvent('activate');
            });
        }
    }

    /**
     * The audit `module` this model belongs to.
     */
    public function auditModule(): string
    {
        if (isset(static::$auditModule) && is_string(static::$auditModule) && static::$auditModule !== '') {
            return static::$auditModule;
        }

        // Sensible fallback so a model that forgets the property still audits.
        return Str::plural(Str::headline(class_basename(static::class)));
    }

    /**
     * Human label for this record inside the description ("Court A").
     */
    public function auditLabel(): string
    {
        foreach (['name', 'title', 'code', 'username', 'label', 'slug'] as $attribute) {
            $value = $this->getAttribute($attribute);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return Str::limit(trim((string) $value), 80);
            }
        }

        return '#'.$this->getKey();
    }

    /**
     * Singular, readable model name used in the description ("Court").
     */
    public function auditSubject(): string
    {
        return Str::headline(class_basename(static::class));
    }

    /**
     * Attributes that must never be written to the audit trail.
     *
     * @return list<string>
     */
    public function auditExcluded(): array
    {
        /** @var list<string> $extra */
        $extra = isset(static::$auditExclude) && is_array(static::$auditExclude)
            ? array_values(static::$auditExclude)
            : [];

        return array_values(array_unique(array_merge(
            static::$auditNeverLog,
            $this->getHidden(),
            $extra,
        )));
    }

    /**
     * Build and persist the entry for one lifecycle event.
     */
    public function recordAuditEvent(string $action): void
    {
        try {
            if (AuditTrailService::isSuppressed() || ! AuditTrailService::hasActor()) {
                return;
            }

            $old = null;
            $new = null;

            if ($action === 'update') {
                [$old, $new] = $this->auditChangeSet();

                // Nothing meaningful moved — do not write a hollow row.
                if ($new === []) {
                    return;
                }
            } elseif ($action === 'create') {
                $new = $this->auditSafeAttributes($this->getAttributes());
            }

            app(AuditTrailService::class)->log(
                module: $this->auditModule(),
                action: $action,
                description: $this->auditDescription($action, $new ?? [], $old ?? []),
                model: $this,
                oldValues: $old,
                newValues: $new,
            );
        } catch (Throwable) {
            // Auditing is observability, not business logic. Swallow and move on;
            // AuditTrailService has already logged anything worth knowing.
        }
    }

    /**
     * The genuinely dirty attributes, split into before and after maps.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function auditChangeSet(): array
    {
        $excluded = $this->auditExcluded();
        $old = [];
        $new = [];

        foreach ($this->getChanges() as $key => $value) {
            if (in_array($key, $excluded, true)) {
                continue;
            }

            $before = $this->getOriginal($key);

            // getChanges() is already "what actually moved", but a cast can make
            // 1 and "1" look different. Compare loosely before recording.
            if ($this->auditValuesMatch($before, $value)) {
                continue;
            }

            $old[$key] = $this->auditScalar($before);
            $new[$key] = $this->auditScalar($value);
        }

        return [$old, $new];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditSafeAttributes(array $attributes): array
    {
        $excluded = $this->auditExcluded();
        $safe = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, $excluded, true)) {
                continue;
            }

            $safe[$key] = $this->auditScalar($value);
        }

        return $safe;
    }

    /**
     * Flatten a value into something JSON-safe and readable.
     */
    protected function auditScalar(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return Str::limit((string) $value, 255);
        }

        if (is_object($value)) {
            return null;
        }

        return Str::limit((string) $value, 255);
    }

    protected function auditValuesMatch(mixed $a, mixed $b): bool
    {
        $a = $this->auditScalar($a);
        $b = $this->auditScalar($b);

        if (is_array($a) || is_array($b)) {
            return $a === $b;
        }

        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return (string) $a === (string) $b;
    }

    /**
     * Readable prose: "Updated Court 'Court A' (price: 400 -> 450)".
     *
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     */
    protected function auditDescription(string $action, array $new, array $old): string
    {
        $verb = match ($action) {
            'create' => 'Created',
            'update' => 'Updated',
            'delete' => 'Deleted',
            'activate' => 'Restored',
            default => Str::ucfirst($action),
        };

        $sentence = sprintf("%s %s '%s'", $verb, $this->auditSubject(), $this->auditLabel());

        if ($action !== 'update' || $new === []) {
            return $sentence;
        }

        $parts = [];

        // Cap the list: a wide model can change 30 columns at once and the
        // description column is not the place to store all of them — the full
        // diff already lives in old_values/new_values.
        foreach (array_slice($new, 0, 5, true) as $key => $value) {
            $parts[] = sprintf(
                '%s: %s -> %s',
                Str::of($key)->replace('_', ' ')->trim()->toString(),
                $this->auditDisplay($old[$key] ?? null),
                $this->auditDisplay($value),
            );
        }

        if (count($new) > 5) {
            $parts[] = sprintf('+%d more', count($new) - 5);
        }

        return $sentence.' ('.implode(', ', $parts).')';
    }

    protected function auditDisplay(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            return Str::limit((string) json_encode($value), 60);
        }

        return Str::limit((string) $value, 60);
    }
}
