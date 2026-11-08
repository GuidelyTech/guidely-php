<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * Error monitoring settings of a {@see GuidelyTrack} client (its `errors:` argument), named like
 * Sentry's options (docs/error-monitoring/sdks.md).
 *
 * ```php
 * $guidely = new GuidelyTrack(getenv('GUIDELY_SECRET_KEY'), environment: 'production', errors: new ErrorOptions(
 *     release: '2.4.1',
 *     sampleRate: 0.5,
 *     ignoreErrors: ['Connection reset by peer'],
 *     ignoreExceptions: [NotFoundHttpException::class],
 *     beforeSend: fn (array $event, ?\Throwable $e) => $e instanceof ValidationException ? null : $event,
 * ));
 * ```
 */
final class ErrorOptions
{
    /** Paths containing one of these are library code (`in_app: false`) unless `inAppInclude` names them. */
    public const DEFAULT_IN_APP_EXCLUDE = ['/vendor/'];
    public const MAX_BREADCRUMBS = 100;

    /** @var (\Closure(array, ?\Throwable): ?array)|null */
    public readonly ?\Closure $beforeSend;
    /** @var (\Closure(array): ?array)|null */
    public readonly ?\Closure $beforeBreadcrumb;
    public readonly float $sampleRate;
    /** @var list<string> */
    public readonly array $inAppExclude;
    public readonly ?string $serverName;
    public readonly int $maxBreadcrumbs;

    /**
     * @param callable(array, ?\Throwable): ?array|null $beforeSend sees every error before it is
     *     queued (`['properties' => …, 'detail' => …, 'userId' => …, 'anonymousId' => …, 'accountId' => …]`):
     *     return it (edited or not), or null to drop it. An exception it throws is swallowed and the
     *     event is sent unchanged.
     * @param callable(array): ?array|null $beforeBreadcrumb sees every breadcrumb; null drops it
     * @param float $sampleRate share of errors sent, 0–1 (uncaught errors are sampled too)
     * @param list<string> $ignoreErrors drop errors whose `"<Class>: <message>"` or message contains one of these
     * @param list<string> $ignorePatterns the same with regular expressions (`'/^Broken pipe/'`)
     * @param list<class-string> $ignoreExceptions drop instances of these classes (and their subclasses)
     * @param list<string> $inAppInclude path prefixes of your code; they win over the excludes
     * @param list<string> $inAppExclude path fragments of library code, added to `vendor/`
     * @param string|null $projectRoot stripped from frame paths (`filename`), so issues group across release directories
     * @param string|null $release your app's version or commit (default: the client's `appVersion`)
     * @param string|null $environment the deployment (`production`, `eu-1`); default: the client's environment key
     * @param string|null $serverName the host (default: `gethostname()`)
     * @param int $maxBreadcrumbs breadcrumbs kept, at most 100
     */
    public function __construct(
        ?callable $beforeSend = null,
        ?callable $beforeBreadcrumb = null,
        float $sampleRate = 1.0,
        public readonly array $ignoreErrors = [],
        public readonly array $ignorePatterns = [],
        public readonly array $ignoreExceptions = [],
        public readonly array $inAppInclude = [],
        array $inAppExclude = [],
        public readonly ?string $projectRoot = null,
        public readonly ?string $release = null,
        public readonly ?string $environment = null,
        ?string $serverName = null,
        int $maxBreadcrumbs = self::MAX_BREADCRUMBS,
    ) {
        foreach ($ignorePatterns as $p) {
            if (!is_string($p) || @preg_match($p, '') === false) {
                throw new \InvalidArgumentException('ignorePatterns must be valid regular expressions');
            }
        }
        $this->beforeSend = $beforeSend === null ? null : \Closure::fromCallable($beforeSend);
        $this->beforeBreadcrumb = $beforeBreadcrumb === null ? null : \Closure::fromCallable($beforeBreadcrumb);
        $this->sampleRate = is_nan($sampleRate) ? 1.0 : max(0.0, min(1.0, $sampleRate));
        $this->inAppExclude = array_values(array_merge(self::DEFAULT_IN_APP_EXCLUDE, $inAppExclude));
        $host = $serverName ?? (gethostname() ?: null);
        $this->serverName = $host === null || $host === '' ? null : $host;
        $this->maxBreadcrumbs = max(0, min(self::MAX_BREADCRUMBS, $maxBreadcrumbs));
    }
}
