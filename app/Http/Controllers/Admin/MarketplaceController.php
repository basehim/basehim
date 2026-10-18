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
 * Themes tab; which tab is open comes from ?tab=. The browse/facets/install
 * JSON endpoints still live under /admin/apps/marketplace and
 * /admin/themes/marketplace (each keeps its own capability gate), and the
 * page calls whichever matches the open tab.
 */
class MarketplaceController extends Controller
{
    /** GET /admin/marketplace?tab=apps|themes */
    public function index(Request $request): Response
    {
        $tab = ((string) $request->query('tab', 'apps')) === 'themes' ? 'themes' : 'apps';

        return $this->view('marketplace.index', [
            'title'       => 'Marketplace',
            'currentUser' => $this->user(),
            'tab'         => $tab,
        ]);
    }
}
