<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Shared plumbing for every booking email.
 *
 * Subclasses only declare their subject, their views and any extra payload —
 * the branding block, the booking summary and the queue behaviour live here so
 * the three templates can never drift apart.
 */
abstract class BookingMailable extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    /**
     * The flattened booking read-model shared by all templates.
     *
     * @return array<string, mixed>
     */
    protected function summary(): array
    {
        return app(BookingService::class)->summary($this->booking);
    }

    /**
     * Company identity pulled from the settings table, with config fallbacks so
     * the first email ever sent still looks intentional.
     *
     * @return array<string, string|null>
     */
    protected function company(): array
    {
        try {
            $settings = app(SettingsService::class);
            $uploaded = $settings->get('company', 'company_logo_path');

            return [
                'name' => (string) ($settings->get('company', 'name') ?: config('app.name', 'PHEA')),
                'email' => $settings->get('company', 'email') ?: config('mail.from.address'),
                'phone' => $settings->get('company', 'phone'),
                'address' => $settings->get('company', 'address'),
                'url' => rtrim((string) config('app.url', ''), '/'),
                ...$this->resolveLogo(is_string($uploaded) ? $uploaded : null),
            ];
        } catch (Throwable) {
            return [
                'name' => (string) config('app.name', 'PHEA'),
                'email' => config('mail.from.address'),
                'phone' => null,
                'address' => null,
                'url' => rtrim((string) config('app.url', ''), '/'),
                ...$this->resolveLogo(null),
            ];
        }
    }

    /**
     * Resolve the logo the email should carry, as an inline-embeddable local
     * file path plus a URL fallback.
     *
     * The logo is embedded into the message body (CID), never linked by URL:
     * this app's APP_URL is routinely a local/private address (e.g. a XAMPP
     * `127.0.0.1:8008`) that a customer's inbox cannot reach, so a linked image
     * renders broken for the very recipient it was sent to. An embedded image
     * travels with the mail and always displays.
     *
     * An admin-uploaded logo (Admin > Settings > Company) is drawn for a light
     * background, unlike the shipped navy-header wordmark, so the layout wraps
     * it in a light plate — `logo_is_uploaded` tells it which treatment to use.
     *
     * @return array{logo_path: string, logo_url: string, logo_is_uploaded: bool}
     */
    private function resolveLogo(?string $uploadedPath): array
    {
        if ($uploadedPath !== null && $uploadedPath !== '') {
            $disk = Storage::disk('public');

            if ($disk->exists($uploadedPath)) {
                return [
                    'logo_path' => $disk->path($uploadedPath),
                    'logo_url' => $disk->url($uploadedPath),
                    'logo_is_uploaded' => true,
                ];
            }
        }

        return [
            'logo_path' => public_path('images/brand/logo-mark.png'),
            'logo_url' => url('/images/brand/logo-mark.png'),
            'logo_is_uploaded' => false,
        ];
    }

    /**
     * Everything the Blade views expect, in one place.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'booking' => $this->booking,
            'summary' => $this->summary(),
            'company' => $this->company(),
        ];
    }
}
