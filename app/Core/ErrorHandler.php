<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\StatusPage;

final class ErrorHandler
{
    public static function handle(\Throwable $e): void
    {
        // Error pages are answered here, not through the router: give them
        // the same plain headers (1.2.90), without PHP's version.
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header('X-Powered-By: Basehim');
            header('X-Content-Type-Options: nosniff');
            header('X-XSS-Protection: 0');
        }
        // Always log it
        try {
            $logger = Application::getInstance()->make(Logger::class);
            $logger->error($e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        } catch (\Throwable) {
            // last resort
            error_log($e->getMessage());
        }

        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        if ($base !== '' && str_starts_with($uri, $base)) $uri = (string) substr($uri, strlen($base));
        $isApi = str_starts_with($uri, '/api/');
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $wantsJson = $isApi || str_contains($accept, 'application/json');
        $debug = filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);

        if ($wantsJson) {
            self::renderJson($e, $debug);
        } else {
            self::renderHtml($e, $debug);
        }
    }

    private static function renderJson(\Throwable $e, bool $debug): void
    {
        $status = self::statusFromException($e);
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        // A 5xx message is for the log, not the client: it can name tables,
        // queries, file paths or the database account. Until 1.2.51 it was
        // returned as the detail even with APP_DEBUG off. A 4xx exception is
        // raised on purpose to tell the client what to fix, so it keeps its.
        $detail = ($debug || $status < 500)
            ? ($e->getMessage() ?: 'An error occurred.')
            : 'Something went wrong on the server. The error has been logged.';
        $body = [
            'type'   => 'https://basehim.io/errors/exception',
            'title'  => self::titleFromStatus($status),
            'status' => $status,
            'detail' => $detail,
        ];
        if ($debug) {
            $body['debug'] = [
                'exception' => $e::class,
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => explode("\n", $e->getTraceAsString()),
            ];
        }
        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function renderHtml(\Throwable $e, bool $debug): void
    {
        $status = self::statusFromException($e);
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }

        if (!$debug) {
            echo self::genericHtml($status);
            return;
        }

        // Debug details, inside the same page every error uses (1.3.0).
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $details = '<div class="mt-4 space-y-3">'
            . '<p class="text-xs font-semibold uppercase tracking-wider text-danger">' . $esc($e::class) . '</p>'
            . '<p class="text-sm text-slate-700"><span class="text-slate-500">in</span> '
            . '<code class="rounded bg-slate-100 px-1.5 py-0.5 [overflow-wrap:anywhere]">' . $esc($e->getFile()) . '</code> '
            . '<span class="text-slate-500">at line</span> <span class="font-semibold">' . (int) $e->getLine() . '</span></p>'
            . '<pre class="max-h-96 overflow-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-200">' . $esc($e->getTraceAsString()) . '</pre>'
            . '</div>';
        echo StatusPage::html($status, $e->getMessage() !== '' ? $e->getMessage() : self::titleFromStatus($status), '', [
            'wide' => true, 'icon' => 'exclamation-circle', 'details' => $details,
        ]);
    }

    private static function genericHtml(int $status): string
    {
        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $server = $status >= 500;
        // Try again = this address, only as a path on this site ("//host"
        // would be another site).
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $again = (str_starts_with($uri, '/') && !str_starts_with($uri, '//') && !str_contains($uri, '\\')) ? $uri : $base . '/';
        return StatusPage::html($status, self::titleFromStatus($status),
            $server
                ? 'Something went wrong on our side and it has been logged. Please try again in a moment.'
                : 'This request could not be completed.', [
            'tone' => $server ? 'danger' : 'warning',
            'actions' => [
                ['label' => 'Try again', 'href' => $again, 'primary' => true, 'icon' => 'arrow-path'],
                ['label' => 'Home', 'href' => $base . '/', 'icon' => 'home'],
            ],
        ]);
    }

    private static function statusFromException(\Throwable $e): int
    {
        // Database errors carry string codes ("42S02"); only an int is a status.
        // Comparing a string here made this int method return it: a TypeError
        // that replaced the error page with a blank 500.
        $code = $e->getCode();
        if (is_int($code) && $code >= 400 && $code < 600) {
            return $code;
        }
        return 500;
    }

    private static function titleFromStatus(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }
}
