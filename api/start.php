<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/ThreadStore.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST erwartet.', 405);
}

$input = json_input();
$topic = trim((string) ($input['topic'] ?? ''));
if ($topic === '') {
    json_error('Bitte ein Thema / eine Fragestellung angeben.');
}

$maxRounds = (int) ($input['maxRounds'] ?? 3);
if ($maxRounds < 1 || $maxRounds > 40) {
    json_error('maxRounds muss zwischen 1 und 40 liegen.');
}

try {
    $store = new ThreadStore();
    $thread = $store->create([
        'topic' => $topic,
        'maxRounds' => $maxRounds,
        'agentA' => $input['agentA'] ?? [],
        'agentB' => $input['agentB'] ?? [],
    ]);
    json_response(['ok' => true, 'thread' => $thread]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
