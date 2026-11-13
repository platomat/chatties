<?php

declare(strict_types=1);

final class LlmClient
{
    /**
     * @param array<int, array{role:string, content:string}> $messages
     */
    public function chat(string $apiUrl, string $model, array $messages, float $temperature = 0.7): string
    {
        $base = rtrim($apiUrl, '/');
        $url = str_ends_with($base, '/chat/completions')
            ? $base
            : $base . '/chat/completions';

        $payload = [
            'messages' => $messages,
            'temperature' => $temperature,
            'stream' => false,
        ];
        if ($model !== '') {
            $payload['model'] = $model;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Request-Body konnte nicht gebaut werden.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURL konnte nicht initialisiert werden.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('LLM-Request fehlgeschlagen: ' . $error);
        }
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Leere Antwort vom LLM-Server (HTTP ' . $status . ').');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Ungültige JSON-Antwort vom LLM-Server (HTTP ' . $status . ').');
        }

        if ($status >= 400) {
            $msg = $data['error']['message'] ?? $data['error'] ?? ('HTTP ' . $status);
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            throw new RuntimeException('LLM-Fehler: ' . (string) $msg);
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('LLM lieferte keinen Textinhalt.');
        }

        return trim($content);
    }
}
