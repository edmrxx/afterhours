<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation for inviting a new staff account.
 *
 * The password is optional on purpose: leaving it blank (or asking for one to
 * be generated) is the normal path — the controller mints a strong temporary
 * password, shows it to the inviter once, and forces rotation on first login.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', \App\Models\User::class);
    }

    /**
     * Normalise before the rules see the payload.
     *
     * Usernames are the login identifier, so they are case-folded and trimmed
     * here rather than in the controller — that way the `unique` rule below
     * compares the value that will actually be stored.
     */
    protected function prepareForValidation(): void
    {
        $generate = $this->boolean('generate_password');

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'username' => is_string($this->input('username'))
                ? mb_strtolower(trim($this->input('username')))
                : $this->input('username'),
            'email' => filled($this->input('email'))
                ? mb_strtolower(trim((string) $this->input('email')))
                : null,
            'phone' => filled($this->input('phone')) ? trim((string) $this->input('phone')) : null,
            'generate_password' => $generate,
            // An explicit "generate it for me" wins over anything typed.
            'password' => $generate ? null : $this->input('password'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            // Soft-deleted rows keep their username because the DB unique index
            // spans them, so the rule must NOT ignore trashed accounts.
            'username' => [
                'required',
                'string',
                'min:3',
                'max:60',
                'regex:/^[a-z0-9]+(?:[._][a-z0-9]+)*$/',
                Rule::unique('users', 'username'),
            ],

            'email' => ['nullable', 'email:filter', 'max:190', Rule::unique('users', 'email')],

            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]{7,32}$/'],

            // Any role in the table, including ones created long after this
            // code shipped — that is the whole point of data-driven RBAC.
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],

            'generate_password' => ['required', 'boolean'],

            'password' => [
                Rule::requiredIf(fn (): bool => ! $this->boolean('generate_password')),
                'nullable',
                'string',
                Password::min(8)->letters()->numbers(),
                'max:72',
            ],

            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'The username may only contain lowercase letters, numbers, dots and underscores, and must start and end with a letter or number.',
            'phone.regex' => 'Enter a valid contact number, for example 0917 123 4567.',
            'role.exists' => 'Choose a role that still exists.',
            'password.required' => 'Set a password, or switch on "Generate a temporary password".',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'is_active' => 'account status',
            'generate_password' => 'password generation',
        ];
    }
}
