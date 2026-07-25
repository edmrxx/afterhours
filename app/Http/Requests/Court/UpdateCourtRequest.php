<?php

declare(strict_types=1);

namespace App\Http\Requests\Court;

use App\Models\Court;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourtRequest extends FormRequest
{
    public function authorize(): bool
    {
        $court = $this->court();

        return $court !== null && ($this->user()?->can('update', $court) ?? false);
    }

    /**
     * Normalise before validating so the rules judge the value we will store.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->trimmed('code');
        $sortOrder = $this->input('sort_order');

        $this->merge([
            'name' => $this->trimmed('name'),
            'code' => $code === null ? null : mb_strtoupper($code),
            'description' => $this->trimmed('description'),
            'is_active' => $this->boolean('is_active'),
            'remove_photo' => $this->boolean('remove_photo'),
            'sort_order' => $sortOrder === '' || $sortOrder === null ? 0 : $sortOrder,
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $court = $this->court();
        $ignore = $court?->getKey();

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:150',
                Rule::unique('courts', 'name')->ignore($ignore)->whereNull('deleted_at'),
            ],
            'code' => [
                'required',
                'string',
                'min:2',
                'max:30',
                'regex:/^[A-Z0-9][A-Z0-9\-]*$/',
                // Trashed rows are intentionally not excluded: the unique index
                // still holds their code, so permitting it here would 500 later.
                Rule::unique('courts', 'code')->ignore($ignore),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'photo' => [
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:min_width=200,min_height=200,max_width=6000,max_height=6000',
            ],
            // Clears the existing photo without uploading a replacement.
            'remove_photo' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the court a name — this is what customers see on the booking site.',
            'name.unique' => 'Another court already uses this name. Pick something customers can tell apart.',
            'code.required' => 'A short code is required, for example CRT-01.',
            'code.unique' => 'That code is already taken. Codes must stay unique, including for deleted courts.',
            'code.regex' => 'Use letters, numbers and hyphens only, starting with a letter or number (e.g. CRT-01).',
            'sort_order.integer' => 'Display order must be a whole number.',
            'photo.image' => 'The photo must be an image file.',
            'photo.mimes' => 'The photo must be a JPG, PNG or WEBP file.',
            'photo.mimetypes' => 'That file is not a real JPG, PNG or WEBP image.',
            'photo.max' => 'The photo may not be larger than 2MB.',
            'photo.dimensions' => 'The photo must be at least 200x200 pixels.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sort_order' => 'display order',
            'is_active' => 'status',
        ];
    }

    /**
     * The court being edited, resolved from the route's implicit binding.
     */
    private function court(): ?Court
    {
        $court = $this->route('court');

        return $court instanceof Court ? $court : null;
    }

    private function trimmed(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
