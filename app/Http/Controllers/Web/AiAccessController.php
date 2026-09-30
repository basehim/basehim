<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\AiAccessService;

/**
 * Public files for AI agents and crawlers. Everything is generated from the
 * site's content and Settings → AI Agents; see AiAccessService.
 */
class AiAccessController extends Controller
{
    private function ai(): AiAccessService { return $this->app->make(AiAccessService::class); }

    private function sendText(string $body, string $type = 'text/plain'): Response
    {
        return Response::make($body, 200, [
            'Content-Type'  => $type . '; charset=utf-8',
            'Cache-Control' => 'public, max-age=900',
            'X-Robots-Tag'  => 'noindex',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function sendJson(mixed $data, int $status = 200): Response
    {
        return Response::make(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            $status,
            [
                'Content-Type'  => 'application/json; charset=utf-8',
                'Cache-Control' => 'public, max-age=900',
                'Access-Control-Allow-Origin' => '*',
            ]
        );
    }

    private function missing(): Response
    {
        return Response::make('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * GET /robots.txt — served while "Serve robots.txt" is on. Its AI-crawler
     * rules apply even with agent features switched off: blocking crawlers is
     * a protection, not an agent feature.
     */
    public function robots(Request $request): Response
    {
        $a = $this->ai();
        return $a->get('robots_enabled') === '1' ? $this->sendText($a->robotsTxt()) : $this->missing();
    }

    /** GET /llms.txt */
    public function llms(Request $request): Response
    {
        $a = $this->ai();
        return $a->on('llms_enabled') ? $this->sendText($a->llmsTxt()) : $this->missing();
    }

    /** GET /llms-full.txt */
    public function llmsFull(Request $request): Response
    {
        $a = $this->ai();
        return ($a->on('llms_enabled') && $a->on('llms_full')) ? $this->sendText($a->llmsFullTxt()) : $this->missing();
    }

    /** GET /.well-known/ard.json and /.well-known/ai-catalog.json */
    public function catalog(Request $request): Response
    {
        $a = $this->ai();
        return $a->on('catalog_enabled') ? $this->sendJson($a->catalog()) : $this->missing();
    }

    /** GET /ai/recent-articles.json?limit=&topic= — backs the list_recent_articles tool. */
    public function recent(Request $request): Response
    {
        $a = $this->ai();
        if (!$a->on('webmcp_enabled') || !$a->on('tool_recent')) return $this->missing();
        $limit = (int) $request->query('limit', 10);
        $topic = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $request->query('topic', ''))) ?? '';
        return $this->sendJson(['articles' => $a->recentArticles($limit, $topic)]);
    }
}
