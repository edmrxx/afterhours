<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\SmsService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bridges Laravel's notification system to `SmsService`.
 *
 * Registered as the `sms` channel in AppServiceProvider, which lets both a
 * `User` (via `routeNotificationForSms()`) and an on-demand
 * `Notification::route('sms', $phone)` receive the same notification class.
 *
 * Like the service it wraps, it never throws: a notification that fails to
 * text must not fail the mail leg beside it.
 */
class SmsChannel
{
    public function __construct(private readonly SmsService $sms) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        try {
            if (! method_exists($notification, 'toSms')) {
                return;
            }

            $to = $notifiable->routeNotificationFor('sms', $notification);

            if (! is_string($to) || trim($to) === '') {
                return;
            }

            $message = trim((string) $notification->toSms($notifiable));

            if ($message === '') {
                return;
            }

            $this->sms->send($to, $message);
        } catch (Throwable $e) {
            Log::warning('SMS channel failed.', [
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
