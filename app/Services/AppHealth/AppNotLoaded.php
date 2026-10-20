<?php
declare(strict_types=1);

namespace App\Services\AppHealth;

/**
 * An app's lifecycle method could not run because its code was not loaded:
 * it is switched off, failed validation, or its entry class is missing
 * (1.2.73). Distinct from an exception the app threw, so callers can refuse
 * to treat "nothing ran" as "it worked" without overwriting the report that
 * already explains why the app was not loaded.
 */
final class AppNotLoaded extends \RuntimeException
{
}
