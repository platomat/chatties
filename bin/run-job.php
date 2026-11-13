#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$jobId = isset($argv[1]) ? trim((string) $argv[1]) : '';
if ($jobId === '' || !preg_match('/^[a-f0-9]{16}$/', $jobId)) {
    fwrite(STDERR, "Usage: run-job.php <jobId>\n");
    exit(1);
}

require_once dirname(__DIR__) . '/lib/Orchestrator.php';

@ini_set('max_execution_time', '0');
@set_time_limit(0);
ignore_user_abort(true);

try {
    $orch = new Orchestrator(new ThreadStore(), new JobStore());
    $orch->runJob($jobId);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
