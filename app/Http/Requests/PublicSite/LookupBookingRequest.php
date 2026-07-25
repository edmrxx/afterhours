<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicSite;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Check my booking" — the booking code is the only credential on the public
 * site, so this request does two jobs:
 *
 *  1. forgives how people type the code (lower case, missing prefix, spaces),
 *  2. refuses anything that could not possibly be a code, so a scripted
 *     enumeration attempt burns its rate-limit budget on validation errors
 *     rather than on database lookups.
 */
class LookupBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'min:6',
                'max:40',
                // PREFIX-XXXXXXXX, using the unambiguous code alphabet.
                'regex:/^[A-Z0-9]+-[A-Z0-9]{4,20}$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Please enter your booking code.',
            'code.regex' => 'That does not look like a booking code. It looks like '
                .$this->prefix().'-XXXXXXXX.',
            'code.min' => 'That booking code is too short.',
            'code.max' => 'That booking code is too long.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'booking code'];
    }

    /**
     * Upper-case it, strip the whitespace people paste in, and add the prefix
     * back when the customer only quoted the second half of their code.
     */
    protected function prepareForValidation(): void
    {
        $code = mb_strtoupper(trim((string) $this->input('code')));
        $code = (string) preg_replace('/[\s_]+/u', '', $code);

        if ($code !== '' && ! str_contains($code, '-')) {
            $code = $this->prefix().'-'.$code;
        }

        $this->merge(['code' => $code]);
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }

    private function prefix(): string
    {
        return Booking::codePrefix();
    }
}
