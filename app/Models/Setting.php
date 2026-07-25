<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic key/value store. Not auditable — settings churn is written by the
 * settings service, which records its own audit entry with a clean diff.
 */
class Setting extends Model
{
    public const GROUP_PAYMENT = 'payment';

    public const GROUP_COMPANY = 'company';

    public const GROUP_THEME = 'theme';

    public const GROUP_SYSTEM = 'system';

    /** @var list<string> */
    public const GROUPS = [
        self::GROUP_PAYMENT,
        self::GROUP_COMPANY,
        self::GROUP_THEME,
        self::GROUP_SYSTEM,
    ];

    /** @var list<string> */
    public const TYPES = ['string', 'text', 'boolean', 'integer', 'json', 'image'];

    /** @var list<string> */
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
    ];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    /**
     * The stored string coerced into the shape `type` promises.
     *
     * @return Attribute<mixed, never>
     */
    protected function typedValue(): Attribute
    {
        return Attribute::make(
            get: fn (): mixed => static::castValue($this->value, (string) $this->type)
        );
    }

    /**
     * Coerce a raw stored string into its declared PHP type.
     */
    public static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return match ($type) {
                'boolean' => false,
                'integer' => 0,
                'json' => [],
                default => null,
            };
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'json' => json_decode($value, true) ?? [],
            default => $value,
        };
    }

    /**
     * Flatten a PHP value into the text column.
     */
    public static function serializeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) (int) $value,
            'json' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
            default => (string) $value,
        };
    }
}
