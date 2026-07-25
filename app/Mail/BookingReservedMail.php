<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "We're holding your court" — sent the instant a slot (or set of slots) is
 * reserved, before any payment has been submitted. The one job this email has
 * that the others don't is urgency: make the hold deadline unmissable.
 */
class BookingReservedMail extends BookingMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Complete your payment — %s', (string) $this->booking->code),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.reserved',
            text: 'emails.booking.text.reserved',
            with: [
                ...$this->payload(),
                'paymentUrl' => $this->paymentUrl(),
                'holdMinutes' => (int) (app(SettingsService::class)->get(Setting::GROUP_SYSTEM, 'booking_hold_minutes') ?: config('booking.hold_minutes', 30)),
            ],
        );
    }

    private function paymentUrl(): ?string
    {
        try {
            return route('public.booking.payment', $this->booking->code);
        } catch (\Throwable) {
            return null;
        }
    }
}
