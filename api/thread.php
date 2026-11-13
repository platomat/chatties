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

    // Only unlock clearly stale "running" locks (orphaned after crash).
    if (($thread['status'] ?? '') === 'running') {
        $started = strtotime((string) ($thread['generationStartedAt'] ?? '')) ?: 0;
        $stale = $started === 0 || (time() - $started) > 90;
        if ($stale) {
            $thread['status'] = 'ready';
            $thread['error'] = null;
            $thread['generationId'] = null;
            $thread['generationStartedAt'] = null;
            $store->save($thread);
            $thread['resumedFromRunning'] = true;
        }
    }

    json_response(['ok' => true, 'thread' => $thread]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
