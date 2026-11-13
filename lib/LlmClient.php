<?php

declare(strict_types=1);

require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/ClientAbortedException.php';

final class LlmClient
{
    /**
     * @param array<int, array{role:string, content:string}> $messages
     * @param callable(string):void|null $onDelta
     * @param callable():void|null $onHeartbeat
     */
    public function chat(
        string $apiUrl,
        string $model,
        array $messages,
        ?callable $onDelta = null,
        float $temperature = 0.7,
        string $apiKey = '',
        ?callable $onHeartbeat = null,
    ): string {
        $stream = $onDelta !== null;
        $url = $this->completionsUrl($apiUrl);
        $timeout = $this->httpTimeout();

        $payload = [
            'messages' => $messages,
            'temperature' => $temperature,
            'stream' => $stream,
        ];
        if ($model !== '') {
            $payload['model'] = $model;
        }
        $maxTokens = $this->maxTokens();
        if ($maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Request-Body konnte nicht gebaut werden.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURL konnte nicht initialisiert werden.');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
        ];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        if (!$stream) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
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

            return $this->parseNonStreamResponse($raw, $status);
        }

        $buffer = '';
        $content = '';
        $httpStatus = 0;
        $sawEvent = false;
        $streamError = null;
        $abortHits = 0;
        $lastHeartbeat = time();

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function (
                $ch,
                float $dlTotal,
                float $dlNow,
                float $ulTotal,
                float $ulNow
            ) use (&$lastHeartbeat, &$streamError, $onHeartbeat): int {
                if ($streamError !== null) {
                    return 1;
                }
                $now = time();
                if ($now - $lastHeartbeat >= 10) {
                    $lastHeartbeat = $now;
                    if ($onHeartbeat !== null) {
                        $onHeartbeat();
                    }
                    // Confirm abort only around heartbeat (avoid false positives mid-chunk).
                    if (connection_aborted()) {
                        $streamError = '__client_aborted__';
                        return 1;
                    }
                }
                return 0;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (
                &$buffer,
                &$content,
                &$httpStatus,
                &$sawEvent,
                &$streamError,
                &$abortHits,
                &$lastHeartbeat,
                $onDelta,
                $onHeartbeat
            ): int {
                if ($streamError !== null) {
                    return 0;
                }
                if ($httpStatus === 0) {
                    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                }
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '') {
                        continue;
                    }

                    if (!$sawEvent && str_starts_with($line, '{')) {
                        $maybe = json_decode($line, true);
                        if (is_array($maybe) && isset($maybe['error'])) {
                            $streamError = self::errorMessage($maybe['error'], 'LLM-Fehler');
                            return 0;
                        }
                    }

                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $sawEvent = true;
                    $payload = trim(substr($line, 5));
                    if ($payload === '[DONE]') {
                        continue;
                    }

                    $json = json_decode($payload, true);
                    if (!is_array($json)) {
                        continue;
                    }
                    if (isset($json['error'])) {
                        $streamError = self::errorMessage($json['error'], 'LLM-Fehler');
                        return 0;
                    }

                    $delta = $json['choices'][0]['delta']['content'] ?? null;
                    if (!is_string($delta) || $delta === '') {
                        continue;
                    }
                    $content .= $delta;
                    $onDelta($delta);
                    $lastHeartbeat = time();
                }

                // Require repeated abort signals — single blips happen with proxies.
                if (connection_aborted()) {
                    $abortHits++;
                    if ($abortHits >= 3) {
                        $streamError = '__client_aborted__';
                        return 0;
                    }
                    if ($onHeartbeat !== null) {
                        $onHeartbeat();
                    }
                } else {
                    $abortHits = 0;
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        if ($httpStatus === 0) {
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }
        curl_close($ch);

        if ($streamError === '__client_aborted__') {
            throw new ClientAbortedException('Client disconnected.');
        }
        if ($streamError !== null) {
            throw new RuntimeException($streamError);
        }
        // CURLE_WRITE_ERROR (23) / CURLE_ABORTED_BY_CALLBACK (42)
        if ($ok === false && $errno !== 0 && $errno !== 23 && $errno !== 42) {
            throw new RuntimeException('LLM-Stream fehlgeschlagen: ' . ($error !== '' ? $error : 'unbekannt'));
        }
        if ($httpStatus >= 400) {
            throw new RuntimeException('LLM-Fehler: HTTP ' . $httpStatus);
        }

        $content = trim($content);
        if ($content === '') {
            throw new RuntimeException('LLM lieferte keinen Textinhalt.');
        }

        return $content;
    }

    private function httpTimeout(): int
    {
        $raw = Env::get('CHATTIES_HTTP_TIMEOUT', '1800') ?? '1800';
        if (!is_numeric($raw)) {
            return 1800;
        }
        return max(0, (int) $raw);
    }

    private function maxTokens(): int
    {
        $raw = Env::get('CHATTIES_MAX_TOKENS', '1024') ?? '1024';
        if (!is_numeric($raw)) {
            return 1024;
        }
        return max(0, (int) $raw);
    }

    private function completionsUrl(string $apiUrl): string
    {
        $base = rtrim($apiUrl, '/');
        return str_ends_with($base, '/chat/completions')
            ? $base
            : $base . '/chat/completions';
    }

    private function parseNonStreamResponse(string $raw, int $status): string
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Ungültige JSON-Antwort vom LLM-Server (HTTP ' . $status . ').');
        }

        if ($status >= 400) {
            throw new RuntimeException(self::errorMessage($data['error'] ?? ('HTTP ' . $status), 'LLM-Fehler'));
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('LLM lieferte keinen Textinhalt.');
        }

        return trim($content);
    }

    private static function errorMessage(mixed $error, string $prefix): string
    {
        if (is_array($error)) {
            $msg = $error['message'] ?? $error;
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            return $prefix . ': ' . (string) $msg;
        }
        return $prefix . ': ' . (string) $error;
    }
}
