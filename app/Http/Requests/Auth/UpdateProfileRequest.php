<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Own-profile edit. Scoped hard to the four fields a user may change about
 * themselves — `username`, `is_active`, roles and permissions are all managed
 * from the Users module and must never be assignable from here.
 */
class UpdateProfileRequest extends FormRequest
{
    /** Upload ceiling in kilobytes (2 MB), mirrored in the client hint. */
    public const MAX_AVATAR_KB = 2048;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getKey();

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            'email' => [
                'nullable',
                'string',
                'email:rfc',
                'max:255',
                // Soft-deleted rows keep occupying the unique index at the
                // database level, but a deleted colleague should not stop a
                // living user claiming an address, so scope the check.
                Rule::unique('users', 'email')
                    ->ignore($userId)
                    ->whereNull('deleted_at'),
            ],

            // Philippine mobile and landline formats, plus the +63 country
            // form. Kept permissive on separators, strict on the character set.
            'phone' => [
                'nullable',
                'string',
                'min:7',
                'max:32',
                'regex:/^\+?[0-9][0-9\s().-]{5,30}$/',
            ],

            'avatar' => [
                'nullable',
                'file',
                'image',
                // Extension and reported MIME are both checked: `mimes` reads
                // the real content type via finfo, `mimetypes` pins the exact
                // set we are willing to serve back out of the public disk.
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.self::MAX_AVATAR_KB,
            ],

            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please enter your name.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'That email address is already in use.',
            'phone.regex' => 'Please enter a valid phone number, e.g. 0917 123 4567.',
            'avatar.image' => 'Your avatar must be an image file.',
            'avatar.mimes' => 'Your avatar must be a JPG, PNG or WEBP image.',
            'avatar.mimetypes' => 'Your avatar must be a JPG, PNG or WEBP image.',
            'avatar.max' => 'Your avatar must be 2MB or smaller.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'name',
            'email' => 'email address',
            'phone' => 'phone number',
            'avatar' => 'profile photo',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Empty strings arriving from a cleared input are normalised to null so
        // the nullable rules apply and the column is blanked rather than set to ''.
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => $this->nullIfBlank($this->input('email')),
            'phone' => $this->nullIfBlank($this->input('phone')),
        ]);
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
