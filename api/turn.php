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

try {
    $orch = new Orchestrator(new ThreadStore());
    $thread = $orch->nextTurn($id);
    $last = $thread['messages'] !== [] ? $thread['messages'][array_key_last($thread['messages'])] : null;
    json_response([
        'ok' => true,
        'thread' => $thread,
        'message' => $last,
        'done' => $thread['status'] === 'done',
    ]);
} catch (Throwable $e) {
    $store = new ThreadStore();
    $thread = null;
    try {
        $thread = $store->get($id);
    } catch (Throwable) {
        // ignore
    }
    json_error($e->getMessage(), 500, ['thread' => $thread]);
}
