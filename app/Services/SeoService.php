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
        $payload = [
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'og_title' => $data['og_title'] ?? null,
            'og_description' => $data['og_description'] ?? null,
            'og_image_id' => !empty($data['og_image_id']) ? (int)$data['og_image_id'] : null,
            'canonical_url' => $data['canonical_url'] ?? null,
            'robots' => $data['robots'] ?? 'index,follow',
            'focus_keyword' => $data['focus_keyword'] ?? null,
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
