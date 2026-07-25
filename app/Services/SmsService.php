<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Outbound SMS, driver-based, Semaphore first.
 *
 * Three hard rules, in priority order:
 *
 *  1. **Never break a booking.** Every failure path — disabled, unconfigured,
 *     bad number, gateway down, timeout — returns false and writes a log line.
 *     No exception ever escapes this class.
 *  2. **Safe by default.** With `sms.enabled` false or no API key, sending is a
 *     no-op that logs the message it *would* have sent. Local and staging
 *     environments therefore cost nothing and spam nobody.
 *  3. **Philippine numbers are normalised**, so `09171234567`, `+639171234567`
 *     and `639171234567` all reach the gateway in the one format it accepts.
 */
class SmsService
{
    /**
     * Send one message. Returns true only when the gateway accepted it.
     */
    public function send(?string $to, string $message): bool
    {
        $message = trim($message);
        $number = $this->normalise($to);

        if ($number === null) {
            Log::warning('SMS skipped: unusable recipient number.', ['to' => $to]);

            return false;
        }

        if ($message === '') {
            Log::warning('SMS skipped: empty message body.', ['to' => $number]);

            return false;
        }

        if (! $this->isConfigured()) {
            // The audit-friendly no-op. Log at info so it is visible in `pail`
            // during development without polluting error reporting.
            Log::info('SMS suppressed (driver disabled or unconfigured).', [
                'to' => $number,
                'message' => $message,
            ]);

            return false;
        }

        return match ($this->driver()) {
            'log' => $this->sendViaLog($number, $message),
            'semaphore' => $this->sendViaSemaphore($number, $message),
            default => $this->unsupportedDriver($number),
        };
    }

    /**
     * Fan out one message to several numbers. Never short-circuits: a bad
     * number in the middle of the list must not silence the rest.
     *
     * @param  iterable<int, string|null>  $numbers
     * @return int  how many were accepted
     */
    public function sendMany(iterable $numbers, string $message): int
    {
        $sent = 0;

        foreach ($numbers as $number) {
            if ($this->send($number, $message)) {
                $sent++;
            }
        }

        return $sent;
    }

    /* --------------------------------------------------------------------- */
    /* Drivers                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Semaphore (semaphore.co) — the mainstream Philippine gateway.
     * Form-encoded POST, 200 + JSON array of message objects on success.
     */
    private function sendViaSemaphore(string $number, string $message): bool
    {
        $endpoint = (string) config('sms.endpoint', 'https://api.semaphore.co/api/v4/messages');

        try {
            $response = Http::asForm()
                ->timeout((int) config('sms.timeout', 10))
                ->connectTimeout((int) config('sms.connect_timeout', 5))
                // One retry only: SMS is fire-and-forget and the caller is
                // usually inside a web request the customer is waiting on.
                ->retry(1, 250, throw: false)
                ->post($endpoint, array_filter([
                    'apikey' => $this->apiKey(),
                    'number' => $number,
                    'message' => $message,
                    'sendername' => $this->senderName(),
                ], static fn (mixed $v): bool => $v !== null && $v !== ''));

            if ($response->successful()) {
                Log::info('SMS sent.', ['to' => $number, 'driver' => 'semaphore']);

                return true;
            }

            Log::error('SMS gateway rejected the message.', [
                'to' => $number,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return false;
        } catch (Throwable $e) {
            // Timeouts, DNS failures, TLS problems. Swallow — an unreachable
            // gateway must never roll back a confirmed booking.
            Log::error('SMS gateway unreachable.', [
                'to' => $number,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Development driver: pretend to send, record what was sent.
     */
    private function sendViaLog(string $number, string $message): bool
    {
        Log::info('SMS (log driver).', ['to' => $number, 'message' => $message]);

        return true;
    }

    private function unsupportedDriver(string $number): bool
    {
        Log::error('SMS not sent: unsupported driver configured.', [
            'to' => $number,
            'driver' => $this->driver(),
        ]);

        return false;
    }

    /* --------------------------------------------------------------------- */
    /* Configuration                                                          */
    /* --------------------------------------------------------------------- */

    /**
     * Is sending switched on *and* usable? Both are required — an enabled
     * driver with a blank key is a misconfiguration, not a reason to throw.
     */
    public function isConfigured(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        // The log driver needs no credentials.
        return $this->driver() === 'log' || $this->apiKey() !== '';
    }

    public function isEnabled(): bool
    {
        return (bool) config('sms.enabled', false);
    }

    private function driver(): string
    {
        return (string) config('sms.driver', 'semaphore');
    }

    private function apiKey(): string
    {
        return trim((string) config('sms.api_key', ''));
    }

    private function senderName(): ?string
    {
        $name = trim((string) config('sms.sender_name', ''));

        return $name === '' ? null : $name;
    }

    /* --------------------------------------------------------------------- */
    /* Number handling                                                        */
    /* --------------------------------------------------------------------- */

    /**
     * Normalise a Philippine mobile number to the gateway format `639XXXXXXXXX`.
     *
     *   0917 123 4567  -> 639171234567
     *   +63 917 1234567 -> 639171234567
     *   639171234567    -> 639171234567
     *   9171234567      -> 639171234567
     *
     * Anything that is not a plausible PH mobile number returns null so the
     * caller can log and move on rather than paying for a doomed send.
     */
    public function normalise(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?? '';

        if ($digits === '') {
            return null;
        }

        // Strip a leading international access code ("0063...").
        if (str_starts_with($digits, '0063')) {
            $digits = substr($digits, 2);
        }

        return match (true) {
            // 09XXXXXXXXX (11 digits) — the way Filipinos write it.
            strlen($digits) === 11 && str_starts_with($digits, '09') => '63'.substr($digits, 1),
            // 639XXXXXXXXX (12 digits) — already correct.
            strlen($digits) === 12 && str_starts_with($digits, '639') => $digits,
            // 9XXXXXXXXX (10 digits) — leading zero dropped by a form.
            strlen($digits) === 10 && str_starts_with($digits, '9') => '63'.$digits,
            default => null,
        };
    }
}
