<?php

declare(strict_types=1);

require_once __DIR__ . '/Env.php';

final class ThreadStore
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__) . '/data/threads';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }

    public function create(array $payload): array
    {
        $id = bin2hex(random_bytes(8));
        $thread = [
            'id' => $id,
            'createdAt' => gmdate('c'),
            'status' => 'ready',
            'topic' => trim((string) ($payload['topic'] ?? '')),
            'maxRounds' => max(1, (int) ($payload['maxRounds'] ?? 3)),
            'currentRound' => 0,
            'nextSpeaker' => 'a',
            'agents' => [
                'a' => self::normalizeAgent($payload['agentA'] ?? [], 'A', 'Agent A'),
                'b' => self::normalizeAgent($payload['agentB'] ?? [], 'B', 'Agent B'),
            ],
            'messages' => [],
            'error' => null,
        ];

        $this->save($thread);
        return $thread;
    }

    public function get(string $id): ?array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @return list<array{
     *   id:string,
     *   createdAt:string,
     *   status:string,
     *   topic:string,
     *   maxRounds:int,
     *   currentRound:int,
     *   messageCount:int,
     *   agents:array{a:string,b:string}
     * }>
     */
    public function list(): array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        $items = [];

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['id'])) {
                continue;
            }

            $items[] = [
                'id' => (string) $data['id'],
                'createdAt' => (string) ($data['createdAt'] ?? ''),
                'status' => (string) ($data['status'] ?? 'unknown'),
                'topic' => (string) ($data['topic'] ?? ''),
                'maxRounds' => (int) ($data['maxRounds'] ?? 0),
                'currentRound' => (int) ($data['currentRound'] ?? 0),
                'messageCount' => self::countAgentMessages(is_array($data['messages'] ?? null) ? $data['messages'] : []),
                'agents' => [
                    'a' => (string) ($data['agents']['a']['name'] ?? 'A'),
                    'b' => (string) ($data['agents']['b']['name'] ?? 'B'),
                ],
            ];
        }

        usort(
            $items,
            static fn(array $x, array $y): int => strcmp($y['createdAt'], $x['createdAt'])
        );

        return $items;
    }

    public function save(array $thread): void
    {
        $path = $this->path((string) $thread['id']);
        $tmp = $path . '.tmp';
        $json = json_encode($thread, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Thread konnte nicht serialisiert werden.');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Thread konnte nicht geschrieben werden.');
        }
        rename($tmp, $path);
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            throw new InvalidArgumentException('Ungültige Thread-ID.');
        }
        return $this->dir . '/' . $id . '.json';
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private static function countAgentMessages(array $messages): int
    {
        $n = 0;
        foreach ($messages as $msg) {
            $speaker = (string) ($msg['speaker'] ?? '');
            if ($speaker === 'a' || $speaker === 'b') {
                $n++;
            }
        }
        return $n;
    }

    private static function normalizeAgent(array $agent, string $fallbackName, string $fallbackLabel): array
    {
        $fallbackUrl = Env::get('CHATTIES_API_URL', 'http://127.0.0.1:1234/v1') ?? 'http://127.0.0.1:1234/v1';
        $name = trim((string) ($agent['name'] ?? $fallbackName));
        $label = trim((string) ($agent['label'] ?? $fallbackLabel));
        $apiUrl = rtrim(trim((string) ($agent['apiUrl'] ?? $fallbackUrl)), '/');
        $model = trim((string) ($agent['model'] ?? ''));
        $context = trim((string) ($agent['context'] ?? ''));

        return [
            'name' => $name !== '' ? $name : $fallbackName,
            'label' => $label !== '' ? $label : $fallbackLabel,
            'apiUrl' => $apiUrl !== '' ? $apiUrl : $fallbackUrl,
            'model' => $model,
            'context' => $context,
        ];
    }
}
