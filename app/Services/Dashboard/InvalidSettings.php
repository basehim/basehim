<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

/** Widget settings that failed their schema; $fields maps field key => messages. */
final class InvalidSettings extends \InvalidArgumentException
{
    /** @param array<string,string[]> $fields */
    public function __construct(string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
