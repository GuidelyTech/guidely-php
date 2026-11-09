<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

use GuidelyTech\Flags\GuidelyFlags;

/**
 * Turns throwables, PHP errors and messages into `exception` messages
 * (docs/error-monitoring/sdks.md): Guidely `properties` (frames innermost first) plus a
 * Sentry-shaped `detail` (frames oldest call first, previous exceptions before the thrown one), and
 * holds the client's scope — tags, extra, contexts, user and the last 100 breadcrumbs.
 *
 * @internal
 */
final class ErrorCapture
{
    public const MAX_CHAIN = 20;
    public const MAX_STACK_FRAMES = 250;
    public const MAX_PROPERTY_FRAMES = 30;
    /** Byte budget for `properties.frames` (an event's properties are capped at 8 KB). */
    public const MAX_FRAMES_BYTES = 6000;
    public const MAX_DETAIL_BYTES = 65536;
    public const MAX_TAGS = 50;
    public const PLATFORM = 'php';
    /** Keys that never leave the app (the server applies the same rule again). */
    public const SENSITIVE = '/password|passwd|secret|token|credit|card|cvv|ssn|iban|authorization|cookie|api[-_]?key/i';
    /** Header names removed whatever SENSITIVE says. */
    private const CREDENTIAL_HEADER = '/^(cookie|set-cookie|authorization|proxy-authorization|x-api-key|x-auth-token|x-csrf-token|x-xsrf-token)$/i';

    /** @var array<string, string> */
    private array $tags = [];
    /** @var array<string, mixed> */
    private array $extra = [];
    /** @var array<string, array<string, mixed>> */
    private array $contexts = [];
    /** @var array<string, string>|null */
    private ?array $user = null;
    /** @var list<array<string, mixed>> */
    private array $breadcrumbs = [];
    private readonly ?string $environment;
    private readonly ?string $release;

    /** @param \Closure(): float $random */
    public function __construct(
        private readonly ErrorOptions $options,
        string $environment,
        ?string $appVersion,
        private readonly \Closure $random,
    ) {
        $this->environment = $options->environment ?? $environment;
        $this->release = Messages::version($options->release ?? $appVersion);
    }

    // --- scope ----------------------------------------------------------------------------------

    public function setTag(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($this->tags[$key]);
        } else {
            $this->tags[$key] = $value;
        }
    }

    public function setExtra(string $key, mixed $value): void
    {
        if ($value === null) {
            unset($this->extra[$key]);
        } else {
            $this->extra[$key] = $value;
        }
    }

    /** @param array<string, mixed>|null $value */
    public function setContext(string $name, ?array $value): void
    {
        if ($value === null) {
            unset($this->contexts[$name]);
        } else {
            $this->contexts[$name] = $value;
        }
    }

    /** @param array{id?: string|int|null, email?: string|null, username?: string|null}|null $user */
    public function setUser(?array $user): void
    {
        $u = [];
        foreach (['id', 'email', 'username'] as $k) {
            $v = $user[$k] ?? null;
            if ((is_string($v) && $v !== '') || is_int($v)) {
                $u[$k] = (string) $v;
            }
        }
        $this->user = $u === [] ? null : $u;
    }

    /** @param array{category?: string, message?: string, level?: Level|string, type?: string, data?: array<string, mixed>, timestamp?: \DateTimeInterface|float|int} $crumb */
    public function addBreadcrumb(array $crumb, \DateTimeImmutable $now): void
    {
        if ($this->options->maxBreadcrumbs === 0) {
            return;
        }
        if ($this->options->beforeBreadcrumb !== null) {
            try {
                $edited = ($this->options->beforeBreadcrumb)($crumb);
                if ($edited === null) {
                    return;
                }
                if (is_array($edited)) {
                    $crumb = $edited;
                }
            } catch (\Throwable) {
                // a hook that throws keeps the breadcrumb unchanged
            }
        }
        $ts = $crumb['timestamp'] ?? null;
        $b = [
            'timestamp' => $ts instanceof \DateTimeInterface ? self::epochSeconds($ts) : (is_int($ts) || is_float($ts) ? (float) $ts : self::epochSeconds($now)),
            'type' => is_string($crumb['type'] ?? null) ? $crumb['type'] : 'default',
        ];
        if (is_string($crumb['category'] ?? null)) {
            $b['category'] = self::cut($crumb['category'], 200);
        }
        if (is_string($crumb['message'] ?? null)) {
            $b['message'] = self::cut($crumb['message'], 1000);
        }
        $b['level'] = (Level::of($crumb['level'] ?? null) ?? Level::Info)->value;
        if (isset($crumb['data']) && is_array($crumb['data']) && $crumb['data'] !== []) {
            $b['data'] = self::scrub($crumb['data']);
        }
        $this->breadcrumbs[] = $b;
        if (count($this->breadcrumbs) > $this->options->maxBreadcrumbs) {
            $this->breadcrumbs = array_slice($this->breadcrumbs, -$this->options->maxBreadcrumbs);
        }
    }

    public function environment(): ?string
    {
        return $this->environment;
    }

    public function release(): ?string
    {
        return $this->release;
    }

    public function serverName(): ?string
    {
        return $this->options->serverName;
    }

    /** @return array<string, string> the scope's tags, as errors send them */
    public function scopeTags(): array
    {
        return self::tags($this->tags);
    }

    /** @return array<string, string>|null */
    public function scopeUser(): ?array
    {
        return $this->user;
    }

    /** @return array<string, array<string, mixed>> */
    public function scopeContexts(): array
    {
        return $this->contexts;
    }

    public function breadcrumbCount(): int
    {
        return count($this->breadcrumbs);
    }

    // --- building -------------------------------------------------------------------------------

    /**
     * The `exception` message's fields (for {@see Messages::build()}), or null when it is dropped
     * by `sampleRate`, the ignore lists or `beforeSend`.
     *
     * @param array<string, mixed> $context captureException()'s second argument
     * @param array{source: string, mechanism: ?string, handled: bool, level?: ?Level} $how
     * @param list<array{file: ?string, line: ?int, function: ?string}>|null $frames the thrown exception's frames when they don't come from its trace (PHP errors)
     * @param string|null $type / $text the type and message of a PHP error or of captureMessage()
     */
    public function build(?\Throwable $throwable, ?string $type, ?string $text, array $context, array $how, \DateTimeImmutable $now, string $messageId, ?array $frames = null): ?array
    {
        $level = $how['level'] ?? Level::of($context['level'] ?? null) ?? ($throwable !== null || $type !== null ? Level::Error : Level::Info);
        $chain = $throwable !== null ? self::chain($throwable) : [];
        if ($throwable !== null && $type === null) {
            $type = StackTrace::className(get_class($throwable));
            $text = $throwable->getMessage();
        }
        $isMessage = $type === null;
        $type ??= 'Message';
        $text ??= '';
        if ($this->ignored($throwable, $isMessage ? null : $type, $text)) {
            return null;
        }
        if ($this->options->sampleRate < 1 && !(($this->random)() < $this->options->sampleRate)) {
            return null;
        }

        $mainFrames = $frames ?? ($throwable !== null ? StackTrace::fromThrowable($throwable) : []);
        $fingerprint = isset($context['fingerprint']) && is_array($context['fingerprint']) && $context['fingerprint'] !== []
            ? array_values(array_map('strval', $context['fingerprint'])) : null;
        $transaction = is_string($context['transaction'] ?? null) ? $context['transaction'] : null;

        // properties: what indexes the occurrence
        $props = [
            'type' => self::cut($type, 200),
            'message' => self::cut($text, 1000),
            'frames' => $this->propertyFrames($mainFrames),
            'source' => $how['source'],
            'handled' => $how['handled'],
            'level' => $level->value,
            'platform' => self::PLATFORM,
        ];
        if ($how['mechanism'] !== null) {
            $props['mechanism'] = $how['mechanism'];
        }
        if ($this->release !== null) {
            $props['release'] = $this->release;
        }
        if ($fingerprint !== null) {
            $props['fingerprintParts'] = $fingerprint;
        }
        if ($transaction !== null) {
            $props['transaction'] = self::cut($transaction, 200);
        }
        foreach ($context as $k => $v) {
            if (!in_array($k, self::CONTEXT_KEYS, true) && !array_key_exists($k, $props) && is_string($k) && preg_match(self::SENSITIVE, $k) !== 1) {
                $props[$k] = $v;
            }
        }

        // detail: everything else, in Sentry's shape
        $detail = [
            'platform' => self::PLATFORM,
            'level' => $level->value,
            'timestamp' => self::epochSeconds($now),
        ];
        if ($this->release !== null) {
            $detail['release'] = $this->release;
        }
        if ($this->environment !== null) {
            $detail['environment'] = $this->environment;
        }
        if ($this->options->serverName !== null) {
            $detail['server_name'] = $this->options->serverName;
        }
        if ($transaction !== null) {
            $detail['transaction'] = $transaction;
        }
        if ($isMessage) {
            $detail['message'] = ['formatted' => self::cut($text, 8000)];
        } else {
            $detail['exception'] = ['values' => $this->exceptionValues($chain, $type, $text, $mainFrames, $how)];
        }
        if ($this->breadcrumbs !== []) {
            $detail['breadcrumbs'] = ['values' => $this->breadcrumbs];
        }
        $contexts = ['runtime' => self::runtimeContext(), 'os' => self::osContext()] + $this->contexts;
        foreach ((is_array($context['contexts'] ?? null) ? $context['contexts'] : []) as $name => $value) {
            if ($value === null) {
                unset($contexts[$name]);
            } elseif (is_array($value)) {
                $contexts[$name] = $value;
            }
        }
        $detail['contexts'] = self::scrub($contexts);
        $tags = self::tags(array_merge($this->tags, is_array($context['tags'] ?? null) ? $context['tags'] : []));
        if ($tags !== []) {
            $detail['tags'] = $tags;
        }
        $extra = self::scrub(array_merge($this->extra, is_array($context['extra'] ?? null) ? $context['extra'] : []));
        if ($extra !== []) {
            $detail['extra'] = $extra;
        }
        $user = $this->user;
        $userId = isset($context['userId']) && (is_string($context['userId']) || is_int($context['userId'])) && (string) $context['userId'] !== '' ? (string) $context['userId'] : null;
        if ($userId !== null) {
            $user = ['id' => $userId] + ($user ?? []);
        }
        if ($user !== null) {
            $detail['user'] = $user;
        }
        if ($fingerprint !== null) {
            $detail['fingerprint'] = $fingerprint;
        }
        if (is_array($context['request'] ?? null)) {
            $request = self::request($context['request']);
            if ($request !== []) {
                $detail['request'] = $request;
            }
        }
        $detail['sdk'] = ['name' => GuidelyFlags::SDK_NAME, 'version' => GuidelyFlags::VERSION];

        $event = [
            'properties' => $props,
            'detail' => $detail,
            'userId' => $userId ?? ($user['id'] ?? null),
            'anonymousId' => is_string($context['anonymousId'] ?? null) && $context['anonymousId'] !== '' ? $context['anonymousId'] : null,
            'accountId' => isset($context['accountId']) && (is_string($context['accountId']) || is_int($context['accountId'])) ? (string) $context['accountId'] : null,
        ];
        if ($this->options->beforeSend !== null) {
            try {
                $edited = ($this->options->beforeSend)($event, $throwable);
                if ($edited === null) {
                    return null;
                }
                if (is_array($edited) && is_array($edited['properties'] ?? null)) {
                    $event = $edited;
                }
            } catch (\Throwable) {
                // a hook that throws: the event is sent unchanged (arrays are copies, so $event is intact)
            }
        }

        $args = ['messageId' => $messageId, 'timestamp' => $now];
        foreach (['userId', 'anonymousId', 'accountId'] as $k) {
            if (isset($event[$k]) && $event[$k] !== '') {
                $args[$k] = $event[$k];
            }
        }
        if ($this->release !== null) {
            $args['appVersion'] = $this->release;
        }
        $args['properties'] = $event['properties'];
        $fitted = is_array($event['detail'] ?? null) ? self::fit($event['detail'], self::MAX_DETAIL_BYTES) : null;
        if ($fitted !== null) {
            $args['detail'] = $fitted;
        }
        return $args;
    }

    /** captureException() keys that are not event properties. */
    private const CONTEXT_KEYS = ['userId', 'anonymousId', 'accountId', 'tags', 'extra', 'level', 'fingerprint', 'contexts', 'request', 'transaction', 'handled', 'mechanism'];

    /**
     * The throwable and its previous exceptions, thrown first, at most 20, stopping at a cycle.
     *
     * @return list<\Throwable>
     */
    public static function chain(\Throwable $e): array
    {
        $chain = [];
        $seen = [];
        for ($c = $e; $c !== null && count($chain) < self::MAX_CHAIN && !isset($seen[spl_object_id($c)]); $c = $c->getPrevious()) {
            $seen[spl_object_id($c)] = true;
            $chain[] = $c;
        }
        return $chain;
    }

    /**
     * `detail.exception.values`: the oldest previous exception first and the thrown one last.
     *
     * @param list<\Throwable> $chain
     * @param list<array{file: ?string, line: ?int, function: ?string}> $mainFrames
     */
    private function exceptionValues(array $chain, string $type, string $text, array $mainFrames, array $how): array
    {
        $entries = $chain === [] ? [null] : $chain;
        $values = [];
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $x = $entries[$i];
            $v = [
                'type' => $i === 0 ? $type : StackTrace::className(get_class($x)),
                'value' => self::cut($i === 0 ? $text : $x->getMessage(), 8000),
            ];
            $v['mechanism'] = $i === 0
                ? ['type' => $how['mechanism'] ?? 'generic', 'handled' => $how['handled'], 'exception_id' => 0]
                : ['type' => 'chained', 'handled' => $how['handled'], 'exception_id' => $i, 'parent_id' => $i - 1, 'source' => 'previous'];
            $v['stacktrace'] = ['frames' => $this->detailFrames($i === 0 ? $mainFrames : StackTrace::fromThrowable($x))];
            $values[] = $v;
        }
        return $values;
    }

    /**
     * Sentry frames, oldest call first and the throwing frame last (the innermost 250).
     *
     * @param list<array{file: ?string, line: ?int, function: ?string}> $frames innermost first
     */
    private function detailFrames(array $frames): array
    {
        $out = [];
        foreach (array_reverse(array_slice($frames, 0, self::MAX_STACK_FRAMES)) as $f) {
            $abs = $f['file'];
            $frame = [
                'filename' => $abs === null ? '[internal]' : $this->relative($abs),
                'function' => $f['function'] ?? '{main}',
            ];
            if ($abs !== null) {
                $frame['abs_path'] = $abs;
            }
            if ($f['line'] !== null && $f['line'] > 0) {
                $frame['lineno'] = $f['line'];
            }
            $frame['in_app'] = $this->inApp($abs);
            $out[] = $frame;
        }
        return $out;
    }

    /**
     * Guidely frames, innermost first, at most 30 and 6,000 bytes.
     *
     * @param list<array{file: ?string, line: ?int, function: ?string}> $frames
     */
    private function propertyFrames(array $frames): array
    {
        $out = [];
        foreach (array_slice($frames, 0, self::MAX_PROPERTY_FRAMES) as $f) {
            $frame = ['fn' => self::cut($f['function'] ?? '{main}', 200)];
            $frame['file'] = self::cut($f['file'] === null ? '[internal]' : $this->relative($f['file']), 500);
            if ($f['line'] !== null && $f['line'] > 0) {
                $frame['line'] = $f['line'];
            }
            $frame['inApp'] = $this->inApp($f['file']);
            $out[] = $frame;
        }
        while (count($out) > 1 && strlen(json_encode($out, Messages::JSON_FLAGS)) > self::MAX_FRAMES_BYTES) {
            array_pop($out);
        }
        return $out;
    }

    /** Includes win, then the excludes (`/vendor/` and yours); everything else is your code. */
    public function inApp(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }
        $p = str_replace('\\', '/', $path);
        foreach ($this->options->inAppInclude as $prefix) {
            if (str_starts_with($p, str_replace('\\', '/', $prefix))) {
                return true;
            }
        }
        foreach ($this->options->inAppExclude as $fragment) {
            if (str_contains($p, str_replace('\\', '/', $fragment))) {
                return false;
            }
        }
        return true;
    }

    private function relative(string $path): string
    {
        $root = $this->options->projectRoot;
        if ($root !== null && $root !== '') {
            $root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
            if (str_starts_with($path, $root)) {
                return substr($path, strlen($root));
            }
        }
        return $path;
    }

    private function ignored(?\Throwable $e, ?string $type, string $message): bool
    {
        if ($e !== null) {
            foreach ($this->options->ignoreExceptions as $class) {
                if (is_string($class) && $e instanceof $class) {
                    return true;
                }
            }
        }
        $candidates = $type === null ? [$message] : [$message, "$type: $message"];
        foreach ($this->options->ignoreErrors as $needle) {
            foreach ($candidates as $s) {
                if (is_string($needle) && $needle !== '' && str_contains($s, $needle)) {
                    return true;
                }
            }
        }
        foreach ($this->options->ignorePatterns as $pattern) {
            foreach ($candidates as $s) {
                if (preg_match($pattern, $s) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function runtimeContext(): array
    {
        return ['name' => 'php', 'version' => PHP_VERSION, 'sapi' => PHP_SAPI];
    }

    public static function osContext(): array
    {
        return ['name' => PHP_OS_FAMILY, 'version' => php_uname('r'), 'machine' => php_uname('m')];
    }

    /** At most 50 tags, keys cut to 32 and values to 200 characters. */
    private static function tags(array $all): array
    {
        $out = [];
        foreach ($all as $k => $v) {
            if (count($out) >= self::MAX_TAGS) {
                break;
            }
            if (!is_string($k) || $k === '' || preg_match(self::SENSITIVE, $k) === 1 || $v === null || is_array($v) || is_object($v)) {
                continue;
            }
            $out[self::cut($k, 32)] = self::cut(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, 200);
        }
        return $out;
    }

    // --- request ---------------------------------------------------------------------------------

    /**
     * Sentry's `request`: method, URL without its query, the query without sensitive values,
     * headers without credentials.
     *
     * @param array{method?: string, url?: string, headers?: array<string, string|list<string>>} $r
     */
    public static function request(array $r): array
    {
        $out = [];
        if (is_string($r['method'] ?? null) && $r['method'] !== '') {
            $out['method'] = strtoupper($r['method']);
        }
        if (is_string($r['url'] ?? null) && $r['url'] !== '') {
            $url = (string) preg_replace('/#.*$/s', '', $r['url']);
            $q = strpos($url, '?');
            $out['url'] = self::cut($q === false ? $url : substr($url, 0, $q), 2000);
            if ($q !== false) {
                $kept = [];
                foreach (explode('&', substr($url, $q + 1)) as $pair) {
                    if ($pair === '') {
                        continue;
                    }
                    $name = explode('=', $pair, 2)[0];
                    if (preg_match(self::SENSITIVE, rawurldecode($name)) !== 1) {
                        $kept[] = $pair;
                    }
                }
                if ($kept !== []) {
                    $out['query_string'] = self::cut(implode('&', $kept), 2000);
                }
            }
        }
        if (is_array($r['headers'] ?? null)) {
            $headers = [];
            foreach ($r['headers'] as $name => $value) {
                if (!is_string($name) || preg_match(self::CREDENTIAL_HEADER, $name) === 1 || preg_match(self::SENSITIVE, $name) === 1) {
                    continue;
                }
                $v = is_array($value) ? implode(', ', array_map('strval', array_filter($value, 'is_scalar'))) : (is_scalar($value) ? (string) $value : null);
                if ($v !== null) {
                    $headers[$name] = self::cut($v, 1000);
                }
            }
            if ($headers !== []) {
                $out['headers'] = $headers;
            }
        }
        return $out;
    }

    // --- trimming ---------------------------------------------------------------------------------

    /**
     * The detail cut to `$max` bytes of JSON, trimming in the contract's order — breadcrumbs (oldest
     * first), frame `vars`, threads other than the crashed one — then long exception values, fewer
     * frames per stack (50, 20, 5; the innermost kept), `extra`, custom contexts and the request.
     * Null when even that does not fit (the event is still sent, without detail).
     */
    public static function fit(array $d, int $max): ?array
    {
        $size = self::bytes($d);
        if ($size <= $max) {
            return $d;
        }
        if (isset($d['breadcrumbs']['values']) && is_array($d['breadcrumbs']['values'])) {
            $values = $d['breadcrumbs']['values'];
            $removed = 0;
            while ($values !== [] && $size - $removed > $max) {
                $removed += self::bytes(array_shift($values)) + 1;
            }
            if ($values === []) {
                unset($d['breadcrumbs']);
            } else {
                $d['breadcrumbs']['values'] = array_values($values);
            }
            if (($size = self::bytes($d)) <= $max) {
                return $d;
            }
        }
        $d = self::mapStacks($d, static function (array $st): array {
            foreach ($st['frames'] ?? [] as $i => $f) {
                if (is_array($f)) {
                    unset($st['frames'][$i]['vars']);
                }
            }
            return $st;
        });
        if (($size = self::bytes($d)) <= $max) {
            return $d;
        }
        if (isset($d['threads']['values']) && is_array($d['threads']['values'])) {
            $d['threads']['values'] = array_values(array_filter($d['threads']['values'], static fn ($t) => is_array($t) && ($t['crashed'] ?? false) === true));
            if (($size = self::bytes($d)) <= $max) {
                return $d;
            }
        }
        foreach ($d['exception']['values'] ?? [] as $i => $v) {
            if (is_array($v) && is_string($v['value'] ?? null)) {
                $d['exception']['values'][$i]['value'] = self::cut($v['value'], 1000);
            }
        }
        // Long previous-exception chains repeat deep stacks: keep fewer frames per stack, innermost last.
        foreach ([50, 20, 5] as $keep) {
            $d = self::mapStacks($d, static function (array $st) use ($keep): array {
                if (isset($st['frames']) && is_array($st['frames']) && count($st['frames']) > $keep) {
                    $st['frames'] = array_slice($st['frames'], -$keep);
                }
                return $st;
            });
            if (($size = self::bytes($d)) <= $max) {
                return $d;
            }
        }
        unset($d['extra'], $d['request']);
        if (isset($d['contexts']) && is_array($d['contexts'])) {
            $d['contexts'] = array_intersect_key($d['contexts'], ['runtime' => true, 'os' => true]);
        }
        return self::bytes($d) <= $max ? $d : null;
    }

    /** Applies $fn to every `stacktrace` (exceptions and threads). */
    private static function mapStacks(array $d, \Closure $fn): array
    {
        foreach (['exception', 'threads'] as $holder) {
            foreach ($d[$holder]['values'] ?? [] as $i => $v) {
                if (is_array($v) && is_array($v['stacktrace'] ?? null)) {
                    $d[$holder]['values'][$i]['stacktrace'] = $fn($v['stacktrace']);
                }
            }
        }
        return $d;
    }

    // --- helpers ----------------------------------------------------------------------------------

    /** Removes keys that look like credentials, at every depth. */
    public static function scrub(array $v): array
    {
        $out = [];
        foreach ($v as $k => $x) {
            if (is_string($k) && preg_match(self::SENSITIVE, $k) === 1) {
                continue;
            }
            $out[$k] = is_array($x) ? self::scrub($x) : $x;
        }
        return $out;
    }

    public static function bytes(mixed $v): int
    {
        try {
            return strlen(json_encode($v, Messages::JSON_FLAGS));
        } catch (\JsonException) {
            return PHP_INT_MAX;
        }
    }

    public static function cut(string $s, int $max): string
    {
        return strlen($s) <= $max ? $s : mb_strcut($s, 0, $max, 'UTF-8');
    }

    /** Epoch seconds with milliseconds, as Sentry timestamps are. */
    public static function epochSeconds(\DateTimeInterface $t): float
    {
        return round((float) $t->format('U.u'), 3);
    }
}
