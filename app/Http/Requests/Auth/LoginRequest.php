<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates, throttles and performs the username/password sign-in attempt.
 *
 * The attempt lives here rather than in the controller for one reason: rate
 * limiting and authentication are a single indivisible decision. Splitting
 * them across two classes is how "the throttle counter never got hit on
 * failure" bugs happen.
 *
 * Two layers of throttling are in play. The route carries `throttle:10,1`,
 * which is a crude per-IP flood guard. This class adds the real policy:
 * five failures for a given *username + IP* pair, then a timed lockout with a
 * message that tells the user exactly how long to wait. Keying on the pair
 * means one attacker cannot lock a legitimate user out of their own account
 * from a different address.
 */
class LoginRequest extends FormRequest
{
    /** Failures tolerated before the account/IP pair is locked out. */
    public const MAX_ATTEMPTS = 5;

    /** How long the lockout lasts, in seconds. */
    public const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        // The route sits behind the `guest` middleware; anyone reaching this
        // is by definition allowed to try.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Please enter your username.',
            'password.required' => 'Please enter your password.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'username' => 'username',
            'password' => 'password',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Browsers happily submit a trailing space from an autofill or a paste;
        // the password is left untouched because whitespace can be meaningful.
        $this->merge([
            'username' => trim((string) $this->input('username')),
        ]);
    }

    /**
     * Attempt to authenticate the request.
     *
     * @throws ValidationException when the credentials fail or the pair is locked out
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('web')->attempt($this->credentials(), $this->remember())) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'username' => 'Those credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * The credential pair handed to the guard.
     *
     * Deliberately *not* filtered by `is_active`: an inactive user must get a
     * clear "your account is deactivated" message, not a misleading "wrong
     * password". The controller signs them straight back out.
     *
     * @return array<string, string>
     */
    public function credentials(): array
    {
        return [
            'username' => (string) $this->input('username'),
            'password' => (string) $this->input('password'),
        ];
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }

    /**
     * Throw once the pair has burned through its allowance.
     *
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'username' => $this->lockoutMessage(RateLimiter::availableIn($this->throttleKey())),
        ]);
    }

    /**
     * A lockout message that states the remaining wait in human units.
     */
    protected function lockoutMessage(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf(
                'Too many sign-in attempts. Please try again in %d %s.',
                max($seconds, 1),
                $seconds === 1 ? 'second' : 'seconds',
            );
        }

        $minutes = (int) ceil($seconds / 60);

        return sprintf(
            'Too many sign-in attempts. Please try again in %d %s.',
            $minutes,
            $minutes === 1 ? 'minute' : 'minutes',
        );
    }

    /**
     * Rate-limiter key: the username and the origin address together.
     *
     * `transliterate` folds accented input down to ASCII so two spellings of
     * the same username cannot each get their own fresh allowance.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(
            'login|'.Str::lower((string) $this->input('username')).'|'.$this->ip()
        );
    }
}
