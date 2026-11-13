<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/ThreadStore.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET erwartet.', 405);
}

try {
    $threads = (new ThreadStore())->list();
    json_response(['ok' => true, 'threads' => $threads]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
