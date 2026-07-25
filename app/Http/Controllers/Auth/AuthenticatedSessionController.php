<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign in and sign out. Username based — `email` is optional on a user and is
 * never an authentication identifier in this system.
 */
class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * The sign-in screen.
     */
    public function create(): Response
    {
        $isLocal = app()->environment('local');

        return Inertia::render('Auth/Login', [
            // Gates the "default credentials" hint on the client. The seeded
            // password is public knowledge from the architecture doc, but it
            // still has no business rendering on a production login page.
            'isLocal' => $isLocal,
            'defaultCredentials' => $isLocal
                ? ['username' => 'admin', 'password' => 'admin123']
                : null,
        ]);
    }

    /**
     * Authenticate and open a session.
     *
     * `LoginRequest::authenticate()` owns the throttling and the credential
     * check; everything below is post-login bookkeeping.
     *
     * @throws ValidationException
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Rotate the session identifier the moment the privilege level changes
        // — this is the fixation defence, and it must happen before anything
        // is written into the session.
        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();

        if (! $user->is_active) {
            $this->forceLogout($request);

            throw ValidationException::withMessages([
                'username' => 'This account has been deactivated. Please contact an administrator.',
            ]);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        $this->audit->logLogin($user);

        if ($user->must_change_password) {
            // Going anywhere else would only bounce off the `password.changed`
            // middleware, so send them straight there with the real reason.
            return redirect()
                ->route('password.change')
                ->with('warning', 'Your account still uses its initial password. Please choose a new one to continue.');
        }

        return redirect()
            ->intended(route('admin.dashboard'))
            ->with('success', sprintf('Welcome back, %s.', $user->name ?: $user->username));
    }

    /**
     * Sign out and return to the public site.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Audit first: once the session is invalidated there is no actor left
        // to attribute the entry to.
        if ($user instanceof User) {
            $this->audit->logLogout($user);
        }

        $this->forceLogout($request);

        return redirect()
            ->route('public.courts.index')
            ->with('success', 'You have been signed out.');
    }

    /**
     * Tear the session down completely: guard, session data, CSRF token.
     */
    private function forceLogout(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
