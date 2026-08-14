<?php

declare(strict_types=1);

namespace App\Core;

final class ErrorHandler
{
    public static function handle(\Throwable $e): void
    {
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

        $isApi = isset($_SERVER['REQUEST_URI']) && str_starts_with($_SERVER['REQUEST_URI'], '/api/');
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

        // `detail` used to carry $e->getMessage() unconditionally. Only the
        // trace was gated behind debug, so production leaked things like
        // "Database connection failed: SQLSTATE[HY000] [1045] Access denied for
        // user 'x'@'host'" to anyone who sent an Accept: application/json
        // header — including attacker-supplied strings echoed back from
        // exception messages.
        $body = [
            'type'   => 'https://basehim.io/errors/exception',
            'title'  => self::titleFromStatus($status),
            'status' => $status,
            'detail' => $debug
                ? ($e->getMessage() ?: 'An error occurred.')
                : self::genericDetail($status),
        ];

        // A correlation id keeps production diagnosable: this value is written
        // to the log next to the real message and stack trace.
        $ref = self::logReference($e);
        if ($ref !== null) {
            $body['reference'] = $ref;
        }

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

    /** Safe, non-revealing message for each status class. */
    private static function genericDetail(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be understood.',
            401 => 'Authentication required.',
            403 => 'You do not have access to this resource.',
            404 => 'The requested resource was not found.',
            405 => 'That method is not allowed for this resource.',
            422 => 'The request could not be processed.',
            429 => 'Too many requests. Please slow down.',
            default => 'An unexpected error occurred. Please try again later.',
        };
    }

    /**
     * A short id shared between the response and the log line, so an operator
     * can find the real error without it being disclosed to the caller.
     */
    private static function logReference(\Throwable $e): ?string
    {
        try {
            return substr(hash('sha256', $e->getFile() . '|' . $e->getLine() . '|' . microtime(true)), 0, 12);
        } catch (\Throwable) {
            return null;
        }
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

        $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES);
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES);
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES);
        $class = $e::class;
        $line = $e->getLine();
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';

        echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Error · Basehim</title>
<link rel="stylesheet" href="{$base}/admin/assets/css/tailwind.min.css">
</head><body class="bg-slate-100 text-slate-900 font-sans">
<div class="max-w-5xl mx-auto p-8">
  <div class="bg-white rounded-2xl shadow-lg border border-red-200">
    <div class="p-6 border-b border-red-100 bg-red-50 rounded-t-2xl">
      <div class="flex items-center gap-3">
        <?= \App\Core\Icon::svg('exclamation-circle', 'w-6 h-6 text-red-500') ?>
        <div>
          <div class="text-xs uppercase tracking-wider text-red-600 font-semibold">{$class}</div>
          <h1 class="text-xl font-semibold text-slate-900">{$msg}</h1>
        </div>
      </div>
    </div>
    <div class="p-6 space-y-3">
      <div class="flex items-start gap-3">
        <?= \App\Core\Icon::svg('file-code', 'w-4 h-4 mt-1 text-blue-500') ?>
        <div class="text-sm text-slate-700"><span class="text-slate-500">in</span> <code class="bg-slate-100 px-2 py-1 rounded">{$file}</code> <span class="text-slate-500">at line</span> <span class="font-semibold">{$line}</span></div>
      </div>
      <pre class="bg-slate-900 text-slate-100 rounded-lg p-4 text-xs overflow-auto max-h-96">{$trace}</pre>
    </div>
  </div>
</div>
</body></html>
HTML;
    }

    private static function genericHtml(int $status): string
    {
        $title = self::titleFromStatus($status);
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        $home = $base ?: '/';
        $iconWarn = Icon::svg('exclamation-triangle', 'w-16 h-16 text-blue-500 mb-4 mx-auto');
        $iconHome = Icon::svg('home', 'w-4 h-4');

        return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{$status} · {$title}</title>
<link rel="stylesheet" href="{$base}/admin/assets/css/tailwind.min.css">
</head><body class="bg-blue-50 min-h-screen flex items-center justify-center text-slate-900">
<div class="text-center">
  {$iconWarn}
  <h1 class="text-5xl font-bold mb-2">{$status}</h1>
  <p class="text-slate-600 text-lg">{$title}</p>
  <a href="{$home}" class="inline-flex items-center gap-2 mt-6 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">{$iconHome} Back to home</a>
</div>
</body></html>
HTML;
    }

    private static function statusFromException(\Throwable $e): int
    {
        $code = $e->getCode();
        if ($code >= 400 && $code < 600) {
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
