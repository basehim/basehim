<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\AuthorService;
use App\Services\PostService;
use App\Services\SettingService;

class AuthorController extends Controller
{
    use RendersTheme;

    /**
     * /author/{slug} — the posts one author has published.
     *
     * {slug} is the author's public slug (AuthorService), not their username:
     * a username is half of a login and must not appear in a URL. Old links
     * by username are deliberately not redirected, since the redirect would
     * reveal which login belongs to which author.
     *
     * A 404 when author archives are switched off, and for anyone without a
     * published post, so these pages cannot be used to list a site's accounts.
     */
    public function show(Request $request, string $username): Response
    {
        /** @var AuthorService $authors */
        $authors = $this->app->make(AuthorService::class);
        if (!$authors->archivesEnabled()) return $this->notFound('Page not found');

        $user = $authors->findBySlug($username);
        if (!$user) return $this->notFound('Author not found');

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);

        $perPage = max(1, (int) $settings->get('reading', 'posts_per_page', 10));
        $page = max(1, (int) $request->query('page', 1));
        $result = $posts->paginate([
            'type' => 'post',
            'status' => 'published',
            'author_id' => (int) $user['id'],
        ], $page, $perPage);

        // Only what a visitor may see. This used to pass the whole user row —
        // email and password hash included — to the theme.
        $author = $authors->publicProfile($user);
        $name = $author['display_name'];
        $bio = trim(preg_replace('/\s+/', ' ', $author['bio']) ?? '');

        return $this->renderTheme('archive', [
            'archive_type' => 'author',
            'author' => $author,
            'posts' => $result['data'],
            'meta' => $result['meta'],
            'title' => 'Posts by ' . $name,
            'seo' => [
                'title' => 'Posts by ' . $name,
                'description' => $bio !== '' ? mb_substr($bio, 0, 160) : 'Posts by ' . $name . '.',
                'canonical' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                    . rtrim((defined('BASEHIM_BASE') ? BASEHIM_BASE : ''), '/')
                    . $author['url'] . ($page > 1 ? '?page=' . $page : ''),
            ],
        ]);
    }
}
