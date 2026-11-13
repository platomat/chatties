<?php

declare(strict_types=1);

require_once __DIR__ . '/Env.php';

function chatties_php_cli(): string
{
    $configured = Env::get('CHATTIES_PHP_BIN', '') ?? '';
    if (is_string($configured) && $configured !== '' && is_executable($configured)) {
        return $configured;
    }

    if (PHP_SAPI === 'cli' && PHP_BINARY !== '' && is_executable(PHP_BINARY) && !str_contains(PHP_BINARY, 'php-fpm')) {
        return PHP_BINARY;
    }

    foreach (['/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php8.1', '/usr/bin/php'] as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }

    return 'php';
}

/**
 * Spawn background CLI worker. Returns true if a PID was obtained.
 */
function chatties_spawn_job(string $jobId): bool
{
    if (!preg_match('/^[a-f0-9]{16}$/', $jobId)) {
        return false;
    }
    if (!function_exists('exec')) {
        return false;
    }

    $script = dirname(__DIR__) . '/bin/run-job.php';
    if (!is_file($script)) {
        return false;
    }

    $jobsDir = dirname(__DIR__) . '/data/jobs';
    if (!is_dir($jobsDir)) {
        mkdir($jobsDir, 0775, true);
    }

    $php = chatties_php_cli();
    $log = $jobsDir . '/' . $jobId . '.log';
    $cmd = sprintf(
        '%s %s %s >> %s 2>&1 & echo $!',
        escapeshellarg($php),
        escapeshellarg($script),
        escapeshellarg($jobId),
        escapeshellarg($log)
    );

    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);
    $pid = (int) ($out[0] ?? 0);
    if ($pid <= 0) {
        return false;
    }

    @file_put_contents($jobsDir . '/' . $jobId . '.pid', (string) $pid);
    return true;
}

/**
 * Send JSON, close the HTTP connection, keep PHP running (inline worker fallback).
 *
 * @param array<string, mixed> $payload
 */
function chatties_finish_response_and_continue(array $payload): void
{
    ignore_user_abort(true);
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"ok":false,"error":"encode failed"}';
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . (string) strlen($json));
    header('Connection: close');
    echo $json;
    flush();

    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
}
