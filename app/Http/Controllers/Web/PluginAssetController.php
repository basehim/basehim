<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

/**
 * PluginAssetController — legacy alias of {@see AppAssetController}.
 *
 * The /content/plugins/{slug}/assets/{path} route still points here, and
 * AppAssetController searches both content/apps/ and content/plugins/, so
 * every asset URL ever emitted by an app keeps resolving.
 *
 * @deprecated 1.34.0 Use AppAssetController.
 */
class PluginAssetController extends AppAssetController
{
}
