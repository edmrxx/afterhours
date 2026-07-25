<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version (busts the client cache on deploy).
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every Inertia response.
     *
     * Keep this lean: it ships on every single request. Anything expensive or
     * page-specific belongs in the controller, not here.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // Spatie's getAllPermissions() reads $this->permissions directly rather
        // than going through loadMissing(), so on a route-model-bound user it is
        // a lazy load — and AppServiceProvider arms preventLazyLoading() locally.
        // Eager-load both relations here so every page share stays safe.
        $user?->loadMissing(['roles.permissions', 'permissions']);

        return array_merge(parent::share($request), [
            'appName' => config('app.name'),

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'initials' => $user->initials(),
                    'avatar_url' => $this->avatarUrl($user),
                    'is_active' => (bool) $user->is_active,
                    'must_change_password' => (bool) $user->must_change_password,
                    'roles' => $user->getRoleNames(),
                    // Flat permission list drives menu visibility + button gating.
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],

            // One-shot notifications rendered by SweetAlert2 on the client.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],

            'ziggy' => fn () => [
                ...(new \Tighten\Ziggy\Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ]);
    }

    /**
     * Public URL for the user's avatar, or null when they have not uploaded one.
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
