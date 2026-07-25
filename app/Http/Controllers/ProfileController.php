<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's own profile.
 *
 * Intentionally carries no permission middleware — everybody manages
 * themselves — which is exactly why every read and write here is pinned to
 * `$request->user()` and never to a route parameter.
 */
class ProfileController extends Controller
{
    /** Directory on the public disk that avatars live in. */
    private const AVATAR_DIRECTORY = 'avatars';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * The profile screen.
     */
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        // Spatie reads `$this->permissions` directly inside getAllPermissions(),
        // which is a lazy load — and `preventLazyLoading()` is armed in local.
        // Load both relations up front so the page is two queries, not a throw.
        $user->loadMissing(['roles', 'permissions']);

        return Inertia::render('Admin/Profile', [
            'profile' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'initials' => $user->initials(),
                'avatar_url' => $this->avatarUrl($user),
                'is_active' => (bool) $user->is_active,
                'must_change_password' => (bool) $user->must_change_password,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_login_ip' => $user->last_login_ip,
                'created_at' => $user->created_at?->toIso8601String(),
                'roles' => $user->getRoleNames()->values(),
                'permission_count' => $user->getAllPermissions()->count(),
            ],
            'maxAvatarMb' => (int) (UpdateProfileRequest::MAX_AVATAR_KB / 1024),
        ]);
    }

    /**
     * Persist name, email, phone and avatar.
     */
    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tracked = ['name', 'email', 'phone', 'avatar_path'];
        $before = Arr::only($user->getOriginal(), $tracked);
        $previousAvatar = $user->avatar_path;

        $user->fill([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
        ]);

        if ($request->hasFile('avatar')) {
            // `store()` generates a random filename, so a user cannot dictate
            // the path on a publicly served disk.
            $user->avatar_path = $request->file('avatar')
                ->store(self::AVATAR_DIRECTORY, 'public');
        } elseif ($request->boolean('remove_avatar')) {
            $user->avatar_path = null;
        }

        $changed = Arr::only($user->getDirty(), $tracked);

        $user->save();

        // Only bin the old file once the new path is safely committed —
        // deleting first would orphan the avatar if the write failed.
        if ($previousAvatar !== null && $user->avatar_path !== $previousAvatar) {
            Storage::disk('public')->delete($previousAvatar);
        }

        if ($changed !== []) {
            $this->audit->log(
                module: 'Profile',
                action: 'update',
                description: sprintf(
                    '%s updated their profile (%s).',
                    $user->name ?: $user->username,
                    implode(', ', array_keys($changed)),
                ),
                model: $user,
                oldValues: Arr::only($before, array_keys($changed)),
                newValues: $changed,
            );
        }

        return $changed === []
            ? back()->with('info', 'There was nothing to save — your profile is unchanged.')
            : back()->with('success', 'Your profile has been updated.');
    }

    /**
     * Public URL for the stored avatar, or null when there is none.
     */
    private function avatarUrl(User $user): ?string
    {
        $path = $user->avatar_path;

        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
