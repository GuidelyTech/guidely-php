<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

use GuidelyTech\Flags\Exception\FlagsException;
use GuidelyTech\Flags\Exception\RemoteEvaluationRequiredException;
use GuidelyTech\Flags\Exception\TransportException;
use GuidelyTech\Flags\Http\DefaultHttpClient;
use GuidelyTech\Flags\Http\HttpClientInterface;
use GuidelyTech\Flags\Http\HttpResponse;

/**
 * Guidely feature flags client (docs/feature-flags-spec.md §4).
 *
 * PHP processes are short-lived, so the client loads lazily: the payload lives in a cache shared
 * by every worker (with its ETag); when it is older than `cacheTtlSeconds` the next read
 * revalidates it with `If-None-Match`. If the API cannot be reached the last good payload keeps
 * serving. Getters never throw: problems go to `onError` / the logger and the caller's fallback
 * is returned.
 */
final class GuidelyFlags
{
    public const SDK_NAME = 'guidely-php';
    public const VERSION = '0.1.0';

    private const PATH_FLAGS = '/api/v1/sdk/flags';
    private const PATH_EVALUATE = '/api/v1/sdk/flags/evaluate';
    private const PATH_EVENTS = '/api/v1/sdk/flags/events';
    private const FLUSH_INTERVAL_SECONDS = 60;
    private const MAX_QUEUED_EXPOSURES = 500;
    private const USAGE_VALUE_MAX_CHARS = 200;

    private readonly Options $options;
    private readonly HttpClientInterface $http;
    private readonly string $cacheKey;

    /** @var array<array-key, mixed>|null the payload in use */
    private ?array $payload = null;
    /** Clock time when the payload was last known fresh (fetched, revalidated or tried). */
    private ?float $payloadCheckedAt = null;
    /** True when the payload came from the `payload` option (no fetching until refresh()). */
    private bool $bootstrapped = false;
    private ?string $lastError = null;

    /** @var array<string, mixed> the `overrides` option / setOverrides() layer */
    private array $overrides;
    /** @var array<string, mixed> dev overrides (spec §4.1), fixed at construction */
    private array $devOverrides = [];
    /** @var array<string, mixed> what evaluation uses: `overrides`, then dev overrides (later wins) */
    private array $effectiveOverrides;
    private OverrideBehaviour $overrideBehaviour;
    private bool $offline;

    /** @var array<string, array<string, EvaluationResult>|null> remote evaluation results per attribute set */
    private array $remoteResults = [];

    /** @var array<string, array{flag: string, variationId?: string, value: string, count: int}> */
    private array $usage = [];
    /** @var list<array<string, mixed>> */
    private array $exposures = [];
    /** @var array<string, true> */
    private array $tracked = [];
    private float $lastFlushAt;

    /**
     * @param Options|array<string, mixed> $options see {@see Options}
     */
    public function __construct(Options|array $options)
    {
        $this->options = $options instanceof Options ? $options : Options::fromArray($options);
        $this->http = $this->options->httpClient ?? new DefaultHttpClient();
        $this->overrides = $this->options->overrides;
        $this->overrideBehaviour = $this->options->overrideBehaviour;
        $this->offline = $this->options->offline;
        $this->cacheKey = 'guidely-flags.' . substr(hash('sha256', implode('|', [$this->options->apiUrl, $this->options->sdkKey ?? '', $this->options->environment ?? ''])), 0, 40);
        $this->lastFlushAt = $this->now();
        if ($this->options->devOverrides) {
            $this->applyDevOverrides(DevOverrides::read($this->options->env));
        }
        $this->effectiveOverrides = array_replace($this->overrides, $this->devOverrides);

        if ($this->options->payload !== null) {
            try {
                $this->payload = $this->parsePayload($this->options->payload);
                $this->bootstrapped = true;
            } catch (\Throwable $e) {
                $this->report(new FlagsException('Invalid bootstrap payload: ' . $e->getMessage(), 0, $e));
            }
        }

        if ($this->options->reportUsage || $this->options->reportExposures) {
            $ref = \WeakReference::create($this);
            register_shutdown_function(static function () use ($ref): void {
                $ref->get()?->flush();
            });
        }
    }

    public function __destruct()
    {
        $this->flush();
    }

    // ---------------------------------------------------------------- getters

    /** Boolean flag value; `$fallback` when the flag is missing, null or not a boolean. */
    public function isEnabled(string $key, array|object $attributes = [], bool $fallback = false): bool
    {
        $value = $this->evaluateTracked($key, $attributes)->value;
        return is_bool($value) ? $value : $fallback;
    }

    /** The flag's value, or `$fallback` when it is null (missing flag, error, …). */
    public function getValue(string $key, mixed $fallback = null, array|object $attributes = []): mixed
    {
        return $this->evaluateTracked($key, $attributes)->value ?? $fallback;
    }

    public function getString(string $key, ?string $fallback = null, array|object $attributes = []): ?string
    {
        $value = $this->evaluateTracked($key, $attributes)->value;
        return is_string($value) ? $value : $fallback;
    }

    public function getNumber(string $key, int|float|null $fallback = null, array|object $attributes = []): int|float|null
    {
        $value = $this->evaluateTracked($key, $attributes)->value;
        return is_int($value) || is_float($value) ? $value : $fallback;
    }

    public function getBool(string $key, ?bool $fallback = null, array|object $attributes = []): ?bool
    {
        $value = $this->evaluateTracked($key, $attributes)->value;
        return is_bool($value) ? $value : $fallback;
    }

    /**
     * A JSON object or array value (objects as associative arrays; see {@see Json}); `$fallback`
     * for other types.
     */
    public function getJson(string $key, array|object|null $fallback = null, array|object $attributes = []): array|object|null
    {
        $value = $this->evaluateTracked($key, $attributes)->value;
        return is_array($value) || is_object($value) ? $value : $fallback;
    }

    /**
     * The full evaluation result. When the value is null, the result carries `$fallback` as its
     * value (the reason still says why).
     */
    public function getDetails(string $key, mixed $fallback = null, array|object $attributes = []): EvaluationResult
    {
        $result = $this->evaluateTracked($key, $attributes);
        if ($result->value !== null || $fallback === null) {
            return $result;
        }
        return new EvaluationResult($result->key, $fallback, $result->reason, $result->ruleId, $result->variationId, $result->inExperiment, $result->experiment, $result->errorMessage);
    }

    /**
     * Every flag evaluated for the attributes (e.g. to bootstrap a browser). Does not count usage
     * or track exposures: read experiment flags with the getters so exposures are recorded.
     *
     * @return array<string, EvaluationResult>
     */
    public function getAll(array|object $attributes = []): array
    {
        try {
            $attrs = $this->mergeAttributes($attributes);
            if ($this->overrideBehaviour !== OverrideBehaviour::LocalOnly && $this->options->remoteEval) {
                $results = $this->remoteResults($attrs) ?? [];
            } else {
                $payload = $this->ensurePayload();
                $results = $payload === null ? [] : Evaluator::evaluateAll($payload, $attrs, $this->now(), $this->options->stickyBucketService);
            }
            foreach ($this->effectiveOverrides as $key => $value) {
                $key = (string) $key;
                if ($this->overrideBehaviour !== OverrideBehaviour::RemoteOverLocal || !isset($results[$key]) || $results[$key]->reason === Reason::FLAG_NOT_FOUND) {
                    $results[$key] = EvaluationResult::override($key, $value);
                }
            }
            return $results;
        } catch (\Throwable $e) {
            $this->report($e);
            return [];
        }
    }

    /** A view bound to one user's attributes (merged over `defaultAttributes`). */
    public function forUser(array|object $attributes): UserContext
    {
        return new UserContext($this, Json::asMap($attributes));
    }

    // ---------------------------------------------------------------- configuration

    /**
     * Replace the local overrides (flag key => value), optionally changing how they combine with
     * the remote payload. Dev overrides (environment variables) stay and still win.
     *
     * @param array<string, mixed> $overrides
     */
    public function setOverrides(array $overrides, OverrideBehaviour|string|null $behaviour = null): void
    {
        $this->overrides = $overrides;
        $this->effectiveOverrides = array_replace($overrides, $this->devOverrides);
        if ($behaviour !== null) {
            $this->overrideBehaviour = is_string($behaviour)
                ? (OverrideBehaviour::tryFrom($behaviour) ?? throw new \InvalidArgumentException('Unknown override behaviour ' . $behaviour))
                : $behaviour;
        }
    }

    /** Stop (or resume) all network activity; cached data keeps serving. */
    public function setOffline(bool $offline): void
    {
        $this->offline = $offline;
    }

    /**
     * Fetch the payload now (ignoring the cache TTL and any bootstrap payload). Returns false when
     * the fetch failed; the previous payload keeps serving and the error goes to `onError`.
     */
    public function refresh(): bool
    {
        $this->remoteResults = [];
        if ($this->offline || $this->options->sdkKey === null || $this->overrideBehaviour === OverrideBehaviour::LocalOnly) {
            return false;
        }
        $this->bootstrapped = false;
        if ($this->options->remoteEval) {
            return true; // results are fetched again on the next read
        }
        return $this->load(true);
    }

    /**
     * The payload currently in use (loading it if needed), or null when none is available.
     *
     * @return array<array-key, mixed>|null
     */
    public function getPayload(): ?array
    {
        try {
            return $this->ensurePayload();
        } catch (\Throwable $e) {
            $this->report($e);
            return null;
        }
    }

    /** The last loading error message, if the most recent attempt failed. */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    // ---------------------------------------------------------------- evaluation

    /** @param array<array-key, mixed>|object $attributes */
    private function evaluateTracked(string $key, array|object $attributes): EvaluationResult
    {
        $attrs = [];
        try {
            $attrs = $this->mergeAttributes($attributes);
            $result = $this->evaluateRaw($key, $attrs);
        } catch (\Throwable $e) {
            $this->report($e);
            $result = EvaluationResult::error($key, $e->getMessage());
        }
        $this->track($result, $attrs);
        return $result;
    }

    /** @param array<array-key, mixed> $attrs */
    private function evaluateRaw(string $key, array $attrs): EvaluationResult
    {
        $hasOverride = array_key_exists($key, $this->effectiveOverrides);
        if ($hasOverride && $this->overrideBehaviour !== OverrideBehaviour::RemoteOverLocal) {
            return EvaluationResult::override($key, $this->effectiveOverrides[$key]);
        }
        // From here on an override (remoteOverLocal) only fills in for flags missing remotely.
        $missing = $hasOverride ? EvaluationResult::override($key, $this->effectiveOverrides[$key]) : new EvaluationResult($key, null, Reason::FLAG_NOT_FOUND);
        if ($this->overrideBehaviour === OverrideBehaviour::LocalOnly && $this->payload === null) {
            return $missing;
        }
        if ($this->options->remoteEval && $this->overrideBehaviour !== OverrideBehaviour::LocalOnly) {
            $results = $this->remoteResults($attrs);
            if ($results === null) {
                return $hasOverride ? $missing : $this->unavailable($key, 'Remote evaluation unavailable');
            }
            $result = $results[$key] ?? $missing;
        } else {
            $payload = $this->ensurePayload();
            if ($payload === null) {
                return $hasOverride ? $missing : $this->unavailable($key, 'No flags payload available');
            }
            $result = Evaluator::evaluate($payload, $key, $attrs, $this->now(), $this->options->stickyBucketService);
        }
        return $result->reason === Reason::FLAG_NOT_FOUND ? $missing : $result;
    }

    private function unavailable(string $key, string $what): EvaluationResult
    {
        return EvaluationResult::error($key, $what . ($this->lastError !== null ? ': ' . $this->lastError : ''));
    }

    /**
     * @param array<array-key, mixed>|object $attributes
     * @return array<array-key, mixed>
     */
    private function mergeAttributes(array|object $attributes): array
    {
        $attrs = Json::asMap($attributes);
        return $this->options->defaultAttributes === [] ? $attrs : array_replace($this->options->defaultAttributes, $attrs);
    }

    /** @param array<array-key, mixed> $attrs */
    private function track(EvaluationResult $result, array $attrs): void
    {
        $this->callHook($this->options->onFlagEvaluated, $result);
        // Overrides are local, not served by Guidely: they are never counted (spec §4).
        if ($this->options->reportUsage && !in_array($result->reason, [Reason::FLAG_NOT_FOUND, Reason::ERROR, Reason::OVERRIDE], true)) {
            $text = self::valueText($result->value);
            $id = $result->key . "\0" . ($result->variationId !== null ? 'v:' . $result->variationId : 't:' . $text);
            if (isset($this->usage[$id])) {
                $this->usage[$id]['count']++;
            } else {
                $entry = ['flag' => $result->key];
                if ($result->variationId !== null) {
                    $entry['variationId'] = $result->variationId;
                }
                $this->usage[$id] = $entry + ['value' => $text, 'count' => 1];
            }
        }
        $experiment = $result->experiment;
        if ($result->inExperiment && $experiment !== null) {
            $hashValue = (string) ($experiment['hashValue'] ?? '');
            $variationId = (string) ($experiment['variationId'] ?? $result->variationId ?? '');
            $experimentKey = (string) ($experiment['key'] ?? '');
            $id = $experimentKey . "\0" . $hashValue . "\0" . $variationId;
            if (!isset($this->tracked[$id])) {
                $this->tracked[$id] = true;
                $this->callHook($this->options->trackingCallback, $experiment, $result);
                if ($this->options->reportExposures) {
                    $exposure = [
                        'experiment' => $experimentKey,
                        'flag' => $result->key,
                        'variationId' => $variationId,
                        'hashAttribute' => (string) ($experiment['hashAttribute'] ?? ''),
                        'hashValue' => $hashValue,
                    ];
                    $userId = Values::textOf(Evaluator::lookupAttribute($attrs, 'id'));
                    if ($userId !== null && $userId !== '') {
                        $exposure['userId'] = $userId;
                    }
                    $anonymousId = Values::textOf(Evaluator::lookupAttribute($attrs, 'anonymousId'));
                    if ($anonymousId !== null && $anonymousId !== '') {
                        $exposure['anonymousId'] = $anonymousId;
                    }
                    $exposure['timestamp'] = gmdate('Y-m-d\TH:i:s\Z', (int) $this->now());
                    $this->exposures[] = $exposure;
                }
            }
        }
        if (count($this->exposures) >= self::MAX_QUEUED_EXPOSURES || $this->now() - $this->lastFlushAt >= self::FLUSH_INTERVAL_SECONDS) {
            $this->flush();
        }
    }

    /** JSON text of a served value, truncated to 200 characters. */
    private static function valueText(mixed $value): string
    {
        try {
            $text = Json::encode($value);
        } catch (\JsonException) {
            $text = 'null';
        }
        return mb_strlen($text, 'UTF-8') > self::USAGE_VALUE_MAX_CHARS ? mb_substr($text, 0, self::USAGE_VALUE_MAX_CHARS, 'UTF-8') : $text;
    }

    // ---------------------------------------------------------------- payload loading

    /** @return array<array-key, mixed>|null */
    private function ensurePayload(): ?array
    {
        if ($this->bootstrapped || $this->overrideBehaviour === OverrideBehaviour::LocalOnly) {
            return $this->payload;
        }
        if ($this->offline || $this->options->sdkKey === null) {
            if ($this->payload === null && $this->options->sdkKey !== null && $this->payloadCheckedAt === null) {
                $this->payloadCheckedAt = $this->now();
                $entry = $this->readCache();
                if ($entry !== null) {
                    $this->applyCachedBody($entry['body']);
                }
            }
            return $this->payload;
        }
        if ($this->payloadCheckedAt !== null && $this->now() - $this->payloadCheckedAt < $this->options->cacheTtlSeconds) {
            return $this->payload;
        }
        $this->load(false);
        return $this->payload;
    }

    /** Load from the cache, revalidating / fetching when stale (or always when forced). */
    private function load(bool $force): bool
    {
        $now = $this->now();
        $this->payloadCheckedAt = $now;
        $entry = $this->readCache();
        $usableEntry = $entry !== null && $this->applyCachedBody($entry['body']);
        if (!$force && $usableEntry && $now - $entry['fetchedAt'] < $this->options->cacheTtlSeconds) {
            $this->payloadCheckedAt = (float) $entry['fetchedAt'];
            return true;
        }
        try {
            $headers = [];
            if ($usableEntry && $entry['etag'] !== null) {
                $headers['If-None-Match'] = $entry['etag'];
            }
            $response = $this->request('GET', self::PATH_FLAGS, null, $headers);
            if ($response->status === 304 && $usableEntry) {
                $this->writeCache($entry['body'], $entry['etag'], $now);
            } elseif ($response->status >= 200 && $response->status < 300) {
                $this->payload = $this->parsePayload($response->body);
                $this->writeCache($response->body, $response->header('etag'), $now);
            } else {
                throw $this->httpError('GET ' . self::PATH_FLAGS, $response);
            }
            $this->lastError = null;
            return true;
        } catch (\Throwable $e) {
            $this->report($e);
            if ($usableEntry) {
                // Keep serving the stale payload; let every worker retry after another TTL.
                $this->writeCache($entry['body'], $entry['etag'], $now);
            }
            return false;
        }
    }

    /**
     * Payload from an array or JSON text, decrypted when encrypted.
     *
     * @param array<array-key, mixed>|string $raw
     * @return array<array-key, mixed>
     */
    private function parsePayload(array|string $raw): array
    {
        $data = is_string($raw) ? Json::decode($raw) : $raw;
        if (!Json::isObject($data)) {
            throw new FlagsException('Invalid flags payload: expected a JSON object');
        }
        $data = PayloadDecryptor::decryptPayload(Json::asMap($data), $this->options->decryptionKey);
        if (!array_key_exists('flags', $data)) {
            throw new FlagsException('Invalid flags payload: no "flags"');
        }
        return $data;
    }

    private function applyCachedBody(string $body): bool
    {
        try {
            $this->payload = $this->parsePayload($body);
            return true;
        } catch (\Throwable $e) {
            $this->report($e);
            return false;
        }
    }

    /** @return array{body: string, etag: ?string, fetchedAt: float}|null */
    private function readCache(): ?array
    {
        try {
            $entry = $this->options->cache->get($this->cacheKey);
        } catch (\Throwable $e) {
            $this->report($e);
            return null;
        }
        if (!is_array($entry) || !is_string($entry['body'] ?? null) || !is_numeric($entry['fetchedAt'] ?? null)) {
            return null;
        }
        return [
            'body' => $entry['body'],
            'etag' => is_string($entry['etag'] ?? null) ? $entry['etag'] : null,
            'fetchedAt' => (float) $entry['fetchedAt'],
        ];
    }

    private function writeCache(string $body, ?string $etag, float $fetchedAt): void
    {
        try {
            $this->options->cache->set($this->cacheKey, ['body' => $body, 'etag' => $etag, 'fetchedAt' => $fetchedAt]);
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    // ---------------------------------------------------------------- remote evaluation

    /**
     * @param array<array-key, mixed> $attrs
     * @return array<string, EvaluationResult>|null null when the request failed
     */
    private function remoteResults(array $attrs): ?array
    {
        $wire = self::wireValue($attrs);
        $body = Json::encode(['attributes' => $wire === [] ? new \stdClass() : $wire]);
        $id = hash('sha256', $body);
        if (array_key_exists($id, $this->remoteResults)) {
            return $this->remoteResults[$id];
        }
        if ($this->offline || $this->options->sdkKey === null) {
            $this->lastError = $this->offline ? 'offline' : 'no sdkKey';
            return null;
        }
        try {
            $response = $this->request('POST', self::PATH_EVALUATE, $body);
            if ($response->status < 200 || $response->status >= 300) {
                throw $this->httpError('POST ' . self::PATH_EVALUATE, $response);
            }
            $data = Json::asMap(Json::decode($response->body));
            $results = [];
            foreach (Json::asMap($data['flags'] ?? null) as $key => $result) {
                $results[(string) $key] = EvaluationResult::fromArray(Json::asMap($result), (string) $key);
            }
            $this->lastError = null;
            return $this->remoteResults[$id] = $results;
        } catch (\Throwable $e) {
            $this->report($e);
            return $this->remoteResults[$id] = null;
        }
    }

    /** Attributes as sent over the wire: dates become ISO 8601 strings. */
    private static function wireValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.vP');
        }
        if (is_array($value)) {
            return array_map([self::class, 'wireValue'], $value);
        }
        if ($value instanceof \stdClass) {
            return (object) array_map([self::class, 'wireValue'], get_object_vars($value));
        }
        return $value;
    }

    // ---------------------------------------------------------------- events

    /**
     * Send queued usage counters and experiment exposures (POST /api/v1/sdk/flags/events).
     * Called automatically every 60 s of activity and at shutdown; never throws.
     */
    public function flush(): void
    {
        try {
            $this->lastFlushAt = $this->now();
            if ($this->usage === [] && $this->exposures === []) {
                return;
            }
            $batch = ['sdk' => ['name' => self::SDK_NAME, 'version' => self::VERSION]];
            if ($this->usage !== []) {
                $batch['usage'] = array_values($this->usage);
            }
            if ($this->exposures !== []) {
                $batch['exposures'] = $this->exposures;
            }
            $this->usage = [];
            $this->exposures = [];
            if ($this->offline || $this->options->sdkKey === null || $this->overrideBehaviour === OverrideBehaviour::LocalOnly) {
                return;
            }
            $response = $this->request('POST', self::PATH_EVENTS, Json::encode($batch));
            if ($response->status < 200 || $response->status >= 300) {
                throw $this->httpError('POST ' . self::PATH_EVENTS, $response);
            }
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    // ---------------------------------------------------------------- transport

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, ?string $body = null, array $headers = []): HttpResponse
    {
        $key = (string) $this->options->sdkKey;
        $headers += [
            'Accept' => 'application/json',
            'User-Agent' => self::SDK_NAME . '/' . self::VERSION . ' PHP/' . PHP_VERSION,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($this->options->usesSecretKey()) {
            $headers['Authorization'] = 'Bearer ' . $key;
            $query = ['environment' => (string) $this->options->environment];
        } else {
            $query = ['key' => $key];
        }
        $url = $this->options->apiUrl . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $this->http->send($method, $url, $headers, $body, $this->options->timeoutSeconds);
    }

    private function httpError(string $what, HttpResponse $response): TransportException
    {
        $code = null;
        $message = null;
        try {
            $error = Json::asMap(Json::asMap(Json::decode($response->body))['error'] ?? null);
            $code = is_string($error['code'] ?? null) ? $error['code'] : null;
            $message = is_string($error['message'] ?? null) ? $error['message'] : null;
        } catch (\Throwable) {
            // not JSON
        }
        if ($response->status === 403 && $code === 'remote_evaluation_required') {
            return new RemoteEvaluationRequiredException();
        }
        return new TransportException(sprintf('%s failed with HTTP %d%s', $what, $response->status, $code !== null ? " ($code" . ($message !== null ? ": $message" : '') . ')' : ''), $response->status);
    }

    // ---------------------------------------------------------------- helpers

    /** Takes dev overrides in, reports broken sources and warns once when any source is in use. */
    private function applyDevOverrides(DevOverrides $dev): void
    {
        $this->devOverrides = $dev->values;
        $this->overrideBehaviour = $dev->behaviour ?? $this->overrideBehaviour;
        foreach ($dev->errors as $error) {
            $this->report(new FlagsException($error));
        }
        if ($dev->sources === []) {
            return;
        }
        $n = count($dev->values);
        $this->warn(sprintf('Dev overrides from %s: %d flag%s, behaviour %s.', implode(', ', $dev->sources), $n, $n === 1 ? '' : 's', $this->overrideBehaviour->value));
    }

    private function now(): float
    {
        return $this->options->clock !== null ? (float) ($this->options->clock)() : microtime(true);
    }

    private function callHook(?\Closure $hook, mixed ...$args): void
    {
        if ($hook === null) {
            return;
        }
        try {
            $hook(...$args);
        } catch (\Throwable $e) {
            $this->report(new FlagsException('A Guidely flags callback threw: ' . $e->getMessage(), 0, $e));
        }
    }

    private function report(\Throwable $error): void
    {
        $this->lastError = $error->getMessage();
        $handled = false;
        if ($this->options->onError !== null) {
            $handled = true;
            try {
                ($this->options->onError)($error);
            } catch (\Throwable) {
                // never let the error handler break flag reads
            }
        }
        if ($this->options->logger !== null) {
            $handled = true;
            try {
                $this->options->logger->error('[guidely-php] ' . $error->getMessage(), ['exception' => $error]);
            } catch (\Throwable) {
            }
        }
        if (!$handled) {
            error_log('[guidely-php] ' . $error->getMessage());
        }
    }

    /** A warning to the logger (`warning()`, else `error()`), or `error_log` without one. */
    private function warn(string $message): void
    {
        $logger = $this->options->logger;
        if ($logger === null) {
            error_log('[guidely-php] ' . $message);
            return;
        }
        try {
            method_exists($logger, 'warning') ? $logger->warning('[guidely-php] ' . $message) : $logger->error('[guidely-php] ' . $message);
        } catch (\Throwable) {
        }
    }
}
