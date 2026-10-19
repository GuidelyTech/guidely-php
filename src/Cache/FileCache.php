<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Cache;

use GuidelyTech\Flags\Exception\FlagsException;

/**
 * JSON files in a directory (default: `<sys_get_temp_dir()>/guidely-flags-<uid>`). Writes go to a
 * temporary file that is renamed into place, so concurrent PHP workers never read a partial file.
 * The default cache: zero setup, shared by every worker on the host.
 */
final class FileCache implements CacheInterface
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        if ($directory === null) {
            $user = function_exists('posix_geteuid') ? (string) posix_geteuid() : preg_replace('/[^A-Za-z0-9_-]/', '', get_current_user());
            $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'guidely-flags-' . $user;
        }
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function get(string $key): mixed
    {
        $raw = @file_get_contents($this->path($key));
        if ($raw === false) {
            return null;
        }
        $item = json_decode($raw, true);
        if (!is_array($item) || !array_key_exists('value', $item)) {
            return null;
        }
        if (isset($item['expires']) && is_numeric($item['expires']) && $item['expires'] <= time()) {
            return null;
        }
        return $item['value'];
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new FlagsException("Cannot create cache directory {$this->directory}");
        }
        $json = json_encode(['expires' => $ttlSeconds === null ? null : time() + $ttlSeconds, 'value' => $value], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new FlagsException('Cannot encode cache value: ' . json_last_error_msg());
        }
        $tmp = @tempnam($this->directory, 'tmp-');
        if ($tmp === false || @file_put_contents($tmp, $json) === false) {
            throw new FlagsException("Cannot write to cache directory {$this->directory}");
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path($key))) {
            @unlink($tmp);
            throw new FlagsException("Cannot write cache file {$this->path($key)}");
        }
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
