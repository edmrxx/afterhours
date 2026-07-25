<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Password rotation for the signed-in user.
 *
 * This is the only screen reachable while `must_change_password` is set — it
 * is deliberately excluded from the `password.changed` middleware's redirect,
 * because it is the way out of it.
 */
class PasswordController extends Controller
{
    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * The change-password screen.
     */
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Auth/ChangePassword', [
            // Drives the "why am I here?" explanation on the client: a forced
            // rotation reads very differently from a voluntary one.
            'mustChange' => (bool) $user->must_change_password,
            'user' => [
                'name' => $user->name,
                'username' => $user->username,
                'initials' => $user->initials(),
            ],
        ]);
    }

    /**
     * Persist the new password and clear the rotation flag.
     */
    public function update(ChangePasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The `password` cast on the model hashes on assignment — the plain
        // value never reaches the database, and it is never audited or logged.
        $user->forceFill([
            'password' => (string) $request->validated('password'),
            'must_change_password' => false,
        ])->save();

        // A credential change is a privilege change: rotate the session id, and
        // refresh the hash the guard uses to detect a stale session so the
        // current browser is not signed out by its own update.
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->getAuthPassword());

        $this->audit->log(
            module: 'Authentication',
            action: 'update',
            description: sprintf(
                '%s changed their own password.',
                $user->name ?: $user->username,
            ),
            model: $user,
        );

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'Your password has been updated.');
    }
}
