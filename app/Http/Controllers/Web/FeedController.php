<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Helpers;
use App\Core\HookRegistry;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\FeedBuilder;
use App\Services\PostService;
use App\Services\SettingService;

/**
 * /feed: the site's RSS 2.0 feed (rebuilt in 1.2.88, see FeedBuilder).
 *
 * Each post's content is rendered exactly as its page renders it (the
 * post.content filter turns block JSON and Markdown into HTML and lets apps
 * add to it), then FeedBuilder makes every URL absolute and adds the item's
 * image as media:content, an enclosure and a lead <img>.
 */
class FeedController extends Controller
{
    public function rss(Request $request): Response
    {
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        /** @var HookRegistry $hooks */
        $hooks = $this->app->make(HookRegistry::class);

        $limit = max(1, min(100, (int) $settings->get('reading', 'feed_items', 10)));
        $feed = $posts->feed(1, $limit);

        $origin = FeedBuilder::requestOrigin();
        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $builder = new FeedBuilder($origin, fn(string $url): int => $this->uploadSize($url, $origin . $base));

        $items = [];
        $newest = null;
        foreach ($feed['data'] as $post) {
            $html = (string) ($post['content'] ?? '');
            try {
                $html = (string) $hooks->applyFilters('post.content', $html, $post);
            } catch (\Throwable) {
                // applyFilters already isolates listeners; this guards the call itself.
            }
            $categories = [];
            try {
                foreach ($posts->terms((int) $post['id']) as $t) {
                    if (in_array($t['taxonomy_slug'] ?? '', ['category', 'tag'], true) && ($t['name'] ?? '') !== '') {
                        $categories[] = (string) $t['name'];
                    }
                }
            } catch (\Throwable) {}

            $items[] = $builder->item($post, $html, $origin . $base . Helpers::postUrl($post), $categories);
            $at = (string) ($post['published_at'] ?? '');
            if ($at !== '' && ($newest === null || $at > $newest)) $newest = $at;
        }

        $xml = $builder->document([
            'title'       => (string) $settings->get('general', 'site_title', 'Basehim'),
            'link'        => $origin . $base . '/',
            'description' => (string) $settings->get('general', 'tagline', ''),
            'language'    => (string) $settings->get('general', 'language', 'en-US'),
            'self'        => $origin . $base . '/feed',
            'updated'     => $newest,
        ], $items);

        return Response::make($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    /**
     * Bytes of an uploaded image given its absolute URL, for <enclosure length>.
     * Only files inside storage/uploads are looked at; anything else is 0
     * ("unknown"), which feed readers accept.
     */
    private function uploadSize(string $url, string $siteRoot): int
    {
        $prefix = $siteRoot . '/uploads/';
        if (!str_starts_with($url, $prefix)) return 0;
        $rel = rawurldecode((string) parse_url(substr($url, strlen($prefix)), PHP_URL_PATH));
        if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '..') || str_contains($rel, '\\')) return 0;
        $root = realpath(BASEHIM_ROOT . '/storage/uploads');
        $abs = realpath(BASEHIM_ROOT . '/storage/uploads/' . $rel);
        if ($root === false || $abs === false || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR) || !is_file($abs)) return 0;
        return (int) (@filesize($abs) ?: 0);
    }
}
