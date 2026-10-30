<?php

declare(strict_types=1);

namespace App\Core\Widgets;

/**
 * A widget change that was refused (1.2.93): an unknown area or widget, a
 * widget the area does not accept, or settings that failed validation.
 * `errors` holds the messages by field key; `status` is the HTTP status the
 * admin answers with (404 not found, 422 invalid).
 */
final class WidgetError extends \InvalidArgumentException
{
    /** @param array<string,string> $errors */
    public function __construct(string $message, public readonly array $errors = [], public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
