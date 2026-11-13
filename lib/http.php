<?php

declare(strict_types=1);

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400, ?array $extra = null): never
{
    $payload = ['ok' => false, 'error' => $message];
    if ($extra !== null) {
        $payload = array_merge($payload, $extra);
    }
    json_response($payload, $status);
}

function sse_bootstrap(): void
{
    // Keep script alive briefly so we can detect abort and cancel the LLM curl.
    ignore_user_abort(true);
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', 'off');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
}

/** @param array<string, mixed> $data */
function sse_event(string $event, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"error":"encode failed"}';
    }
    echo "event: {$event}\ndata: {$json}\n\n";
    sse_flush();
}

/** Keep proxies from idle-closing long LLM streams. */
function sse_ping(): void
{
    echo ': ping ' . time() . "\n\n";
    sse_flush();
}

function sse_flush(): void
{
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}
