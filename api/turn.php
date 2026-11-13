<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/Orchestrator.php';
require_once dirname(__DIR__) . '/lib/Process.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST erwartet.', 405);
}

$input = json_input();
$id = trim((string) ($input['id'] ?? ($_GET['id'] ?? '')));
if ($id === '') {
    json_error('Thread-ID fehlt.');
}

$force = !empty($input['force']);

try {
    $orch = new Orchestrator(new ThreadStore(), new JobStore());
    $result = $orch->enqueueTurn($id, $force);

    if (!empty($result['alreadyDone'])) {
        json_response([
            'ok' => true,
            'alreadyDone' => true,
            'jobId' => null,
            'thread' => $result['thread'],
            'done' => true,
            'message' => null,
        ]);
    }

    $job = $result['job'];
    $payload = [
        'ok' => true,
        'alreadyDone' => false,
        'jobId' => $job['id'],
        'job' => $job,
        'thread' => $result['thread'],
    ];

    if (!empty($result['spawned'])) {
        json_response($payload);
    }

    // Fallback: no CLI worker — finish HTTP response, then run inline.
    chatties_finish_response_and_continue($payload);
    $orch->runJob((string) $job['id']);
    exit;
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
