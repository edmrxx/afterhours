<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cached read/write facade over the generic `settings` table.
 *
 * Settings are read on almost every public page (the GCash QR, the company
 * name, the theme) but written once in a blue moon, so the whole group is
 * cached forever and busted on write. One cache entry per group keeps the
 * payload small and means a payment change never invalidates the theme.
 *
 * Nothing outside this service may query the table directly.
 */
class SettingsService
{
    /** Cache key prefix — one entry per settings group. */
    private const CACHE_PREFIX = 'settings:group:';

    /** Cache key holding the list of groups that exist, so flush() is total. */
    private const GROUP_INDEX_KEY = 'settings:groups';

    /** Types the `type` column accepts. */
    public const TYPES = ['string', 'text', 'boolean', 'integer', 'json', 'image'];

    /**
     * In-request memo: a single page render can ask for a dozen keys and there
     * is no reason to round-trip the cache driver each time.
     *
     * @var array<string, array<string, array{value: string|null, type: string}>>
     */
    private array $memo = [];

    /* --------------------------------------------------------------------- */
    /* Reading                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Fetch one setting, cast to its declared type.
     */
    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $rows = $this->rows($group);

        if (! array_key_exists($key, $rows)) {
            return $default;
        }

        $cast = $this->cast($rows[$key]['value'], $rows[$key]['type']);

        // An empty string is "not configured" for every type except text.
        return $cast === null || $cast === '' ? $default : $cast;
    }

    /**
     * Every setting in a group as `key => cast value`.
     *
     * @return array<string, mixed>
     */
    public function all(string $group): array
    {
        $out = [];

        foreach ($this->rows($group) as $key => $row) {
            $out[$key] = $this->cast($row['value'], $row['type']);
        }

        return $out;
    }

    /**
     * Does the key exist at all (even if empty)?
     */
    public function has(string $group, string $key): bool
    {
        return array_key_exists($key, $this->rows($group));
    }

    /**
     * The declared type of a key, or null when it does not exist.
     */
    public function typeOf(string $group, string $key): ?string
    {
        return $this->rows($group)[$key]['type'] ?? null;
    }

    /* --------------------------------------------------------------------- */
    /* Writing                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Create or update one setting and bust the group cache.
     *
     * `image` values are stored as the relative storage path produced by the
     * upload — never the binary, never a full URL, so moving domains or disks
     * does not invalidate the row.
     */
    public function set(string $group, string $key, mixed $value, ?string $type = null): void
    {
        $type = $this->normaliseType($type ?? $this->typeOf($group, $key) ?? $this->inferType($value));

        Setting::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $this->serialise($value, $type), 'type' => $type],
        );

        $this->forgetGroup($group);
    }

    /**
     * Write a whole group in one transaction — the shape the settings screens
     * submit. Keys absent from the payload are left untouched.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $types  optional `key => type` overrides
     */
    public function setMany(string $group, array $values, array $types = []): void
    {
        DB::transaction(function () use ($group, $values, $types): void {
            foreach ($values as $key => $value) {
                $type = $this->normaliseType(
                    $types[$key] ?? $this->typeOf($group, $key) ?? $this->inferType($value)
                );

                Setting::query()->updateOrCreate(
                    ['group' => $group, 'key' => (string) $key],
                    ['value' => $this->serialise($value, $type), 'type' => $type],
                );
            }
        });

        $this->forgetGroup($group);
    }

    /**
     * Delete a single setting.
     */
    public function forget(string $group, string $key): void
    {
        Setting::query()->where('group', $group)->where('key', $key)->delete();

        $this->forgetGroup($group);
    }

    /**
     * Drop the cache for one group, or for every group when none is given.
     * The rows themselves are untouched — this only forces a re-read.
     */
    public function flush(?string $group = null): void
    {
        if ($group !== null) {
            $this->forgetGroup($group);

            return;
        }

        foreach ($this->knownGroups() as $known) {
            Cache::forget(self::CACHE_PREFIX.$known);
        }

        Cache::forget(self::GROUP_INDEX_KEY);
        $this->memo = [];
    }

    /* --------------------------------------------------------------------- */
    /* Internals                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * Raw rows for a group, keyed by setting key.
     *
     * @return array<string, array{value: string|null, type: string}>
     */
    private function rows(string $group): array
    {
        if (isset($this->memo[$group])) {
            return $this->memo[$group];
        }

        try {
            /** @var array<string, array{value: string|null, type: string}> $rows */
            $rows = Cache::rememberForever(
                self::CACHE_PREFIX.$group,
                fn (): array => Setting::query()
                    ->where('group', $group)
                    ->get(['key', 'value', 'type'])
                    ->mapWithKeys(fn (Setting $setting): array => [
                        (string) $setting->key => [
                            'value' => $setting->value === null ? null : (string) $setting->value,
                            'type' => (string) ($setting->type ?: 'string'),
                        ],
                    ])
                    ->all(),
            );
        } catch (Throwable) {
            // Missing table during a first-boot / migration window must not take
            // the whole page down — behave as "nothing configured yet".
            $rows = [];
        }

        $this->rememberGroup($group);

        return $this->memo[$group] = $rows;
    }

    private function forgetGroup(string $group): void
    {
        Cache::forget(self::CACHE_PREFIX.$group);
        unset($this->memo[$group]);
    }

    /**
     * @return list<string>
     */
    private function knownGroups(): array
    {
        try {
            /** @var list<string> $groups */
            $groups = Cache::get(self::GROUP_INDEX_KEY, []);

            $fromDb = Setting::query()->distinct()->pluck('group')->map(
                static fn (mixed $g): string => (string) $g
            )->all();

            return array_values(array_unique([...$groups, ...$fromDb]));
        } catch (Throwable) {
            return array_keys($this->memo);
        }
    }

    private function rememberGroup(string $group): void
    {
        try {
            /** @var list<string> $groups */
            $groups = Cache::get(self::GROUP_INDEX_KEY, []);

            if (! in_array($group, $groups, true)) {
                $groups[] = $group;
                Cache::forever(self::GROUP_INDEX_KEY, array_values($groups));
            }
        } catch (Throwable) {
            // Index is an optimisation for flush(); losing it is not fatal.
        }
    }

    private function normaliseType(string $type): string
    {
        return in_array($type, self::TYPES, true) ? $type : 'string';
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    /**
     * Turn a PHP value into the string the `value` column stores.
     */
    private function serialise(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) (int) $value,
            'json' => is_string($value) ? $value : (json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]'),
            // `image` holds the storage-relative path, e.g. "settings/gcash-qr.png".
            'image' => ltrim((string) $value, '/'),
            default => (string) $value,
        };
    }

    /**
     * Turn the stored string back into a usable PHP value.
     */
    private function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return $type === 'boolean' ? false : null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'integer' => (int) $value,
            'json' => json_decode($value, true) ?? [],
            default => $value,
        };
    }
}
