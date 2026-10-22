<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;

/**
 * MarketplaceController — the single /admin/marketplace screen.
 *
 * Apps and themes used to live at /admin/apps/marketplace and
 * /admin/themes/marketplace. They now share one page with an Apps tab and a
 * Themes tab; which tab is open comes from ?tab=. Since 1.2.84 the page
 * talks to the AJAX gateway (apps.market_* and themes.market_*, see
 * routes/ajax.php), each action gated like the screen it stands in for.
 *
 * The Themes tab is shown only to a role that holds manage_themes: before
 * 1.2.84 it was always there, and every request it made failed for a role
 * without that capability.
 */
class MarketplaceController extends Controller
{
    /** GET /admin/marketplace?tab=apps|themes */
    public function index(Request $request): Response
    {
        $user = $this->user();
        $canThemes = $user !== null && \App\Http\Middleware\CheckCapability::userCan($user, 'manage_themes');
        $tab = ((string) $request->query('tab', 'apps')) === 'themes' && $canThemes ? 'themes' : 'apps';

        return $this->view('marketplace.index', [
            'title'       => 'Marketplace',
            'currentUser' => $user,
            'tab'         => $tab,
            'canThemes'   => $canThemes,
        ]);
    }
}
