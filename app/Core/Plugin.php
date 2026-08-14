<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plugin — legacy alias of {@see App}.
 *
 * As of Basehim 1.34.0 "plugins" are called "apps". The entire implementation
 * moved to App\Core\App; this class remains so that every plugin already in
 * the wild — anything doing `class Plugin extends \App\Core\Plugin` — keeps
 * working with no change at all.
 *
 * There is nothing to migrate. Existing plugins are apps; they simply extend
 * the class under its old name. New apps should extend App\Core\App directly.
 *
 * Because Plugin extends App, `is_subclass_of($class, App::class)` is true for
 * both old and new code, which is what the loader checks.
 *
 * @deprecated 1.34.0 Extend App\Core\App instead. This alias is not scheduled
 *             for removal — existing plugins will keep booting indefinitely.
 */
abstract class Plugin extends App
{
}
