<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single writer for the `audit_trails` table.
 *
 * Two design rules drive this class:
 *
 *  1. The record must outlive its subject. `user_name` and `role_name` are
 *     snapshots taken at write time, so deleting a user never blanks history.
 *  2. Auditing must never be the reason a request fails. Every write is
 *     wrapped — a broken audit row degrades to a log line, it does not roll
 *     back a confirmed booking.
 */
class AuditTrailService
{
    /** Valid `action` values, mirroring the enum in the migration. */
    public const ACTIONS = [
        'login', 'logout', 'create', 'view', 'update', 'delete', 'activate', 'deactivate',
    ];

    /**
     * Explicit actor override, used by queue jobs and console commands where
     * there is no authenticated session.
     */
    private static ?User $actor = null;

    /**
     * Name recorded when an unattended process is the actor (scheduler, CLI).
     */
    private static ?string $systemActor = null;

    /**
     * Nesting-safe suppression counter, so a service that writes its own rich
     * audit entry can silence the model trait for the same operation.
     */
    private static int $suppressed = 0;

    /* --------------------------------------------------------------------- */
    /* Actor context                                                          */
    /* --------------------------------------------------------------------- */

    /**
     * Pin an explicit user as the actor (e.g. inside a queued job).
     */
    public static function actingAs(?User $user): void
    {
        self::$actor = $user;
    }

    /**
     * Mark the current process as an unattended system actor.
     */
    public static function actingAsSystem(string $name = 'System'): void
    {
        self::$systemActor = $name;
    }

    /**
     * Drop any actor override and fall back to the authenticated user.
     */
    public static function forgetActor(): void
    {
        self::$actor = null;
        self::$systemActor = null;
    }

    /**
     * Run a callback as an unattended system actor, restoring context after.
     *
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public static function asSystem(callable $callback, string $name = 'System'): mixed
    {
        $previous = self::$systemActor;
        self::$systemActor = $name;

        try {
            return $callback();
        } finally {
            self::$systemActor = $previous;
        }
    }

    /**
     * Is there anybody we can attribute a change to?
     *
     * The `Auditable` trait uses this to stay silent on public, unattended
     * writes (a guest creating a booking) that the service layer logs itself.
     */
    public static function hasActor(): bool
    {
        return self::$actor instanceof User
            || self::$systemActor !== null
            || Auth::hasUser();
    }

    /* --------------------------------------------------------------------- */
    /* Suppression                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * Run a callback with model-level auditing muted.
     *
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public static function withoutAuditing(callable $callback): mixed
    {
        self::$suppressed++;

        try {
            return $callback();
        } finally {
            self::$suppressed = max(0, self::$suppressed - 1);
        }
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressed > 0;
    }

    /* --------------------------------------------------------------------- */
    /* Writing                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Persist one audit entry.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $module,
        string $action,
        string $description,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): ?AuditTrail {
        try {
            $user = self::$actor ?? (Auth::hasUser() ? Auth::user() : null);
            $agent = (string) Request::userAgent();

            $entry = new AuditTrail;
            // The table carries `created_at` only — stamp it by hand.
            $entry->timestamps = false;

            $entry->forceFill([
                'user_id' => $user?->getKey(),
                'user_name' => $this->actorName($user),
                'role_name' => $this->actorRole($user),
                'module' => Str::limit($module, 60, ''),
                'action' => in_array($action, self::ACTIONS, true) ? $action : 'update',
                'description' => Str::limit($description, 500),
                'auditable_type' => $model !== null ? $model->getMorphClass() : null,
                'auditable_id' => $model?->getKey(),
                'old_values' => $this->encodeJson($entry, 'old_values', $oldValues),
                'new_values' => $this->encodeJson($entry, 'new_values', $newValues),
                'ip_address' => Str::limit((string) Request::ip(), 45, ''),
                'user_agent' => Str::limit($agent, 500, ''),
                'browser' => $this->browser($agent),
                'platform' => $this->platform($agent),
                'url' => Str::limit($this->currentUrl(), 500, ''),
                'method' => Str::limit((string) Request::method(), 10, ''),
                'created_at' => now(),
            ])->save();

            return $entry;
        } catch (Throwable $e) {
            // Never let bookkeeping take down the request it is describing.
            Log::warning('Audit trail write failed.', [
                'module' => $module,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function logLogin(User $user): ?AuditTrail
    {
        self::actingAs($user);

        try {
            return $this->log(
                module: 'Authentication',
                action: 'login',
                description: sprintf('%s signed in.', $user->name ?: $user->username),
                model: $user,
            );
        } finally {
            self::actingAs(null);
        }
    }

    public function logLogout(User $user): ?AuditTrail
    {
        self::actingAs($user);

        try {
            return $this->log(
                module: 'Authentication',
                action: 'logout',
                description: sprintf('%s signed out.', $user->name ?: $user->username),
                model: $user,
            );
        } finally {
            self::actingAs(null);
        }
    }

    /**
     * Record that somebody opened a record or a report.
     */
    public function logView(string $module, string $description, ?Model $model = null): ?AuditTrail
    {
        return $this->log($module, 'view', $description, $model);
    }

    /* --------------------------------------------------------------------- */
    /* Snapshots                                                              */
    /* --------------------------------------------------------------------- */

    private function actorName(?User $user): string
    {
        if ($user !== null) {
            return Str::limit((string) ($user->name ?: $user->username), 120, '');
        }

        return self::$systemActor ?? 'Guest';
    }

    private function actorRole(?User $user): string
    {
        if ($user === null) {
            return self::$systemActor !== null ? 'System' : 'Guest';
        }

        try {
            return Str::limit($user->primaryRoleName(), 120, '');
        } catch (Throwable) {
            return '—';
        }
    }

    private function currentUrl(): string
    {
        try {
            return Request::fullUrl();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Hand the JSON columns whatever shape the model expects: an array when it
     * casts them, a pre-encoded string when it does not.
     *
     * @param  array<string, mixed>|null  $values
     */
    private function encodeJson(AuditTrail $entry, string $column, ?array $values): string|array|null
    {
        if ($values === null || $values === []) {
            return null;
        }

        return array_key_exists($column, $entry->getCasts())
            ? $values
            : (json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null);
    }

    /* --------------------------------------------------------------------- */
    /* User-agent parsing                                                     */
    /* --------------------------------------------------------------------- */

    /**
     * Identify the browser family from a user-agent string.
     *
     * Deliberately dependency-free: a handful of ordered substring tests beats
     * pulling a parser library plus its regex database into the app. Order is
     * load-bearing — Edge and Opera both claim to be Chrome, and everything
     * claims to be Safari, so the most specific token must be tested first.
     */
    public function browser(?string $agent): string
    {
        $agent = trim((string) $agent);

        if ($agent === '') {
            return 'Unknown';
        }

        /** @var array<string, string> $map token => label */
        $map = [
            'Edg/' => 'Microsoft Edge',      // Chromium Edge
            'EdgA/' => 'Microsoft Edge',     // Edge on Android
            'EdgiOS/' => 'Microsoft Edge',   // Edge on iOS
            'Edge/' => 'Microsoft Edge',     // Legacy EdgeHTML
            'OPR/' => 'Opera',
            'Opera' => 'Opera',
            'SamsungBrowser' => 'Samsung Internet',
            'YaBrowser' => 'Yandex Browser',
            'Vivaldi' => 'Vivaldi',
            'Brave' => 'Brave',
            'FxiOS' => 'Firefox',
            'Firefox' => 'Firefox',
            'CriOS' => 'Chrome',             // Chrome on iOS
            'Chromium' => 'Chromium',
            'Chrome' => 'Chrome',
            'Safari' => 'Safari',
            'MSIE' => 'Internet Explorer',
            'Trident' => 'Internet Explorer',
            'curl/' => 'curl',
            'PostmanRuntime' => 'Postman',
        ];

        foreach ($map as $token => $label) {
            if (str_contains($agent, $token)) {
                return $label;
            }
        }

        // Crawlers and unknown clients: keep the first token, it is the most
        // identifying part and stops the column filling with noise.
        return Str::limit(Str::before($agent, '/') ?: $agent, 60, '');
    }

    /**
     * Identify the operating system from a user-agent string.
     */
    public function platform(?string $agent): string
    {
        $agent = trim((string) $agent);

        if ($agent === '') {
            return 'Unknown';
        }

        // Mobile checks come first: an iPad reports "Macintosh" in desktop mode
        // and Android reports "Linux", so the broad families must lose.
        return match (true) {
            str_contains($agent, 'iPhone') => 'iOS',
            str_contains($agent, 'iPad') => 'iPadOS',
            str_contains($agent, 'iPod') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'CrOS') => 'Chrome OS',
            // Windows NT 10.0 covers both 10 and 11 — Microsoft froze the token.
            str_contains($agent, 'Windows NT 10.0') => 'Windows 10/11',
            str_contains($agent, 'Windows NT 6.3') => 'Windows 8.1',
            str_contains($agent, 'Windows NT 6.2') => 'Windows 8',
            str_contains($agent, 'Windows NT 6.1') => 'Windows 7',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X'), str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Ubuntu') => 'Ubuntu',
            str_contains($agent, 'Linux') => 'Linux',
            str_contains($agent, 'FreeBSD') => 'FreeBSD',
            default => 'Unknown',
        };
    }
}
