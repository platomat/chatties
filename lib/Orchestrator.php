<?php

declare(strict_types=1);

require_once __DIR__ . '/LlmClient.php';
require_once __DIR__ . '/ThreadStore.php';
require_once __DIR__ . '/JobStore.php';
require_once __DIR__ . '/JobCancelledException.php';
require_once __DIR__ . '/Process.php';

final class Orchestrator
{
    /** @var list<string> */
    private const SPEAKER_ORDER = ['a', 'b'];

    public function __construct(
        private ThreadStore $store,
        private JobStore $jobs = new JobStore(),
        private LlmClient $llm = new LlmClient(),
    ) {
    }

    /**
     * Grant more rounds and optionally inject a moderator directive.
     *
     * @return array{thread: array, directive: ?array}
     */
    public function extend(string $threadId, int $extraRounds, string $directive = ''): array
    {
        $thread = $this->store->get($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread nicht gefunden.');
        }
        if ($extraRounds < 1 || $extraRounds > 40) {
            throw new RuntimeException('extraRounds muss zwischen 1 und 40 liegen.');
        }

        $directive = trim($directive);
        $directiveMessage = null;

        if ($directive !== '') {
            $directiveMessage = [
                'id' => bin2hex(random_bytes(4)),
                'speaker' => 'mod',
                'name' => 'Moderator',
                'round' => (int) ($thread['currentRound'] ?? 0),
                'kind' => 'directive',
                'content' => $directive,
                'createdAt' => gmdate('c'),
            ];
            $thread['messages'][] = $directiveMessage;
        }

        $thread['maxRounds'] = (int) $thread['maxRounds'] + $extraRounds;
        $thread['status'] = 'ready';
        $thread['error'] = null;
        $this->store->save($thread);

        return [
            'thread' => $thread,
            'directive' => $directiveMessage,
        ];
    }

    /**
     * Create a background job for the next turn (does not call the LLM yet).
     *
     * @return array{alreadyDone: bool, job: ?array, thread: array, spawned: bool}
     */
    public function enqueueTurn(string $threadId, bool $force = false): array
    {
        $prepared = $this->prepareTurn($threadId, $force);
        $thread = $prepared['thread'];

        if ($prepared['alreadyDone']) {
            return [
                'alreadyDone' => true,
                'job' => null,
                'thread' => $thread,
                'spawned' => false,
            ];
        }

        $speakerKey = $prepared['speakerKey'];
        $agent = $prepared['agent'];
        $round = $prepared['round'];

        $job = $this->jobs->create([
            'threadId' => $thread['id'],
            'speaker' => $speakerKey,
            'name' => $agent['name'],
            'round' => $round,
        ]);

        $thread['status'] = 'running';
        $thread['error'] = null;
        $thread['activeJobId'] = $job['id'];
        $thread['generationId'] = $job['id'];
        $thread['generationStartedAt'] = gmdate('c');
        $this->store->save($thread);

        $spawned = chatties_spawn_job($job['id']);

        return [
            'alreadyDone' => false,
            'job' => $job,
            'thread' => $thread,
            'spawned' => $spawned,
        ];
    }

    /**
     * Run LLM for a queued/running job and finalize the thread message.
     */
    public function runJob(string $jobId): void
    {
        $lockFile = $this->jobs->lockPath($jobId);
        $lock = @fopen($lockFile, 'c+');
        if ($lock === false) {
            throw new RuntimeException('Job-Lock konnte nicht geöffnet werden.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return; // another worker owns it
        }

        try {
            $this->executeJob($jobId);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockFile);
        }
    }

    public function cancelJob(string $jobId): array
    {
        $job = $this->jobs->requestCancel($jobId);
        if ($job === null) {
            throw new RuntimeException('Job nicht gefunden.');
        }

        // If still queued (worker never started), finalize immediately.
        if (($job['status'] ?? '') === 'queued') {
            $job['status'] = 'cancelled';
            $job['finishedAt'] = gmdate('c');
            $this->jobs->save($job);
            $this->releaseThreadForJob($job, 'ready', null);
        }

        return $job;
    }

    /**
     * Reconcile thread + job after reload / poll (stale cleanup, sync finished jobs).
     *
     * @return array{thread: array, job: ?array}
     */
    public function reconcileThread(string $threadId): array
    {
        $thread = $this->store->get($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread nicht gefunden.');
        }

        $jobId = (string) ($thread['activeJobId'] ?? $thread['generationId'] ?? '');
        $job = $jobId !== '' ? $this->jobs->get($jobId) : null;

        if ($job !== null && $this->jobs->isStale($job)) {
            $job = $this->jobs->markStale($job);
            if (($thread['activeJobId'] ?? '') === $job['id'] || ($thread['generationId'] ?? '') === $job['id']) {
                $thread = $this->releaseThreadForJob($job, 'ready', null);
                $thread['resumedFromRunning'] = true;
            }
        } elseif ($job !== null && in_array($job['status'], ['done', 'error', 'cancelled'], true)) {
            // Worker finished but thread pointer may still say running (rare race).
            if (($thread['status'] ?? '') === 'running'
                && (($thread['activeJobId'] ?? '') === $job['id'] || ($thread['generationId'] ?? '') === $job['id'])
            ) {
                if (is_array($job['thread'] ?? null)) {
                    $thread = $job['thread'];
                } else {
                    $status = $job['status'] === 'error' ? 'error' : 'ready';
                    $thread = $this->releaseThreadForJob($job, $status, $job['error'] ?? null);
                }
            }
        } elseif (($thread['status'] ?? '') === 'running' && ($job === null || !$this->jobs->isActive($job))) {
            $started = strtotime((string) ($thread['generationStartedAt'] ?? '')) ?: 0;
            $stale = $started === 0 || (time() - $started) > 120;
            if ($stale) {
                $thread['status'] = 'ready';
                $thread['error'] = null;
                $thread['activeJobId'] = null;
                $thread['generationId'] = null;
                $thread['generationStartedAt'] = null;
                $this->store->save($thread);
                $thread['resumedFromRunning'] = true;
            }
        }

        return ['thread' => $thread, 'job' => $job];
    }

    private function executeJob(string $jobId): void
    {
        $job = $this->jobs->get($jobId);
        if ($job === null) {
            return;
        }
        if (in_array($job['status'], ['done', 'error', 'cancelled'], true)) {
            return;
        }
        if (!empty($job['cancelRequested'])) {
            $job['status'] = 'cancelled';
            $job['finishedAt'] = gmdate('c');
            $this->jobs->save($job);
            $this->releaseThreadForJob($job, 'ready', null);
            return;
        }

        $threadId = (string) $job['threadId'];
        $thread = $this->store->get($threadId);
        if ($thread === null) {
            $job['status'] = 'error';
            $job['error'] = 'Thread nicht gefunden.';
            $job['finishedAt'] = gmdate('c');
            $this->jobs->save($job);
            return;
        }

        // Superseded by a newer job.
        if (($thread['activeJobId'] ?? $thread['generationId'] ?? '') !== $jobId) {
            $job['status'] = 'cancelled';
            $job['error'] = 'Superseded.';
            $job['finishedAt'] = gmdate('c');
            $this->jobs->save($job);
            return;
        }

        $prepared = $this->prepareTurn($threadId, false, true);
        if ($prepared['alreadyDone']) {
            $job['status'] = 'done';
            $job['done'] = true;
            $job['thread'] = $prepared['thread'];
            $job['finishedAt'] = gmdate('c');
            $this->jobs->save($job);
            $this->clearActiveJob($prepared['thread']);
            return;
        }

        $job['status'] = 'running';
        $job['startedAt'] = gmdate('c');
        $job['speaker'] = $prepared['speakerKey'];
        $job['name'] = $prepared['agent']['name'];
        $job['round'] = $prepared['round'];
        $this->jobs->save($job);

        $speakerKey = $prepared['speakerKey'];
        $agent = $prepared['agent'];
        $round = $prepared['round'];
        $spoken = $prepared['spoken'];
        $totalSlots = $prepared['totalSlots'];
        $indexInRound = $prepared['indexInRound'];

        $lastFlush = 0.0;
        $partial = '';
        $persist = function (string $content, bool $force = false) use (&$lastFlush, $jobId): void {
            $now = microtime(true);
            if (!$force && ($now - $lastFlush) < 0.25) {
                return;
            }
            $lastFlush = $now;
            $current = $this->jobs->get($jobId);
            if ($current === null || in_array($current['status'], ['done', 'error', 'cancelled'], true)) {
                return;
            }
            $current['content'] = $content;
            $this->jobs->save($current);
        };

        try {
            $content = $this->llm->chat(
                (string) $agent['apiUrl'],
                (string) $agent['model'],
                $prepared['messages'],
                function (string $delta) use (&$partial, $persist): void {
                    $partial .= $delta;
                    $persist($partial, false);
                },
                0.7,
                (string) ($agent['apiKey'] ?? ''),
                function () use ($jobId): void {
                    // Heartbeat: touch job so stale cleanup stays quiet.
                    $current = $this->jobs->get($jobId);
                    if ($current !== null && ($current['status'] ?? '') === 'running') {
                        $this->jobs->save($current);
                    }
                },
                function () use ($jobId): bool {
                    return $this->jobs->isCancelRequested($jobId);
                },
            );

            $persist($content, true);

            $latest = $this->store->get($threadId);
            if ($latest === null || ($latest['activeJobId'] ?? $latest['generationId'] ?? '') !== $jobId) {
                $job = $this->jobs->get($jobId) ?? $job;
                $job['status'] = 'cancelled';
                $job['error'] = 'Superseded.';
                $job['content'] = $content;
                $job['finishedAt'] = gmdate('c');
                $this->jobs->save($job);
                return;
            }

            if ($this->jobs->isCancelRequested($jobId)) {
                throw new JobCancelledException('Job cancelled.');
            }

            $message = [
                'id' => bin2hex(random_bytes(4)),
                'speaker' => $speakerKey,
                'name' => $agent['name'],
                'round' => $round,
                'content' => $content,
                'createdAt' => gmdate('c'),
            ];

            $latest['messages'][] = $message;
            $latest['currentRound'] = $round;
            $latest['nextSpeaker'] = self::SPEAKER_ORDER[($indexInRound + 1) % count(self::SPEAKER_ORDER)];
            $latest['status'] = ($spoken + 1) >= $totalSlots ? 'done' : 'ready';
            $latest['error'] = null;
            $latest['activeJobId'] = null;
            $latest['generationId'] = null;
            $latest['generationStartedAt'] = null;
            $this->store->save($latest);

            $job = $this->jobs->get($jobId) ?? $job;
            $job['status'] = 'done';
            $job['content'] = $content;
            $job['message'] = $message;
            $job['thread'] = $latest;
            $job['done'] = $latest['status'] === 'done';
            $job['finishedAt'] = gmdate('c');
            $this->jobs->save($job);
        } catch (JobCancelledException $e) {
            $job = $this->jobs->get($jobId) ?? $job;
            $job['status'] = 'cancelled';
            $job['finishedAt'] = gmdate('c');
            $this->jobs->save($job);
            $this->releaseThreadForJob($job, 'ready', null);
        } catch (Throwable $e) {
            $job = $this->jobs->get($jobId) ?? $job;
            $job['status'] = 'error';
            $job['error'] = $e->getMessage();
            $job['finishedAt'] = gmdate('c');
            $this->jobs->save($job);
            $latest = $this->releaseThreadForJob($job, 'error', $e->getMessage());
            $job['thread'] = $latest;
            $this->jobs->save($job);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseThreadForJob(array $job, string $status, ?string $error): array
    {
        $thread = $this->store->get((string) $job['threadId']);
        if ($thread === null) {
            return [];
        }
        if (($thread['activeJobId'] ?? $thread['generationId'] ?? '') !== ($job['id'] ?? '')) {
            return $thread;
        }
        $thread['status'] = $status;
        $thread['error'] = $error;
        $thread['activeJobId'] = null;
        $thread['generationId'] = null;
        $thread['generationStartedAt'] = null;
        $this->store->save($thread);
        return $thread;
    }

    /**
     * @param array<string, mixed> $thread
     */
    private function clearActiveJob(array $thread): void
    {
        $thread['activeJobId'] = null;
        $thread['generationId'] = null;
        $thread['generationStartedAt'] = null;
        if (($thread['status'] ?? '') === 'running') {
            $thread['status'] = 'ready';
        }
        $this->store->save($thread);
    }

    /**
     * @return array{
     *   thread: array,
     *   alreadyDone: bool,
     *   speakerKey?: string,
     *   agent?: array,
     *   round?: int,
     *   spoken?: int,
     *   totalSlots?: int,
     *   indexInRound?: int,
     *   messages?: array<int, array{role:string, content:string}>
     * }
     */
    private function prepareTurn(string $threadId, bool $force = false, bool $forRunningJob = false): array
    {
        $thread = $this->store->get($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread nicht gefunden.');
        }

        if ($thread['status'] === 'done') {
            return ['thread' => $thread, 'alreadyDone' => true];
        }

        if ($thread['status'] === 'running' && !$forRunningJob) {
            $jobId = (string) ($thread['activeJobId'] ?? $thread['generationId'] ?? '');
            $job = $jobId !== '' ? $this->jobs->get($jobId) : null;

            if ($job !== null && $this->jobs->isActive($job) && !$this->jobs->isStale($job) && !$force) {
                throw new RuntimeException(
                    'A generation is still running for this thread. Reconnect to the job or cancel it first.'
                );
            }

            if ($force && $job !== null && $this->jobs->isActive($job)) {
                $this->jobs->requestCancel($jobId);
                $job['status'] = 'cancelled';
                $job['cancelRequested'] = true;
                $job['error'] = 'Superseded by force continue.';
                $job['finishedAt'] = gmdate('c');
                $this->jobs->save($job);
            } elseif ($job !== null && $this->jobs->isStale($job)) {
                $this->jobs->markStale($job);
            }

            $thread['status'] = 'ready';
            $thread['error'] = null;
            $thread['activeJobId'] = null;
            $thread['generationId'] = null;
            $thread['generationStartedAt'] = null;
            $this->store->save($thread);
        }

        if ($thread['status'] === 'error') {
            $thread['status'] = 'ready';
            $thread['error'] = null;
            $this->store->save($thread);
        }

        // When executing an existing job, keep status running.
        if ($forRunningJob && ($thread['status'] ?? '') === 'ready') {
            $thread['status'] = 'running';
            $this->store->save($thread);
        }

        $max = (int) $thread['maxRounds'];
        $agentCount = count(self::SPEAKER_ORDER);
        $spoken = self::countAgentMessages($thread);
        $totalSlots = $max * $agentCount;

        if ($spoken >= $totalSlots) {
            $thread['status'] = 'done';
            $this->store->save($thread);
            return ['thread' => $thread, 'alreadyDone' => true];
        }

        $round = intdiv($spoken, $agentCount) + 1;
        $indexInRound = $spoken % $agentCount;
        $speakerKey = self::SPEAKER_ORDER[$indexInRound];
        $agent = $thread['agents'][$speakerKey] ?? null;
        if (!is_array($agent)) {
            throw new RuntimeException('Ungültiger Sprecher.');
        }

        $isLastInRound = $indexInRound === $agentCount - 1;
        $remainingRoundsAfterThis = $max - $round;
        $remainingTurnsAfter = $totalSlots - $spoken - 1;

        return [
            'thread' => $thread,
            'alreadyDone' => false,
            'speakerKey' => $speakerKey,
            'agent' => $agent,
            'round' => $round,
            'spoken' => $spoken,
            'totalSlots' => $totalSlots,
            'indexInRound' => $indexInRound,
            'messages' => $this->buildMessages(
                $thread,
                $speakerKey,
                $round,
                $max,
                $isLastInRound,
                $remainingRoundsAfterThis,
                $remainingTurnsAfter,
            ),
        ];
    }

    /**
     * @return array<int, array{role:string, content:string}>
     */
    private function buildMessages(
        array $thread,
        string $speakerKey,
        int $round,
        int $max,
        bool $isLastInRound,
        int $remainingRoundsAfterThis,
        int $remainingTurnsAfter,
    ): array {
        $agent = $thread['agents'][$speakerKey];
        $otherKey = $speakerKey === 'a' ? 'b' : 'a';
        $other = $thread['agents'][$otherKey];

        $roleBits = [];
        if ($agent['context'] !== '') {
            $roleBits[] = $agent['context'];
        } else {
            $roleBits[] = 'Du bist ' . $agent['name'] . ' in einem Dialog.';
        }
        $roleBits[] = 'Dein Gesprächspartner heißt ' . $other['name'] . '.';
        $roleBits[] = 'Ihr diskutiert abwechselnd. Eine Runde ist erst vorbei, wenn alle Teilnehmer einmal gesprochen haben. Antworte nur als ' . $agent['name'] . ', ohne den Namen voranzustellen.';
        $roleBits[] = 'Thema / Aufgabe: ' . $thread['topic'];

        $directives = [];
        foreach ($thread['messages'] as $msg) {
            if (($msg['speaker'] ?? '') === 'mod' || ($msg['kind'] ?? '') === 'directive') {
                $directives[] = (string) $msg['content'];
            }
        }
        if ($directives !== []) {
            $roleBits[] = "Hinweise vom Moderator (bitte berücksichtigen):\n- " . implode("\n- ", $directives);
        }

        $roleBits[] = sprintf(
            'Aktuell: Runde %d von %d. Nach dieser Runde bleiben %d volle Runden. Nach deinem Beitrag bleiben insgesamt %d Einzelbeiträge.',
            $round,
            $max,
            $remainingRoundsAfterThis,
            $remainingTurnsAfter
        );

        if ($remainingTurnsAfter === 0) {
            $roleBits[] = 'Das ist der letzte Beitrag des Gesprächs. Fasse zusammen und schließe ab.';
        } elseif ($isLastInRound && $remainingRoundsAfterThis === 0) {
            $roleBits[] = 'Du schließt die letzte Runde ab. Bring das Gespräch zu einem klaren Ergebnis.';
        } elseif ($remainingRoundsAfterThis <= 1) {
            $roleBits[] = 'Die Runden gehen zur Neige — verdichte und arbeite auf einen Abschluss hin.';
        }

        $system = implode("\n\n", $roleBits);

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        if ($thread['messages'] === []) {
            $messages[] = [
                'role' => 'user',
                'content' => "Eröffne das Gespräch zum Thema. Bleib in deiner Rolle.\n\nThema: {$thread['topic']}",
            ];
            return $messages;
        }

        $lines = [];
        foreach ($thread['messages'] as $msg) {
            $lines[] = $msg['name'] . ': ' . $msg['content'];
        }
        $transcript = implode("\n\n", $lines);

        $messages[] = [
            'role' => 'user',
            'content' => "Bisheriger Dialog:\n\n{$transcript}\n\n---\nSchreibe jetzt den nächsten Beitrag als {$agent['name']}.",
        ];

        return $messages;
    }

    private static function countAgentMessages(array $thread): int
    {
        $n = 0;
        foreach ($thread['messages'] as $msg) {
            $speaker = (string) ($msg['speaker'] ?? '');
            if ($speaker === 'a' || $speaker === 'b') {
                $n++;
            }
        }
        return $n;
    }
}
