<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Validation for creating a role.
 *
 * Roles are data, not code: anything the administrator can name — Manager,
 * Supervisor, Cashier, Encoder, Accountant — is valid, and the permission set
 * is validated against whatever rows exist in `permissions` at the time. No
 * hardcoded catalogue lives here, so adding a permission to the seeder is the
 * only change ever needed to expose it in the matrix.
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Role::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name'))
                // Collapse internal whitespace so "Front  Desk" and "Front Desk"
                // cannot both exist and confuse the assignment dropdown.
                ? trim(preg_replace('/\s+/u', ' ', $this->input('name')) ?? '')
                : $this->input('name'),
            'permissions' => array_values(array_unique(
                array_filter(
                    (array) $this->input('permissions', []),
                    static fn ($value): bool => is_string($value) && $value !== '',
                ),
            )),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:60',
                'regex:/^[\pL][\pL\pN \-\/&\.]*$/u',
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],

            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The role name must start with a letter and may contain letters, numbers, spaces, hyphens, slashes, ampersands and dots.',
            'name.unique' => 'A role with that name already exists.',
            'permissions.required' => 'Select at least one permission — a role with none cannot do anything.',
            'permissions.min' => 'Select at least one permission — a role with none cannot do anything.',
            'permissions.*.exists' => 'One of the selected permissions no longer exists. Reload the page and try again.',
        ];
    }
}
