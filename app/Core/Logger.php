<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public function __construct(private string $logDir)
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
    }

    public function emergency(string $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(string $message, array $context = []): void     { $this->log('alert', $message, $context); }
    public function critical(string $message, array $context = []): void  { $this->log('critical', $message, $context); }
    public function error(string $message, array $context = []): void     { $this->log('error', $message, $context); }
    public function warning(string $message, array $context = []): void   { $this->log('warning', $message, $context); }
    public function notice(string $message, array $context = []): void    { $this->log('notice', $message, $context); }
    public function info(string $message, array $context = []): void      { $this->log('info', $message, $context); }
    public function debug(string $message, array $context = []): void     { $this->log('debug', $message, $context); }

    public function log(string $level, string $message, array $context = []): void
    {
        $date = date('Y-m-d');
        $file = $this->logDir . "/basehim-{$date}.log";

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $this->interpolate($message, $context),
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $k => $v) {
            if (is_scalar($v) || (is_object($v) && method_exists($v, '__toString'))) {
                $replace['{' . $k . '}'] = (string) $v;
            }
        }
        return strtr($message, $replace);
    }
}
