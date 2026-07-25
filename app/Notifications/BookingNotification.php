<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared behaviour for the three booking notifications.
 *
 * Queueing notes
 * --------------
 * These implement `ShouldQueue` so a slow SMTP handshake never sits inside the
 * transaction-adjacent request path. Two safeguards keep that honest on a
 * `database` queue with nobody running `queue:work`:
 *
 *  - `viaConnection()` honours `booking.notification_connection`, so an
 *    operator can set it to `sync` and get inline delivery with no worker.
 *  - `failed()` logs instead of bubbling, so a dead mailer leaves a breadcrumb
 *    rather than an unhandled job exception.
 *
 * Delivery is decided per-recipient: mail only when we actually have an
 * address, SMS only when the gateway is switched on and we have a number.
 * A customer who gave no email still gets their text.
 */
abstract class BookingNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Give a flaky mailer a few chances before giving up. */
    public int $tries = 3;

    /** Seconds between attempts — back off rather than hammer. */
    public array $backoff = [10, 60, 300];

    public function __construct(public Booking $booking) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if ($this->wantsMail($notifiable)) {
            $channels[] = 'mail';
        }

        if ($this->wantsSms($notifiable)) {
            $channels[] = 'sms';
        }

        return $channels;
    }

    /**
     * Let operations force inline delivery without a queue worker.
     *
     * Must be plural — Illuminate\Notifications\NotificationSender only ever
     * calls `viaConnections()` (per channel), never a singular `viaConnection()`.
     * The singular name silently does nothing: Laravel falls back to the
     * default queue connection and every notification sits unprocessed until
     * something runs `queue:work`.
     */
    public function viaConnections(): array
    {
        $connection = (string) (config('booking.notification_connection') ?? config('queue.default', 'sync'));

        return [
            'mail' => $connection,
            'sms' => $connection,
        ];
    }

    public function viaQueues(): array
    {
        return ['mail' => (string) config('booking.notification_queue', 'default')];
    }

    /**
     * A permanently failed notification is a support issue, not a crash.
     */
    public function failed(?Throwable $e = null): void
    {
        Log::error('Booking notification failed.', [
            'notification' => static::class,
            'booking' => $this->booking->code ?? $this->booking->getKey(),
            'error' => $e?->getMessage(),
        ]);
    }

    /**
     * The flattened booking read-model, shared with the mail templates.
     *
     * @return array<string, mixed>
     */
    protected function summary(): array
    {
        return app(BookingService::class)->summary($this->booking);
    }

    protected function recipientEmail(object $notifiable): ?string
    {
        try {
            $address = $notifiable->routeNotificationFor('mail', $this);
        } catch (Throwable) {
            return null;
        }

        // routeNotificationFor('mail') may hand back ['a@b.com' => 'Name'].
        if (is_array($address)) {
            $address = array_key_first($address) ?: null;
        }

        return is_string($address) && trim($address) !== '' ? trim($address) : null;
    }

    protected function wantsMail(object $notifiable): bool
    {
        return $this->recipientEmail($notifiable) !== null;
    }

    protected function wantsSms(object $notifiable): bool
    {
        if (! app(SmsService::class)->isConfigured()) {
            return false;
        }

        try {
            $number = $notifiable->routeNotificationFor('sms', $this);
        } catch (Throwable) {
            return false;
        }

        return is_string($number) && trim($number) !== '';
    }
}
