<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Sources;

/**
 * Source
 *
 * Uniform interface for reading WordPress data, regardless of whether the
 * data lives in a WXR file or a live MySQL database.
 *
 * Each method returns a paginated batch of records suitable for one
 * "tick" of the wizard. Implementations may cache parsed data internally
 * (WXR loads the file once, MySQL queries on demand).
 *
 * Conventions:
 *   - $offset is zero-based.
 *   - $limit is the max number of records to return.
 *   - All returned rows use WordPress-style column names where reasonable
 *     (ID, post_title, post_content, post_type, post_status, ...).
 *   - count*() methods return total counts for the progress bar.
 */
interface Source
{
    public function countUsers(): int;
    public function fetchUsers(int $offset, int $limit): array;

    public function countTerms(): int;
    public function fetchTerms(int $offset, int $limit): array;

    public function countPosts(): int;
    public function fetchPosts(int $offset, int $limit): array;

    public function countAttachments(): int;
    public function fetchAttachments(int $offset, int $limit): array;

    public function countComments(): int;
    public function fetchComments(int $offset, int $limit): array;

    /** Source-of-truth site URL (used to recognize internal links/images). */
    public function siteUrl(): string;
}
