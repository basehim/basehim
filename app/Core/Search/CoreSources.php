<?php

declare(strict_types=1);

namespace App\Core\Search;

use App\Core\Application;
use App\Core\Database;

/**
 * Core's search sources (BH Search, 1.2.92):
 *
 *   content   posts, pages and app content types (posts table); public when published
 *   media     the media library
 *   comments  comments in every status
 *   users     accounts (name, username, email)
 *
 * Each is written to the index by the repository or service that saves it
 * (PostRepository, MediaRepository, CommentService, UserRepository).
 */
final class CoreSources
{
    public static function register(SearchManager $m, Application $app): void
    {
        $db = $app->make(Database::class);

        $m->source('content', [
            'label' => 'Posts and pages',
            'public' => true,
            'batch' => 200,
            'version' => 1,
            'table' => 'posts',
            'columns' => ['title', 'content', 'excerpt'],
            'documents' => fn(int $after, int $limit) => self::contentDocs($db, $app,
                $db->select('SELECT * FROM {posts} WHERE id > :a ORDER BY id ASC LIMIT ' . max(1, $limit), ['a' => $after])),
            'document' => fn(int $id) => self::contentDocs($db, $app,
                $db->select('SELECT * FROM {posts} WHERE id = :id', ['id' => $id]))[0] ?? null,
            'count' => fn() => (int) ($db->selectOne('SELECT COUNT(*) AS c FROM {posts}')['c'] ?? 0),
            'hydrate' => fn(array $ids, array $opts) => $app->make(\App\Repositories\PostRepository::class)
                ->findForSearch($ids, !empty($opts['public'])),
        ]);

        $m->source('media', [
            'label' => 'Media',
            'batch' => 1000,
            'table' => 'media',
            'columns' => ['title', 'original_name', 'file_name', 'alt_text', 'caption', 'description'],
            'documents' => fn(int $after, int $limit) => array_map([self::class, 'mediaDoc'],
                $db->select('SELECT * FROM {media} WHERE id > :a ORDER BY id ASC LIMIT ' . max(1, $limit), ['a' => $after])),
            'document' => function (int $id) use ($db) {
                $r = $db->selectOne('SELECT * FROM {media} WHERE id = :id', ['id' => $id]);
                return $r ? self::mediaDoc($r) : null;
            },
            'count' => fn() => (int) ($db->selectOne('SELECT COUNT(*) AS c FROM {media}')['c'] ?? 0),
            'permission' => 'media.read',
        ]);

        $m->source('comments', [
            'label' => 'Comments',
            'batch' => 1000,
            'table' => 'comments',
            'columns' => ['content', 'author_name', 'author_email'],
            'documents' => fn(int $after, int $limit) => array_map([self::class, 'commentDoc'],
                $db->select('SELECT * FROM {comments} WHERE id > :a ORDER BY id ASC LIMIT ' . max(1, $limit), ['a' => $after])),
            'document' => function (int $id) use ($db) {
                $r = $db->selectOne('SELECT * FROM {comments} WHERE id = :id', ['id' => $id]);
                return $r ? self::commentDoc($r) : null;
            },
            'count' => fn() => (int) ($db->selectOne('SELECT COUNT(*) AS c FROM {comments}')['c'] ?? 0),
            'permission' => 'comments.read',
        ]);

        $m->source('users', [
            'label' => 'Users',
            'batch' => 1000,
            'table' => 'users',
            'columns' => ['username', 'email', 'display_name'],
            'documents' => fn(int $after, int $limit) => array_map([self::class, 'userDoc'],
                $db->select('SELECT id, username, email, display_name, bio, role, status, public_slug, created_at, deleted_at FROM {users} WHERE id > :a ORDER BY id ASC LIMIT ' . max(1, $limit), ['a' => $after])),
            'document' => function (int $id) use ($db) {
                $r = $db->selectOne('SELECT id, username, email, display_name, bio, role, status, public_slug, created_at, deleted_at FROM {users} WHERE id = :id', ['id' => $id]);
                return $r ? self::userDoc($r) : null;
            },
            'count' => fn() => (int) ($db->selectOne('SELECT COUNT(*) AS c FROM {users}')['c'] ?? 0),
            'permission' => 'users.read',
        ]);
    }

    /** Content types whose published items appear in site search: post, page, and app types not marked public => false or searchable => false. */
    public static function searchableType(Application $app, string $type): bool
    {
        if ($type === 'post' || $type === 'page') return true;
        try {
            $def = $app->make(\App\Services\PostTypeRegistry::class)->get($type);
        } catch (\Throwable) {
            return false;
        }
        return $def !== null && !empty($def['public']) && ($def['searchable'] ?? true) !== false;
    }

    /** @param list<array> $rows posts rows */
    private static function contentDocs(Database $db, Application $app, array $rows): array
    {
        if ($rows === []) return [];
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $terms = [];
        try {
            foreach ($db->select(
                'SELECT pt.post_id, t.name FROM {post_term} pt JOIN {terms} t ON t.id = pt.term_id WHERE pt.post_id IN (' . implode(',', $ids) . ')'
            ) as $t) {
                $terms[(int) $t['post_id']][] = (string) $t['name'];
            }
        } catch (\Throwable) {}

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $type = (string) ($r['type'] ?? 'post');
            $status = (string) ($r['status'] ?? '');
            $trashed = !empty($r['deleted_at']);
            $body = TextExtractor::fromContent((string) ($r['content'] ?? ''), (string) ($r['content_format'] ?? 'html'));
            $excerpt = trim(TextExtractor::html((string) ($r['excerpt'] ?? ''), 2000));
            $out[] = [
                'id' => $id,
                'title' => (string) ($r['title'] ?? ''),
                'body' => $excerpt !== '' ? $excerpt . "\n" . $body : $body,
                'keywords' => array_merge([str_replace('-', ' ', rawurldecode((string) ($r['slug'] ?? '')))], $terms[$id] ?? []),
                'type' => $type,
                'status' => $trashed ? 'trash' : $status,
                'public' => !$trashed && $status === 'published' && self::searchableType($app, $type),
                'owner_id' => (int) ($r['author_id'] ?? 0),
                'date' => $r['published_at'] ?? $r['created_at'] ?? null,
            ];
        }
        return $out;
    }

    public static function mediaDoc(array $r): array
    {
        $mime = (string) ($r['mime_type'] ?? '');
        $group = $mime === 'image/svg+xml' ? 'svg'
            : (str_starts_with($mime, 'image/') ? 'image'
            : (str_starts_with($mime, 'video/') ? 'video'
            : (str_starts_with($mime, 'audio/') ? 'audio' : 'document')));
        return [
            'id' => (int) $r['id'],
            'title' => (string) ($r['title'] ?? ''),
            'body' => trim((string) ($r['caption'] ?? '') . "\n" . (string) ($r['description'] ?? '')),
            'keywords' => [(string) ($r['original_name'] ?? ''), (string) ($r['file_name'] ?? ''), (string) ($r['alt_text'] ?? '')],
            'type' => $group,
            'status' => !empty($r['deleted_at']) ? 'trash' : '',
            'public' => false,
            'owner_id' => (int) ($r['author_id'] ?? 0),
            'date' => $r['created_at'] ?? null,
        ];
    }

    public static function commentDoc(array $r): array
    {
        $url = (string) ($r['author_url'] ?? '');
        $host = $url !== '' ? (string) (parse_url($url, PHP_URL_HOST) ?: '') : '';
        return [
            'id' => (int) $r['id'],
            'title' => (string) ($r['author_name'] ?? ''),
            'body' => TextExtractor::html((string) ($r['content'] ?? ''), 20000),
            'keywords' => [(string) ($r['author_email'] ?? ''), $host],
            'type' => 'comment',
            'status' => (string) ($r['status'] ?? ''),
            'public' => false,
            'owner_id' => (int) ($r['author_id'] ?? 0),
            'date' => $r['created_at'] ?? null,
        ];
    }

    public static function userDoc(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) (($r['display_name'] ?? '') !== '' ? $r['display_name'] : ($r['username'] ?? '')),
            'body' => TextExtractor::html((string) ($r['bio'] ?? ''), 5000),
            'keywords' => [(string) ($r['username'] ?? ''), (string) ($r['email'] ?? ''), (string) ($r['public_slug'] ?? ''),
                (string) ($r['display_name'] ?? '')],
            'type' => (string) ($r['role'] ?? ''),
            'status' => !empty($r['deleted_at']) ? 'deleted' : (string) ($r['status'] ?? ''),
            'public' => false,
            'date' => $r['created_at'] ?? null,
        ];
    }
}
