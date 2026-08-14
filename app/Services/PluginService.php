<?php
declare(strict_types=1);

namespace App\Services;

/**
 * PluginService — legacy alias of {@see AppService}.
 *
 * Basehim 1.34.0 renamed plugins to apps. The implementation now lives in
 * AppService; this subclass exists so that anything still resolving or
 * type-hinting PluginService — third-party app code, an old controller, the
 * `plugins` container alias — continues to work unchanged.
 *
 * Both names resolve to the *same* singleton instance (see
 * Application::registerServices), so there is one app registry, one instance
 * cache, and no risk of two services disagreeing about what is active.
 *
 * @deprecated 1.34.0 Use App\Services\AppService. Not scheduled for removal.
 */
class PluginService extends AppService
{
}
