<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Config;
use App\Core\Database;

/**
 * Profile photos.
 *
 * A user's photo is an ordinary media item, referenced by
 * `users.avatar_media_id`. This service is the one place that reads, sets and
 * removes it — the My Profile screen, the Edit User screen and the REST API all
 * come through here, so the rules are the same everywhere:
 *
 *   - images only (jpg, jpeg, png, gif, webp — never SVG, which can carry
 *     script), and only those the site's media settings allow;
 *   - at most 5 MB, or the site's upload limit if that is lower;
 *   - the file must really be an image, whatever its name says.
 *
 * Avatars are shown small, so callers get a resized copy — the 150 px
 * thumbnail for anything up to 75 px on screen, the 300 px medium size up to
 * 150 px — and the original only when nothing smaller exists.
 *
 * Replacing or removing a photo leaves the old file in the media library: it
 * may be used elsewhere, and deleting is the library's job.
 */
class AvatarService
{
    public const TYPES     = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private Database $db) {}

    /**
     * The user's photo, or null:
     * {media_id, url, thumbnail_url, medium_url, width, height}.
     */
    public function forUser(array $user): ?array
    {
        $mid = (int) ($user['avatar_media_id'] ?? 0);
        if ($mid <= 0) return null;
        $m = $this->db->selectOne(
            'SELECT id, url, sizes, width, height, mime_type FROM {media} WHERE id = :id', ['id' => $mid]
        );
        if (!$m || empty($m['url'])) return null;
        $url = self::publicUrl((string) $m['url']);
        return [
            'media_id'      => (int) $m['id'],
            'url'           => $url,
            // Square copies only (see pick()): a site's thumbnail size may be 3:2.
            'thumbnail_url' => self::pick((string) $m['url'], $m['sizes'] ?? null, 40),
            'medium_url'    => self::pick((string) $m['url'], $m['sizes'] ?? null, 150),
            'width'         => (int) ($m['width'] ?? 0),
            'height'        => (int) ($m['height'] ?? 0),
        ];
    }

    /**
     * The best image to show at $px CSS pixels (doubled for sharp screens).
     *
     * @param string|array|null $sizesJson the media row's `sizes`
     */
    public static function pick(string $url, $sizesJson, int $px = 32): string
    {
        // Only square copies: avatars are round, and a site's "thumbnail" may be
        // 3:2 (384 × 256 for post cards) — that cut the face out of the circle.
        $sizes = self::sizes($sizesJson, true);
        if ($px <= 75 && isset($sizes['thumbnail'])) return $sizes['thumbnail'];
        if ($px <= 150 && isset($sizes['medium'])) return $sizes['medium'];
        return $url !== '' ? self::publicUrl($url) : ($sizes['medium'] ?? $sizes['thumbnail'] ?? '');
    }

    /**
     * Upload a new photo for a user (a PHP $_FILES entry) and make it theirs.
     *
     * @throws \RuntimeException with a message fit to show the person
     */
    public function upload(int $userId, array $file, int $byUserId): array
    {
        $user = $this->user($userId);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(match ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That photo is larger than the server accepts.',
                UPLOAD_ERR_NO_FILE => 'Choose a photo to upload.',
                default => 'The photo did not upload completely. Please try again.',
            });
        }
        $max = $this->maxBytes();
        if ((int) ($file['size'] ?? 0) > $max) {
            throw new \RuntimeException('That photo is too large. The limit is ' . round($max / 1048576, 1) . ' MB.');
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = $this->allowedTypes();
        if (!in_array($ext, $allowed, true)) {
            throw new \RuntimeException('Use a ' . strtoupper(implode(', ', array_unique(array_map(
                static fn($t) => $t === 'jpeg' ? 'jpg' : $t, $allowed)))) . ' image.');
        }
        $info = @getimagesize((string) ($file['tmp_name'] ?? ''));
        if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            throw new \RuntimeException('That file is not an image.');
        }

        // 1:1 whatever arrives — avatars are shown in circles.
        $original = $file;
        $file = $this->square($file);

        $name = (string) (($user['display_name'] ?? '') !== '' ? $user['display_name'] : $user['username']);
        /** @var MediaService $media */
        $media = Application::getInstance()->make(MediaService::class);
        $meta = ['title' => 'Profile photo — ' . $name, 'alt_text' => $name];
        $author = $byUserId > 0 ? $byUserId : $userId;
        if (!empty($file['__bh_square']) && method_exists($media, 'importFile')) {
            // The square copy is a file this code made, not one PHP received:
            // importFile() takes those; upload() would refuse it.
            $row = $media->importFile((string) $file['tmp_name'], (string) $file['name'], $author, $allowed, $max, $meta);
        } else {
            if (!empty($file['__bh_square'])) { @unlink((string) $file['tmp_name']); $file = $original; }
            $row = $media->upload($file, $author, $allowed, $max, $meta);
        }
        if (!empty($file['__bh_square'])) @unlink((string) $file['tmp_name']);
        $mediaId = (int) ($row['id'] ?? 0);
        if ($mediaId <= 0) throw new \RuntimeException('The photo could not be saved.');

        return $this->setMedia($userId, $mediaId);
    }

    /**
     * Use an image already in the media library.
     *
     * @throws \RuntimeException
     */
    public function setMedia(int $userId, int $mediaId): array
    {
        $this->user($userId);
        $m = $this->db->selectOne('SELECT id, mime_type FROM {media} WHERE id = :id', ['id' => $mediaId]);
        if (!$m) throw new \RuntimeException('That media item does not exist.');
        $mime = (string) ($m['mime_type'] ?? '');
        if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            throw new \RuntimeException('A profile photo has to be a JPG, PNG, GIF or WebP image.');
        }
        $this->users()->update($userId, ['avatar_media_id' => $mediaId]);
        $this->changed($userId);
        return $this->forUser($this->user($userId)) ?? [];
    }

    /** Remove the photo (the file stays in the media library). */
    public function remove(int $userId): void
    {
        $this->user($userId);
        $this->db->execute('UPDATE {users} SET avatar_media_id = NULL WHERE id = :id', ['id' => $userId]);
        $this->changed($userId);
    }

    /**
     * id => thumbnail URL for each of these users who has a photo — one query,
     * for lists (the Users screen) that would otherwise ask once per row.
     *
     * @param array<int|string> $userIds
     * @return array<int,string>
     */
    public function thumbnails(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$ids) return [];
        $rows = $this->db->select(
            'SELECT u.id, m.url, m.sizes FROM {users} u JOIN {media} m ON m.id = u.avatar_media_id
              WHERE u.id IN (' . implode(',', $ids) . ')'
        );
        $out = [];
        foreach ($rows as $r) {
            if (!empty($r['url'])) $out[(int) $r['id']] = self::pick((string) $r['url'], $r['sizes'] ?? null, 40);
        }
        return $out;
    }

    /** What the upload control and the API say about the rules. */
    public function rules(): array
    {
        return ['types' => array_values(array_unique(array_map(static fn($t) => $t === 'jpeg' ? 'jpg' : $t, $this->allowedTypes()))),
                'max_bytes' => $this->maxBytes()];
    }

    // ------------------------------------------------------------------

    private function allowedTypes(): array
    {
        try {
            $media = Application::getInstance()->make(MediaService::class);
            $site = $media->allowedTypes((array) Application::getInstance()->make(Config::class)->get('cms.media.allowed_types', []));
            $list = array_values(array_intersect(self::TYPES, array_map('strtolower', $site)));
            return $list ?: self::TYPES;
        } catch (\Throwable) {
            return self::TYPES;
        }
    }

    private function maxBytes(): int
    {
        try {
            $media = Application::getInstance()->make(MediaService::class);
            $site = $media->maxUploadBytes((int) Application::getInstance()->make(Config::class)->get('cms.media.max_upload_size', self::MAX_BYTES));
            return max(1, min(self::MAX_BYTES, $site));
        } catch (\Throwable) {
            return self::MAX_BYTES;
        }
    }

    private function user(int $id): array
    {
        $u = $this->db->selectOne('SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        if (!$u) throw new \RuntimeException('That user does not exist.');
        return $u;
    }

    private function users(): UserService
    {
        return Application::getInstance()->make(UserService::class);
    }

    private function changed(int $userId): void
    {
        try {
            Application::getInstance()->make(\App\Core\HookRegistry::class)->doAction('user.avatar_changed', $userId);
        } catch (\Throwable) {}
        try {
            // Author archives and post pages show the photo.
            Application::getInstance()->make(CacheService::class)->flush();
        } catch (\Throwable) {}
    }

    /**
     * @param bool $squareOnly keep only sizes whose width and height are equal
     *                         (within a pixel); sizes without dimensions are dropped
     * @return array<string,string> size name => public URL
     */
    private static function sizes($raw, bool $squareOnly = false): array
    {
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $name => $v) {
            if (!is_array($v) || empty($v['url'])) continue;
            if ($squareOnly) {
                $w = (int) ($v['width'] ?? 0); $h = (int) ($v['height'] ?? 0);
                if ($w <= 0 || $h <= 0 || abs($w - $h) > 1) continue;
            }
            $out[(string) $name] = self::publicUrl((string) $v['url']);
        }
        return $out;
    }

    /**
     * Make an uploaded photo square: centre-crop to 1:1, at most 512 × 512,
     * turned upright from the camera's EXIF orientation. The photo editor
     * already sends a 512 × 512 crop; this covers everything else — the API,
     * and the editor saving a photo as it is when the cropping tool cannot load.
     * Returns the $_FILES entry, pointing at the square copy (or unchanged when
     * GD cannot read the image, or it is an animated GIF).
     */
    private function square(array $file): array
    {
        $path = (string) ($file['tmp_name'] ?? '');
        $info = @getimagesize($path);
        if (!$info || !function_exists('imagecreatetruecolor')) return $file;
        [$w, $h, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        if ($type === IMAGETYPE_GIF && preg_match_all('/\x00\x21\xF9\x04/', (string) @file_get_contents($path)) > 1) return $file;   // animated
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default        => false,
        };
        if (!$src) return $file;

        // Upright, as the camera meant it.
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
            $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($rot !== 0 && ($r = imagerotate($src, $rot, 0))) { imagedestroy($src); $src = $r; [$w, $h] = [imagesx($src), imagesy($src)]; }
        } elseif ($w === $h && $w <= 512) {
            imagedestroy($src);
            return $file;   // already square and small: nothing to do
        }

        $side = min($w, $h);
        $out  = min(512, $side);
        $dst  = imagecreatetruecolor($out, $out);
        if ($type !== IMAGETYPE_JPEG) { imagealphablending($dst, false); imagesavealpha($dst, true); }
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $out, $out, $side, $side);
        imagedestroy($src);

        $tmp = tempnam(sys_get_temp_dir(), 'bhav');
        $ext = match ($type) { IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'png', IMAGETYPE_WEBP => 'webp', default => 'jpg' };
        $ok = match ($ext) {
            'png'  => imagepng($dst, $tmp, 6),
            'webp' => function_exists('imagewebp') ? imagewebp($dst, $tmp, 88) : false,
            default => imagejpeg($dst, $tmp, 90),
        };
        imagedestroy($dst);
        if (!$ok) { @unlink($tmp); return $file; }
        $base = pathinfo((string) ($file['name'] ?? 'avatar'), PATHINFO_FILENAME) ?: 'avatar';
        return ['name' => $base . '.' . $ext, 'type' => image_type_to_mime_type(match ($ext) { 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP, default => IMAGETYPE_JPEG }),
                'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($tmp), '__bh_square' => true];
    }

    private static function publicUrl(string $url): string
    {
        return class_exists(\App\Repositories\PostRepository::class)
            ? \App\Repositories\PostRepository::mediaUrl($url)
            : $url;
    }
}
