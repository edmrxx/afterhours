<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * The forced-rotation form reached through the `password.changed` middleware.
 *
 * Proving knowledge of the current password matters even though the user is
 * already authenticated: it stops an unattended, still-logged-in browser from
 * being used to lock the real owner out of their account.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => [
                'required',
                'string',
                'confirmed',
                // Rotating to the same value defeats the entire point of the
                // screen, so reject it explicitly rather than silently passing.
                'different:current_password',
                self::passwordPolicy(),
            ],
        ];
    }

    /**
     * The house password policy: at least 8 characters, mixed case, a digit,
     * and absent from the Have I Been Pwned breach corpus.
     *
     * Stated explicitly rather than deferring to `Password::defaults()`, which
     * is only a bare eight-character floor unless a service provider registers
     * something stronger — and no provider in this application does. The
     * `uncompromised()` check fails open when the k-anonymity API is
     * unreachable, so an offline environment never blocks a rotation.
     */
    public static function passwordPolicy(): Password
    {
        return Password::min(8)->mixedCase()->numbers()->uncompromised();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Please enter your current password.',
            'current_password.current_password' => 'That is not your current password.',
            'password.required' => 'Please choose a new password.',
            'password.confirmed' => 'The two new passwords do not match.',
            'password.different' => 'Your new password must be different from your current one.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'current password',
            'password' => 'new password',
            'password_confirmation' => 'password confirmation',
        ];
    }
}
