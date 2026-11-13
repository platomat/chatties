<?php

declare(strict_types=1);

require_once __DIR__ . '/LlmClient.php';
require_once __DIR__ . '/ThreadStore.php';

final class Orchestrator
{
    /** @var list<string> */
    private const SPEAKER_ORDER = ['a', 'b'];

    public function __construct(
        private ThreadStore $store,
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
     * @param callable(string, array):void $emit event name + payload
     */
    public function nextTurnStream(string $threadId, callable $emit): array
    {
        $prepared = $this->prepareTurn($threadId);
        $thread = $prepared['thread'];

        if ($prepared['alreadyDone']) {
            $emit('done', [
                'ok' => true,
                'thread' => $thread,
                'message' => null,
                'done' => true,
            ]);
            return $thread;
        }

        $speakerKey = $prepared['speakerKey'];
        $agent = $prepared['agent'];
        $round = $prepared['round'];
        $spoken = $prepared['spoken'];
        $totalSlots = $prepared['totalSlots'];
        $indexInRound = $prepared['indexInRound'];

        $emit('start', [
            'speaker' => $speakerKey,
            'name' => $agent['name'],
            'round' => $round,
            'threadId' => $thread['id'],
        ]);

        $thread['status'] = 'running';
        $thread['error'] = null;
        $this->store->save($thread);

        try {
            $content = $this->llm->chat(
                (string) $agent['apiUrl'],
                (string) $agent['model'],
                $prepared['messages'],
                static function (string $delta) use ($emit): void {
                    $emit('token', ['text' => $delta]);
                },
            );

            $message = [
                'id' => bin2hex(random_bytes(4)),
                'speaker' => $speakerKey,
                'name' => $agent['name'],
                'round' => $round,
                'content' => $content,
                'createdAt' => gmdate('c'),
            ];

            $thread['messages'][] = $message;
            $thread['currentRound'] = $round;
            $thread['nextSpeaker'] = self::SPEAKER_ORDER[($indexInRound + 1) % count(self::SPEAKER_ORDER)];
            $thread['status'] = ($spoken + 1) >= $totalSlots ? 'done' : 'ready';
            $thread['error'] = null;
            $this->store->save($thread);

            $emit('done', [
                'ok' => true,
                'thread' => $thread,
                'message' => $message,
                'done' => $thread['status'] === 'done',
            ]);

            return $thread;
        } catch (Throwable $e) {
            $thread['status'] = 'error';
            $thread['error'] = $e->getMessage();
            $this->store->save($thread);
            $emit('error', [
                'ok' => false,
                'error' => $e->getMessage(),
                'thread' => $thread,
            ]);
            return $thread;
        }
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
    private function prepareTurn(string $threadId): array
    {
        $thread = $this->store->get($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread nicht gefunden.');
        }

        if ($thread['status'] === 'done') {
            return ['thread' => $thread, 'alreadyDone' => true];
        }

        if ($thread['status'] === 'running' || $thread['status'] === 'error') {
            $thread['status'] = 'ready';
            $thread['error'] = null;
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
