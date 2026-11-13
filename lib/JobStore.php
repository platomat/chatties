<?php

declare(strict_types=1);

require_once __DIR__ . '/Env.php';

final class JobStore
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__) . '/data/jobs';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }

    /**
     * @param array<string, mixed> $seed
     * @return array<string, mixed>
     */
    public function create(array $seed): array
    {
        $id = bin2hex(random_bytes(8));
        $now = gmdate('c');
        $job = [
            'id' => $id,
            'threadId' => (string) ($seed['threadId'] ?? ''),
            'status' => 'queued',
            'speaker' => (string) ($seed['speaker'] ?? ''),
            'name' => (string) ($seed['name'] ?? ''),
            'round' => (int) ($seed['round'] ?? 0),
            'content' => '',
            'error' => null,
            'cancelRequested' => false,
            'createdAt' => $now,
            'updatedAt' => $now,
            'startedAt' => null,
            'finishedAt' => null,
            'message' => null,
            'thread' => null,
            'done' => false,
        ];
        $this->save($job);
        return $job;
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

    public function save(array $job): void
    {
        $path = $this->path((string) $job['id']);
        $job['updatedAt'] = gmdate('c');
        $tmp = $path . '.tmp';
        $json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Job konnte nicht serialisiert werden.');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Job konnte nicht geschrieben werden.');
        }
        rename($tmp, $path);
    }

    public function requestCancel(string $id): ?array
    {
        $job = $this->get($id);
        if ($job === null) {
            return null;
        }
        if (in_array($job['status'], ['done', 'error', 'cancelled'], true)) {
            return $job;
        }
        $job['cancelRequested'] = true;
        $this->save($job);
        return $job;
    }

    public function isCancelRequested(string $id): bool
    {
        $job = $this->get($id);
        return is_array($job) && !empty($job['cancelRequested']);
    }

    public function isActive(?array $job): bool
    {
        if ($job === null) {
            return false;
        }
        return in_array($job['status'] ?? '', ['queued', 'running'], true);
    }

    public function isStale(?array $job): bool
    {
        if ($job === null || !$this->isActive($job)) {
            return false;
        }
        $updated = strtotime((string) ($job['updatedAt'] ?? '')) ?: 0;
        if ($updated === 0) {
            return true;
        }
        return (time() - $updated) > $this->staleSeconds();
    }

    public function markStale(array $job, string $reason = 'Job timed out (no heartbeat).'): array
    {
        $job['status'] = 'error';
        $job['error'] = $reason;
        $job['finishedAt'] = gmdate('c');
        $this->save($job);
        return $job;
    }

    public function lockPath(string $id): string
    {
        return $this->path($id) . '.lock';
    }

    public function dir(): string
    {
        return $this->dir;
    }

    private function staleSeconds(): int
    {
        $raw = Env::get('CHATTIES_JOB_STALE_SECONDS', '120') ?? '120';
        if (!is_numeric($raw)) {
            return 120;
        }
        return max(30, (int) $raw);
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            throw new InvalidArgumentException('Ungültige Job-ID.');
        }
        return $this->dir . '/' . $id . '.json';
    }
}
