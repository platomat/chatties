<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/ThreadStore.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET erwartet.', 405);
}

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '') {
    json_error('Thread-ID fehlt.');
}

try {
    $store = new ThreadStore();
    $thread = $store->get($id);
    if ($thread === null) {
        json_error('Thread nicht gefunden.', 404);
    }

    // Interrupted mid-stream (page reload) → unlock so the client can resume.
    if (($thread['status'] ?? '') === 'running') {
        $thread['status'] = 'ready';
        $thread['error'] = null;
        $store->save($thread);
        $thread['resumedFromRunning'] = true;
    }

    json_response(['ok' => true, 'thread' => $thread]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
