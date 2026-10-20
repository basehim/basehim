<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

/**
 * Thrown by a widget's data provider when its data can't be had right now for
 * a reason that isn't a fault — a service not configured, a remote API
 * offline, nothing connected yet (1.2.68):
 *
 *     throw new WidgetUnavailable('Connect a payment provider to see sales.');
 *
 * The card shows the message in its "unavailable" state, without the error
 * styling and without logging. The message is shown to the user as written,
 * so write it for them; any other exception is logged and the card shows a
 * generic error with Retry.
 */
final class WidgetUnavailable extends \RuntimeException
{
}
