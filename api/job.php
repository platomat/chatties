<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/Orchestrator.php';

$orch = new Orchestrator(new ThreadStore(), new JobStore());
$jobs = new JobStore();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_input();
    $id = trim((string) ($input['id'] ?? ''));
    $action = trim((string) ($input['action'] ?? 'cancel'));
    if ($id === '') {
        json_error('Job-ID fehlt.');
    }
    if ($action !== 'cancel') {
        json_error('Unbekannte action.');
    }
    try {
        $job = $orch->cancelJob($id);
        json_response(['ok' => true, 'job' => $job]);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET oder POST erwartet.', 405);
}

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '') {
    json_error('Job-ID fehlt.');
}

$wantsSse = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream')
    || (($_GET['stream'] ?? '') === '1');

try {
    $job = $jobs->get($id);
    if ($job === null) {
        json_error('Job nicht gefunden.', 404);
    }

    if ($jobs->isStale($job)) {
        $job = $jobs->markStale($job);
        $orch->reconcileThread((string) $job['threadId']);
        $job = $jobs->get($id) ?? $job;
    }

    if (!$wantsSse) {
        json_response(['ok' => true, 'job' => $job]);
    }

    sse_bootstrap();
    // Watching must not cancel the job when the browser disconnects.
    ignore_user_abort(true);

    $sentStart = false;
    $offset = 0;
    $lastPing = time();
    $deadline = time() + 7200;

    while (time() < $deadline) {
        $job = $jobs->get($id);
        if ($job === null) {
            sse_event('error', ['ok' => false, 'error' => 'Job nicht gefunden.']);
            break;
        }

        if ($jobs->isStale($job)) {
            $job = $jobs->markStale($job);
            $synced = $orch->reconcileThread((string) $job['threadId']);
            sse_event('error', [
                'ok' => false,
                'error' => (string) ($job['error'] ?? 'Job timed out.'),
                'thread' => $synced['thread'],
                'job' => $job,
            ]);
            break;
        }

        if (!$sentStart) {
            sse_event('start', [
                'speaker' => $job['speaker'],
                'name' => $job['name'],
                'round' => $job['round'],
                'threadId' => $job['threadId'],
                'jobId' => $job['id'],
            ]);
            $sentStart = true;
        }

        $content = (string) ($job['content'] ?? '');
        if (strlen($content) > $offset) {
            $delta = substr($content, $offset);
            $offset = strlen($content);
            if ($delta !== '') {
                sse_event('token', ['text' => $delta]);
            }
        }

        $status = (string) ($job['status'] ?? '');
        if ($status === 'done') {
            sse_event('done', [
                'ok' => true,
                'thread' => $job['thread'],
                'message' => $job['message'],
                'done' => !empty($job['done']),
                'job' => $job,
            ]);
            break;
        }
        if ($status === 'error') {
            sse_event('error', [
                'ok' => false,
                'error' => (string) ($job['error'] ?? 'Job error'),
                'thread' => $job['thread'],
                'job' => $job,
            ]);
            break;
        }
        if ($status === 'cancelled') {
            $synced = $orch->reconcileThread((string) $job['threadId']);
            sse_event('cancelled', [
                'ok' => true,
                'thread' => $synced['thread'] ?: $job['thread'],
                'job' => $job,
            ]);
            break;
        }

        if (time() - $lastPing >= 10) {
            sse_ping();
            $lastPing = time();
        }

        usleep(200000);
    }
} catch (Throwable $e) {
    if ($wantsSse) {
        sse_event('error', ['ok' => false, 'error' => $e->getMessage()]);
    } else {
        json_error($e->getMessage(), 500);
    }
}
