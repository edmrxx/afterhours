<?php

declare(strict_types=1);

namespace App\Http\Requests\Court;

use App\Models\Court;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Court::class) ?? false;
    }

    /**
     * Normalise before validating so the rules judge the value we will store.
     *
     * Inertia sends multipart when a file is attached, which turns every scalar
     * into a string — booleans arrive as "1"/"0" and a cleared number input
     * arrives as "". Both are flattened here rather than in the controller.
     */
    protected function prepareForValidation(): void
    {
        $sortOrder = $this->input('sort_order');

        $this->merge([
            'name' => $this->trimmed('name'),
            // Codes are shouted on signage and read over the phone: one case.
            'code' => $this->trimmed('code') === null ? null : mb_strtoupper((string) $this->trimmed('code')),
            'description' => $this->trimmed('description'),
            'is_active' => $this->boolean('is_active'),
            'sort_order' => $sortOrder === '' || $sortOrder === null ? 0 : $sortOrder,
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:150',
                // Names are not unique in the schema, so a court in the bin must
                // not squat on one — unlike `code`, which the index does police.
                Rule::unique('courts', 'name')->whereNull('deleted_at'),
            ],
            'code' => [
                'required',
                'string',
                'min:2',
                'max:30',
                'regex:/^[A-Z0-9][A-Z0-9\-]*$/',
                // No deleted_at filter: the unique index counts trashed rows too,
                // so allowing a reused code here would only fail at the database.
                Rule::unique('courts', 'code'),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'photo' => [
                'nullable',
                'file',
                'image',
                // Belt and braces: `mimes` reads the extension, `mimetypes`
                // reads the sniffed content type. A renamed .php must fail both.
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:min_width=200,min_height=200,max_width=6000,max_height=6000',
            ],
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
