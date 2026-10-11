<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

class SeoService
{
    public function __construct(private Database $db) {}

    public function forPost(int $postId): ?array
    {
        return $this->db->selectOne('SELECT * FROM {seo_meta} WHERE post_id = :pid', ['pid' => $postId]);
    }

    public function savePostMeta(int $postId, array $data): void
    {
        $existing = $this->forPost($postId);
        /*
         * Fields that were not sent keep what they had. Before 1.2.47 every
         * save wrote all eight, so saving in the editor (which has no Open
         * Graph fields) erased an og:title, og:description or social image
         * set any other way.
         */
        $keep = fn(string $k, mixed $default = null) => array_key_exists($k, $data) ? $data[$k] : ($existing[$k] ?? $default);
        $payload = [
            'meta_title' => $keep('meta_title'),
            'meta_description' => $keep('meta_description'),
            'og_title' => $keep('og_title'),
            'og_description' => $keep('og_description'),
            'og_image_id' => !empty($keep('og_image_id')) ? (int) $keep('og_image_id') : null,
            'canonical_url' => $keep('canonical_url'),
            'robots' => $keep('robots') ?: 'index,follow',
            'focus_keyword' => $keep('focus_keyword'),
        ];

        if (!empty($data['schema_markup'])) {
            $payload['schema_markup'] = is_string($data['schema_markup'])
                ? $data['schema_markup']
                : json_encode($data['schema_markup']);
        }

        if ($existing) {
            $this->db->update('seo_meta', $payload, ['post_id' => $postId]);
        } else {
            $payload['post_id'] = $postId;
            $this->db->insert('seo_meta', $payload);
        }
    }

    public function deletePostMeta(int $postId): void
    {
        $this->db->delete('seo_meta', ['post_id' => $postId]);
    }
}
