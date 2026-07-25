<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicSite;

use App\Models\Booking;
use App\Services\PaymentMethodService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 2 of the funnel — the customer hands over proof of payment.
 *
 * The amount is never accepted from the client: it was copied onto the booking
 * from the slot at reserve() time and is re-read server-side. All this request
 * carries is which app was paid from, a required screenshot of the receipt,
 * and an optional reference number (the checkout no longer asks for one, but
 * the column stays free-form in case a value ever arrives another way).
 *
 * The screenshot is validated by extension *and* by sniffed MIME type, so a
 * `.jpg` that is really a PHP script never reaches the public disk.
 */
class SubmitPaymentRequest extends FormRequest
{
    /** Hard ceiling in kilobytes — 4MB, matching the brief. */
    private const MAX_PROOF_KB = 4096;

    public function authorize(): bool
    {
        // The booking code in the URL is the credential; the controller
        // enforces that the booking is still awaiting payment.
        return true;
    }

    /**
     * `$methods` is resolved by the container — Laravel calls this method
     * through it, so the published set arrives here without a service locator.
     *
     * @return array<string, mixed>
     */
    public function rules(PaymentMethodService $methods): array
    {
        return [
            'payment_method' => $this->methodRules($methods),

            // No longer collected on the checkout — the receipt screenshot is
            // the proof now. Left nullable (not dropped) so the column still
            // accepts a value if one is ever submitted another way.
            'payment_reference' => [
                'nullable',
                'string',
                'min:6',
                'max:64',
                // Both GCash and GoTyme references are alphanumeric; we tolerate
                // the spaces and dashes people copy across from the app receipt.
                'regex:/^[A-Za-z0-9][A-Za-z0-9 \-]*[A-Za-z0-9]$/',
            ],

            // The sole proof of payment now that the reference number is gone —
            // required, not optional.
            'payment_proof' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.self::MAX_PROOF_KB,
                'dimensions:max_width=6000,max_height=6000',
            ],
        ];
    }

    /**
     * Rules for the wallet the guest says they paid from.
     *
     * Required *only when the checkout published something to choose between*.
     * The page renders one card per published method and a chooser above them;
     * with nothing published it renders neither, and shows a "contact us and we
     * will take your payment directly" panel instead. Demanding a method there
     * would reject a submission with an error naming a control that was never
     * on the page — the guest could retype the reference forever and the hold
     * would simply lapse.
     *
     * The published set is read through the same service the checkout renders
     * from, so the two can never disagree about whether the question was asked.
     *
     * `Rule::in` still guards the column in both branches: a tampered value
     * never reaches `bookings.payment_method`.
     *
     * @return list<mixed>
     */
    private function methodRules(PaymentMethodService $methods): array
    {
        $guard = ['string', Rule::in(Booking::PAYMENT_METHODS)];

        if (! $methods->hasPublished()) {
            // Null, not a guess: BookingService and every payload already
            // render an unnamed method as an em dash rather than assuming
            // GCash, which is exactly the mistake this column exists to stop.
            return array_merge(['nullable'], $guard);
        }

        return array_merge(['required'], $guard);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Please choose which app you paid with.',
            'payment_method.in' => 'Please choose which app you paid with.',
            'payment_reference.min' => 'That reference looks too short — please copy the full number from your receipt.',
            'payment_reference.max' => 'That reference looks too long — please check it against your receipt.',
            'payment_reference.regex' => 'The reference number may only contain letters, numbers, spaces and dashes.',
            'payment_proof.required' => 'Please upload a screenshot of your payment receipt.',
            'payment_proof.image' => 'Please upload a screenshot image (JPG, PNG or WEBP).',
            'payment_proof.mimes' => 'Only JPG, PNG and WEBP screenshots can be uploaded.',
            'payment_proof.mimetypes' => 'That file does not look like a real image. Please upload a screenshot.',
            'payment_proof.max' => 'That screenshot is too large. Please keep it under 4MB.',
            'payment_proof.dimensions' => 'That image is unusually large. Please upload a normal phone screenshot.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'payment_method' => 'payment method',
            'payment_reference' => 'reference number',
            'payment_proof' => 'screenshot',
        ];
    }

    protected function prepareForValidation(): void
    {
        $trimmed = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            (string) $this->input('payment_reference'),
        ));

        // Collapse a blank submission to null rather than '': the checkout no
        // longer asks for this field, and 'nullable' only skips the format
        // rules (min/max/regex) for a genuine null — an empty string would
        // still fail 'min:6'.
        $this->merge([
            'payment_reference' => $trimmed === '' ? null : $trimmed,
        ]);
    }

    /**
     * Null now that the checkout no longer collects this — kept for whatever
     * legitimately supplies one (a value entered another way).
     */
    public function reference(): ?string
    {
        $value = $this->validated('payment_reference');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The wallet the customer says they paid from — one of
     * Booking::PAYMENT_METHODS, because validation ran first.
     *
     * Null when the site publishes no payment method at all: the page could
     * not ask, so there is no answer to record. BookingService takes exactly
     * this nullable shape and leaves the column unset rather than guessing.
     *
     * Note this shadows Illuminate\Http\Request::method(), which returns the
     * HTTP verb. Nothing in this funnel asks a form request for its verb (the
     * router reads it off the incoming request, not off this object), and the
     * contract for this feature names the accessor, so the shadowing is
     * deliberate rather than accidental.
     */
    public function method(): ?string
    {
        $method = $this->validated('payment_method');

        return is_string($method) && $method !== '' ? $method : null;
    }
}
