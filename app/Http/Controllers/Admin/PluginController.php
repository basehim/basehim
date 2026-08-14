<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

/**
 * PluginController — legacy alias of {@see AppController}.
 *
 * Basehim 1.34.0 renamed plugins to apps. Every action moved to
 * AppController; this subclass exists so that a route file, a bookmark
 * handler, or third-party code still referencing
 * App\Http\Controllers\Admin\PluginController resolves and behaves
 * identically. Actions redirect to /admin/apps, the canonical location.
 *
 * @deprecated 1.34.0 Use AppController. Not scheduled for removal.
 */
class PluginController extends AppController
{
}
