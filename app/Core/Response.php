<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private int $status = 200;
    private array $headers = [];
    private string $body = '';

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function make(string $body = '', int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        return new self(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status, $headers);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        // Prepend install base for relative URLs so redirects work when
        // Basehim is installed in a subdirectory (e.g. /basehim/).
        if ($url !== '' && $url[0] === '/' && defined('BASEHIM_BASE') && BASEHIM_BASE !== '') {
            // But don't double-prepend if it's already there
            if (!str_starts_with($url, BASEHIM_BASE . '/') && $url !== BASEHIM_BASE) {
                $url = BASEHIM_BASE . $url;
            }
        }
        return new self('', $status, ['Location' => $url]);
    }

    public static function view(string $template, array $data = [], int $status = 200): self
    {
        $view = Application::getInstance()->make(View::class);
        return new self($view->render($template, $data), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function status(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }
        echo $this->body;
    }

    public function getStatus(): int { return $this->status; }
    public function getBody(): string { return $this->body; }
    public function getHeaders(): array { return $this->headers; }
}
