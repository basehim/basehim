<?php

declare(strict_types=1);

namespace Basehim\PublicRoutesExample;

use App\Core\Http\SitePage;
use App\Core\Request;
use App\Core\Response;

/**
 * Public Routes Example — public pages and a small JSON API (Basehim 1.2.72).
 *
 *   GET  /example          a page in the active theme, listing the items
 *   GET  /example/{slug}   one item, or the theme's 404
 *   GET  /api/example      the items as JSON
 *   POST /api/example      add a note: validated, rate limited, CSRF-checked
 *                          for browser sessions (see docs/PUBLIC-ROUTES.md)
 *
 * The items are fixed and the notes live in this app's settings, so the
 * example needs no database table.
 */
class App extends \App\Core\App
{
    private const ITEMS = [
        'blue-mug'    => ['name' => 'Blue mug', 'price' => 12.5, 'summary' => 'Stoneware, 350 ml, dishwasher safe.'],
        'green-plate' => ['name' => 'Green plate', 'price' => 18.0, 'summary' => 'Hand glazed, 26 cm.'],
        'oak-board'   => ['name' => 'Oak board', 'price' => 32.0, 'summary' => 'Solid oak serving board.'],
    ];

    public function boot(): void
    {
        $site = $this->router()->site();

        $site->get('/example', [$this, 'index'], ['name' => 'example.index']);
        $site->get('/example/{slug}', [$this, 'show'], [
            'name' => 'example.item',
            'where' => ['slug' => '[a-z0-9-]+'],
        ]);

        $site->group(['prefix' => '/api/example'], function ($api) {
            $api->get('', [$this, 'list']);
            // A public form or script may post here. A browser that has this
            // site's session cookie must send the CSRF token (csrf defaults to
            // true); ten notes a minute per visitor.
            $api->post('', [$this, 'addNote'], ['rate' => [10, 60]]);
        });
    }

    /** GET /example */
    public function index(Request $request): Response
    {
        return SitePage::view($this, 'index', [
            'items' => self::ITEMS,
            'notes' => array_slice((array) $this->getSetting('notes', []), -5),
            'csrf'  => $this->session()->csrfToken(),
            'itemUrl' => fn(string $slug) => (string) $this->router()->url('example.item', ['slug' => $slug]),
        ], [
            'title' => 'Example shop',
            'description' => 'A page served by the Public Routes Example app.',
        ]);
    }

    /** GET /example/{slug} */
    public function show(Request $request, string $slug): Response
    {
        $item = self::ITEMS[$slug] ?? null;
        if ($item === null) {
            return SitePage::notFound('There is no item called "' . $slug . '".');
        }
        return SitePage::view($this, 'item', ['item' => $item, 'slug' => $slug], [
            'title' => $item['name'],
            'description' => $item['summary'],
        ]);
    }

    /** GET /api/example */
    public function list(Request $request): Response
    {
        $out = [];
        foreach (self::ITEMS as $slug => $item) {
            $out[] = ['slug' => $slug] + $item + ['url' => $this->router()->url('example.item', ['slug' => $slug])];
        }
        return Response::json(['items' => $out]);
    }

    /** POST /api/example — JSON {"name": "...", "note": "..."} or a form. */
    public function addNote(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        $note = trim((string) $request->input('note', ''));

        $errors = [];
        if ($name === '' || mb_strlen($name) > 60) $errors['name'] = 'Give a name of up to 60 characters.';
        if ($note === '' || mb_strlen($note) > 500) $errors['note'] = 'Write a note of up to 500 characters.';
        if ($errors) {
            return Response::json(['ok' => false, 'errors' => $errors], 422);
        }

        $notes = (array) $this->getSetting('notes', []);
        $notes[] = ['name' => $name, 'note' => $note, 'at' => gmdate('c')];
        $this->setSetting('notes', array_slice($notes, -50));

        if (!$request->wantsJson() && !$request->isJson()) {
            return Response::redirect((string) $this->router()->url('example.index') . '?thanks=1', 303);
        }
        return Response::json(['ok' => true, 'note' => ['name' => $name, 'note' => $note]], 201);
    }
}
