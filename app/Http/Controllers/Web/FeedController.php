<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Services\SettingService;

class FeedController extends Controller
{
    public function rss(Request $request): Response
    {
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);

        $limit = (int)$settings->get('reading', 'feed_items', 10);
        $feed = $posts->feed(1, $limit);

        $site = $settings->get('general', 'site_title', 'Basehim');
        $tagline = $settings->get('general', 'tagline', '');
        $base = $this->baseUrl();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        $xml .= "  <channel>\n";
        $xml .= "    <title>" . htmlspecialchars($site) . "</title>\n";
        $xml .= "    <link>{$base}/</link>\n";
        $xml .= "    <description>" . htmlspecialchars($tagline) . "</description>\n";
        $xml .= "    <language>" . htmlspecialchars($settings->get('general', 'language', 'en-US')) . "</language>\n";
        $xml .= "    <atom:link href=\"{$base}/feed\" rel=\"self\" type=\"application/rss+xml\" />\n";
        $xml .= "    <generator>Basehim</generator>\n";

        foreach ($feed['data'] as $post) {
            $url = $base . Helpers::postUrl($post);
            $xml .= "    <item>\n";
            $xml .= "      <title>" . htmlspecialchars($post['title']) . "</title>\n";
            $xml .= "      <link>{$url}</link>\n";
            $xml .= "      <guid isPermaLink=\"true\">{$url}</guid>\n";
            $xml .= "      <pubDate>" . date(DATE_RSS, strtotime($post['published_at'] ?? $post['created_at'])) . "</pubDate>\n";
            $xml .= "      <description><![CDATA[" . ($post['excerpt'] ?? mb_substr(strip_tags($post['content']), 0, 300)) . "]]></description>\n";
            $xml .= "      <content:encoded><![CDATA[" . $post['content'] . "]]></content:encoded>\n";
            if (!empty($post['author_name'])) {
                $xml .= "      <author>" . htmlspecialchars($post['author_name']) . "</author>\n";
            }
            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n</rss>\n";

        return Response::make($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    private function baseUrl(): string
    {
        $scheme = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        return $scheme . '://' . $host . $base;
    }
}
