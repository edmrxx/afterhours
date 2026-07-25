<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation for editing an existing account.
 *
 * Beyond the obvious field rules this request enforces the two structural
 * guards from `UserPolicy` at the *input* boundary as well as the policy
 * boundary, because the UI hides the controls but a crafted request must not
 * be able to slip past them:
 *
 *  - you cannot change your own role;
 *  - you cannot deactivate yourself;
 *  - the last remaining administrator can be neither demoted nor deactivated.
 *
 * When a field is locked and simply absent from the payload (the normal case,
 * since the form hides it) the current value is merged in, so the controller
 * always receives a complete, already-safe data set.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->target());
    }

    protected function prepareForValidation(): void
    {
        $target = $this->target();

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'username' => is_string($this->input('username'))
                ? mb_strtolower(trim($this->input('username')))
                : $this->input('username'),
            'email' => filled($this->input('email'))
                ? mb_strtolower(trim((string) $this->input('email')))
                : null,
            'phone' => filled($this->input('phone')) ? trim((string) $this->input('phone')) : null,
            // Blank means "leave the password alone", never "set it to empty".
            'password' => filled($this->input('password')) ? $this->input('password') : null,
        ]);

        if ($this->roleIsLocked() && ! $this->has('role')) {
            $this->merge(['role' => $this->currentRole()]);
        }

        if ($this->statusIsLocked() && ! $this->has('is_active')) {
            $this->merge(['is_active' => true]);
        }

        if ($this->has('is_active')) {
            $this->merge(['is_active' => $this->boolean('is_active')]);
        } else {
            $this->merge(['is_active' => (bool) $target->is_active]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->target()->getKey();
        $current = $this->currentRole();

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            'username' => [
                'required',
                'string',
                'min:3',
                'max:60',
                'regex:/^[a-z0-9]+(?:[._][a-z0-9]+)*$/',
                Rule::unique('users', 'username')->ignore($id),
            ],

            'email' => ['nullable', 'email:filter', 'max:190', Rule::unique('users', 'email')->ignore($id)],

            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]{7,32}$/'],

            'role' => $this->roleIsLocked()
                ? [$current === null ? 'nullable' : 'required', 'string', Rule::in(array_filter([$current]))]
                : ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],

            'is_active' => $this->statusIsLocked()
                ? ['required', 'boolean', 'accepted']
                : ['required', 'boolean'],

            // Absent or blank leaves the existing password untouched.
            'password' => ['nullable', 'string', Password::min(8)->letters()->numbers(), 'max:72'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $reasonRole = $this->isSelf()
            ? 'You cannot change your own role.'
            : sprintf('This is the last remaining %s account — its role cannot be changed.', UserPolicy::PROTECTED_ROLE);

        $reasonStatus = $this->isSelf()
            ? 'You cannot deactivate your own account.'
            : sprintf('This is the last active %s account — it cannot be deactivated.', UserPolicy::PROTECTED_ROLE);

        return [
            'username.regex' => 'The username may only contain lowercase letters, numbers, dots and underscores, and must start and end with a letter or number.',
            'phone.regex' => 'Enter a valid contact number, for example 0917 123 4567.',
            'role.in' => $reasonRole,
            'is_active.accepted' => $reasonStatus,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['is_active' => 'account status'];
    }

    /* --------------------------------------------------------------------- */
    /* Context                                                                */
    /* --------------------------------------------------------------------- */

    public function target(): User
    {
        /** @var User $user */
        $user = $this->route('user');

        return $user;
    }

    public function isSelf(): bool
    {
        return $this->user()?->is($this->target()) === true;
    }

    public function roleIsLocked(): bool
    {
        return Gate::inspect('assignRole', $this->target())->denied();
    }

    public function statusIsLocked(): bool
    {
        $target = $this->target();

        // Only meaningful while the account is on: reactivating is never blocked.
        return $target->is_active && Gate::inspect('toggleStatus', $target)->denied();
    }

    public function currentRole(): ?string
    {
        $target = $this->target();
        $target->loadMissing('roles');

        $name = $target->roles->first()?->name;

        return $name === null ? null : (string) $name;
    }
}
