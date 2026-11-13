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

    public function nextTurn(string $threadId): array
    {
        $thread = $this->store->get($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread nicht gefunden.');
        }

        if ($thread['status'] === 'done') {
            return $thread;
        }
        if ($thread['status'] === 'error') {
            return $thread;
        }
        if ($thread['status'] === 'running') {
            throw new RuntimeException('Ein Turn läuft bereits.');
        }

        $max = (int) $thread['maxRounds'];
        $agentCount = count(self::SPEAKER_ORDER);
        $spoken = count($thread['messages']);
        $totalSlots = $max * $agentCount;

        if ($spoken >= $totalSlots) {
            $thread['status'] = 'done';
            $this->store->save($thread);
            return $thread;
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

        $thread['status'] = 'running';
        $thread['error'] = null;
        $this->store->save($thread);

        try {
            $messages = $this->buildMessages(
                $thread,
                $speakerKey,
                $round,
                $max,
                $isLastInRound,
                $remainingRoundsAfterThis,
                $remainingTurnsAfter,
            );
            $content = $this->llm->chat(
                (string) $agent['apiUrl'],
                (string) $agent['model'],
                $messages,
            );

            $thread['messages'][] = [
                'id' => bin2hex(random_bytes(4)),
                'speaker' => $speakerKey,
                'name' => $agent['name'],
                'round' => $round,
                'content' => $content,
                'createdAt' => gmdate('c'),
            ];
            $thread['currentRound'] = $round;
            $thread['nextSpeaker'] = self::SPEAKER_ORDER[($indexInRound + 1) % $agentCount];
            $thread['status'] = ($spoken + 1) >= $totalSlots ? 'done' : 'ready';
            $thread['error'] = null;
            $this->store->save($thread);

            return $thread;
        } catch (Throwable $e) {
            $thread['status'] = 'error';
            $thread['error'] = $e->getMessage();
            $this->store->save($thread);
            throw $e;
        }
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
}
