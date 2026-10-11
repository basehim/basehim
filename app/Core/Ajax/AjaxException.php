<?php

declare(strict_types=1);

namespace App\Core\Ajax;

/**
 * An error a handler means the browser to see.
 *
 * The gateway shows the code and message of this exception, and only this
 * exception. Anything else a handler throws is logged with the request ID and
 * answered with a generic 500, so a PDO error, a file path or a stack trace
 * never reaches a response by accident.
 *
 *     throw new AjaxException('NOT_FOUND', 'That file no longer exists.', 404);
 *
 * The message is shown to the user as it is: write it for them, and never put
 * anything into it that came from an exception or the database.
 */
final class AjaxException extends \RuntimeException
{
    /** @param array<string,string[]> $fields per-field messages, for 422 */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $fields = [],
    ) {
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $errorCode)) {
            throw new \InvalidArgumentException("AJAX error codes are UPPER_SNAKE_CASE; got '{$errorCode}'.");
        }
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException("AJAX error status must be 4xx or 5xx; got {$status}.");
        }
        parent::__construct($message);
    }

    public static function notFound(string $message = 'The requested item was not found.'): self
    {
        return new self('NOT_FOUND', $message, 404);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self('FORBIDDEN', $message, 403);
    }

    public static function invalid(string $message, array $fields = []): self
    {
        return new self('VALIDATION_FAILED', $message, 422, $fields);
    }

    public static function conflict(string $message): self
    {
        return new self('CONFLICT', $message, 409);
    }
}
