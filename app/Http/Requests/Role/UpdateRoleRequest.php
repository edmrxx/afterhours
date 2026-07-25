<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use App\Policies\RolePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Validation for editing a role.
 *
 * The administrator role is deliberately editable-but-immutable: it renders in
 * the same screen (so its permission set is visible and auditable) while being
 * refused any rename or any reduction of its permissions. Losing the last
 * account able to grant permissions is unrecoverable from inside the app.
 */
class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->target());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name'))
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
        $role = $this->target();

        return [
            'name' => $this->isProtected()
                ? ['required', 'string', Rule::in([$role->name])]
                : [
                    'required',
                    'string',
                    'min:2',
                    'max:60',
                    'regex:/^[\pL][\pL\pN \-\/&\.]*$/u',
                    Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->getKey()),
                ],

            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }

    /**
     * The protected role must keep every permission in the catalogue — not
     * merely the ones it already had, so a permission added by a later release
     * cannot be quietly withheld from administrators.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->isProtected() || $validator->errors()->has('permissions')) {
                return;
            }

            /** @var list<string> $submitted */
            $submitted = (array) $this->input('permissions', []);

            $missing = Permission::query()
                ->where('guard_name', 'web')
                ->whereNotIn('name', $submitted)
                ->count();

            if ($missing > 0) {
                $validator->errors()->add('permissions', sprintf(
                    'The %s role must keep every permission — %d cannot be removed.',
                    RolePolicy::PROTECTED_ROLE,
                    $missing,
                ));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.in' => sprintf('The %s role is built into the system and cannot be renamed.', RolePolicy::PROTECTED_ROLE),
            'name.regex' => 'The role name must start with a letter and may contain letters, numbers, spaces, hyphens, slashes, ampersands and dots.',
            'name.unique' => 'A role with that name already exists.',
            'permissions.required' => 'Select at least one permission — a role with none cannot do anything.',
            'permissions.min' => 'Select at least one permission — a role with none cannot do anything.',
            'permissions.*.exists' => 'One of the selected permissions no longer exists. Reload the page and try again.',
        ];
    }

    public function target(): Role
    {
        /** @var Role $role */
        $role = $this->route('role');

        return $role;
    }

    public function isProtected(): bool
    {
        return (string) $this->target()->name === RolePolicy::PROTECTED_ROLE;
    }
}
