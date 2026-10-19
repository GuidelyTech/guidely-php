<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Http;

use GuidelyTech\Flags\Exception\TransportException;

/** HTTP transport using ext-curl when available, otherwise `file_get_contents` with a stream context. */
final class DefaultHttpClient implements HttpClientInterface
{
    public function __construct(private readonly ?bool $useCurl = null)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): HttpResponse
    {
        $curl = $this->useCurl ?? function_exists('curl_init');
        return $curl ? $this->sendWithCurl($method, $url, $headers, $body, $timeoutSeconds) : $this->sendWithStreams($method, $url, $headers, $body, $timeoutSeconds);
    }

    /** @param array<string, string> $headers */
    private function sendWithCurl(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TransportException("Could not initialise cURL for $url");
        }
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        $timeoutMs = max(1, (int) round($timeoutSeconds * 1000));
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = []; // a new response (after 100 Continue)
                } elseif (($pos = strpos($trimmed, ':')) !== false) {
                    $responseHeaders[strtolower(trim(substr($trimmed, 0, $pos)))] = trim(substr($trimmed, $pos + 1));
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $out = curl_exec($ch);
        if ($out === false || !is_string($out)) {
            $error = curl_error($ch);
            throw new TransportException("$method $url failed: $error");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new HttpResponse($status, $out, $responseHeaders);
    }

    /** @param array<string, string> $headers */
    private function sendWithStreams(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): HttpResponse
    {
        $lines = ['Connection: close'];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        $http = [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $context = stream_context_create(['http' => $http]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new TransportException("$method $url failed: $error");
        }
        try {
            $raw = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            $out = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
        if ($out === false) {
            throw new TransportException("$method $url failed: could not read the response");
        }
        $status = 0;
        $responseHeaders = [];
        foreach (is_array($raw) ? $raw : [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $responseHeaders = [];
            } elseif (($pos = strpos($line, ':')) !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }
        if ($status === 0) {
            throw new TransportException("$method $url failed: no HTTP status line");
        }
        return new HttpResponse($status, $out, $responseHeaders);
    }
}
