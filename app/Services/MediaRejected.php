<?php

declare(strict_types=1);

namespace App\Services;

/**
 * An upload MediaService refused, with a message written for the person who
 * sent it (1.2.67). Still a RuntimeException, so existing catch blocks work;
 * the admin upload route shows only these messages and logs anything else.
 */
final class MediaRejected extends \RuntimeException
{
}
