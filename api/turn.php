<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/Orchestrator.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST erwartet.', 405);
}

$input = json_input();
$id = trim((string) ($input['id'] ?? ($_GET['id'] ?? '')));
if ($id === '') {
    json_error('Thread-ID fehlt.');
}

sse_bootstrap();

try {
    $orch = new Orchestrator(new ThreadStore());
    $orch->nextTurnStream($id, static function (string $event, array $payload): void {
        sse_event($event, $payload);
    });
} catch (Throwable $e) {
    // prepare/stream may already have emitted an error event; send one if not.
    sse_event('error', [
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
