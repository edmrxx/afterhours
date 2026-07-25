<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the company profile.
 *
 * These values are rendered in the public site header and in every outbound
 * email, so they are treated as public copy: trimmed, length-bounded, and
 * never allowed to be blank where a template would show an empty gap.
 */
class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'min:2', 'max:120'],
            'company_legal_name' => ['nullable', 'string', 'max:180'],
            'company_email' => ['required', 'email:rfc', 'max:150'],

            // Landlines are legitimate here (unlike the GCash number), so this
            // is deliberately looser than the payment rule.
            'company_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{6,40}$/'],

            'company_address' => ['nullable', 'string', 'max:400'],

            // 24-hour HH:MM. The window may cross midnight (07:00 → 02:00 is a
            // valid late-night close), so there is no after/before rule between
            // the two — only that they are not the same instant, which would be
            // a zero-length "always closed" day.
            //
            // Optional, not required: an older cached build of this screen (or any
            // client that predates this feature) submits the form without these
            // two fields at all. Rejecting that outright would block every other
            // change on the page until every browser has the new bundle. When
            // BOTH are omitted, the controller keeps whatever is already stored
            // rather than resetting it — see CompanySettingsController::update().
            // When present, they are still fully validated below.
            'operating_opening_time' => ['nullable', 'date_format:H:i'],
            'operating_closing_time' => ['nullable', 'date_format:H:i', 'different:operating_opening_time'],

            'company_logo' => [
                'nullable',
                'file',
                'image',
                'mimetypes:image/png,image/jpeg,image/webp',
                'mimes:png,jpg,jpeg,webp',
                'max:2048',
                'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000',
            ],

            'remove_company_logo' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_phone.regex' => 'Use digits, spaces and the characters + ( ) - only.',
            'operating_closing_time.different' => 'The closing time cannot be the same as the opening time.',
            'company_logo.image' => 'Upload the logo as a PNG, JPG or WebP image.',
            'company_logo.mimetypes' => 'Upload the logo as a PNG, JPG or WebP image.',
            'company_logo.mimes' => 'Upload the logo as a PNG, JPG or WebP image.',
            'company_logo.max' => 'The logo must be 2MB or smaller.',
            'company_logo.dimensions' => 'The logo must be at least 64 x 64 pixels.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_name' => 'company name',
            'company_legal_name' => 'registered legal name',
            'company_email' => 'contact email',
            'company_phone' => 'contact phone',
            'company_address' => 'address',
            'operating_opening_time' => 'opening time',
            'operating_closing_time' => 'closing time',
            'company_logo' => 'logo',
        ];
    }

    /**
     * The operating window must be long enough to hold at least one hourly slot.
     * Without this, a 07:00–07:30 window is a valid H:i pair that produces zero
     * slots — and the "Sync to operating hours" reshape treats an empty window as
     * "every hour is closed", which would delete the whole available schedule.
     *
     * The window is wrap-aware: 22:00 → 01:00 is a legitimate three-hour night.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (\Illuminate\Contracts\Validation\Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $open = $this->minutesOfDay($this->input('operating_opening_time'));
                $close = $this->minutesOfDay($this->input('operating_closing_time'));

                if ($open === null || $close === null) {
                    return;
                }

                if ($close <= $open) {
                    $close += 1440;
                }

                if ($close - $open < 60) {
                    $validator->errors()->add(
                        'operating_closing_time',
                        'The opening hours must span at least one hour.',
                    );
                }
            },
        ];
    }

    /** Minutes since midnight for a valid "H:i", or null. */
    private function minutesOfDay(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
            return null;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'remove_company_logo' => $this->boolean('remove_company_logo'),
            ...$this->trimmed([
                'company_name',
                'company_legal_name',
                'company_email',
                'company_phone',
                'company_address',
            ]),
        ]);
    }

    /**
     * Trim the given string inputs, leaving non-strings and absent keys alone.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function trimmed(array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $out[$key] = trim($value);
            }
        }

        return $out;
    }
}
