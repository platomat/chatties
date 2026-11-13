<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/Orchestrator.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST erwartet.', 405);
}

$input = json_input();
$id = trim((string) ($input['id'] ?? ''));
if ($id === '') {
    json_error('Thread-ID fehlt.');
}

$extraRounds = (int) ($input['extraRounds'] ?? 1);
$directive = trim((string) ($input['directive'] ?? ''));

try {
    $result = (new Orchestrator(new ThreadStore()))->extend($id, $extraRounds, $directive);
    json_response([
        'ok' => true,
        'thread' => $result['thread'],
        'directive' => $result['directive'],
        'extraRounds' => $extraRounds,
    ]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
