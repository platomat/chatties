<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/Orchestrator.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET erwartet.', 405);
}

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '') {
    json_error('Thread-ID fehlt.');
}

try {
    $orch = new Orchestrator(new ThreadStore(), new JobStore());
    $result = $orch->reconcileThread($id);
    json_response([
        'ok' => true,
        'thread' => $result['thread'],
        'job' => $result['job'],
    ]);
} catch (Throwable $e) {
    $code = str_contains($e->getMessage(), 'nicht gefunden') ? 404 : 500;
    json_error($e->getMessage(), $code);
}
