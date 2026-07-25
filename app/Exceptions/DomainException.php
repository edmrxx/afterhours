<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Base class for expected, business-rule failures.
 *
 * These are not bugs — they are the domain telling us "no". They carry a
 * message that is safe to show a customer verbatim and render themselves as a
 * flash redirect (web) or a 422 payload (JSON/API), so controllers never have
 * to wrap service calls in try/catch just to produce a friendly page.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * Machine-readable reason code, useful for the future mobile client.
     */
    protected string $reason = 'domain_error';

    /**
     * HTTP status used when this surfaces through the API.
     */
    protected int $status = 422;

    /**
     * The session key the flash message is written to.
     */
    protected string $flashKey = 'error';

    public function __construct(string $message = '', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Render the exception without leaking a stack trace to the visitor.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return new JsonResponse([
                'message' => $this->getMessage(),
                'reason' => $this->reason,
            ], $this->status);
        }

        return back()->with($this->flashKey, $this->getMessage());
    }
}
