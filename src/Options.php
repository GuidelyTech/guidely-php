<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

use GuidelyTech\Flags\Cache\CacheInterface;
use GuidelyTech\Flags\Cache\FileCache;
use GuidelyTech\Flags\Cache\InMemoryCache;
use GuidelyTech\Flags\Cache\Psr16Cache;
use GuidelyTech\Flags\Http\HttpClientInterface;
use GuidelyTech\Flags\Sticky\StickyBucketServiceInterface;

/**
 * Client configuration. Build it with named arguments (`new Options(sdkKey: 'pk_…')`) or pass an
 * array with the same keys to {@see GuidelyFlags::__construct()}. See the README for every option.
 */
final class Options
{
    public const DEFAULT_API_URL = 'https://guidelytech.com';
    public const ENVIRONMENTS = ['development', 'staging', 'production'];

    public readonly ?string $sdkKey;
    public readonly string $apiUrl;
    public readonly ?string $environment;
    public readonly int $cacheTtlSeconds;
    public readonly CacheInterface $cache;
    /** @var array<array-key, mixed>|string|null */
    public readonly array|string|null $payload;
    public readonly ?string $decryptionKey;
    /** @var array<string, mixed> */
    public readonly array $overrides;
    public readonly OverrideBehaviour $overrideBehaviour;
    /** @var array<string, mixed> */
    public readonly array $defaultAttributes;
    public readonly ?StickyBucketServiceInterface $stickyBucketService;
    /** @var (\Closure(array<string, mixed>, EvaluationResult): void)|null */
    public readonly ?\Closure $trackingCallback;
    /** @var (\Closure(EvaluationResult): void)|null */
    public readonly ?\Closure $onFlagEvaluated;
    /** @var (\Closure(\Throwable): void)|null */
    public readonly ?\Closure $onError;
    public readonly ?object $logger;
    public readonly bool $reportUsage;
    public readonly bool $reportExposures;
    public readonly float $timeoutSeconds;
    public readonly ?HttpClientInterface $httpClient;
    public readonly bool $offline;
    public readonly bool $remoteEval;
    /** @var (\Closure(): float)|null */
    public readonly ?\Closure $clock;

    /**
     * @param string|null $sdkKey environment public key (`pk_…`) or secret API key (`gly_sk_…`)
     * @param string|null $projectId alias of `sdkKey`
     * @param string|null $environment `development` / `staging` / `production`; required with a `gly_sk_` key
     * @param CacheInterface|object|false|null $cache payload cache: a CacheInterface, any PSR-16 cache,
     *        `false` for none (memory only), or null for the default {@see FileCache}
     * @param array<array-key, mixed>|string|null $payload bootstrap payload (array or JSON); when given the
     *        SDK serves it and does not fetch until `refresh()`
     * @param array<string, mixed> $overrides flag key => value
     * @param array<string, mixed> $defaultAttributes merged under every call's attributes
     * @param callable(array<string, mixed>, EvaluationResult): void|null $trackingCallback
     * @param callable(EvaluationResult): void|null $onFlagEvaluated
     * @param callable(\Throwable): void|null $onError
     * @param object|null $logger PSR-3 style logger (anything with `error(string, array)`)
     * @param callable(): float|null $clock current Unix time in seconds (tests)
     */
    public function __construct(
        ?string $sdkKey = null,
        ?string $projectId = null,
        string $apiUrl = self::DEFAULT_API_URL,
        ?string $environment = null,
        int $cacheTtlSeconds = 60,
        mixed $cache = null,
        array|string|null $payload = null,
        ?string $decryptionKey = null,
        array $overrides = [],
        OverrideBehaviour|string $overrideBehaviour = OverrideBehaviour::LocalOverRemote,
        array $defaultAttributes = [],
        ?StickyBucketServiceInterface $stickyBucketService = null,
        ?callable $trackingCallback = null,
        ?callable $onFlagEvaluated = null,
        ?callable $onError = null,
        ?object $logger = null,
        bool $reportUsage = true,
        bool $reportExposures = true,
        float $timeoutSeconds = 5.0,
        ?HttpClientInterface $httpClient = null,
        bool $offline = false,
        bool $remoteEval = false,
        ?callable $clock = null,
    ) {
        if ($sdkKey !== null && $projectId !== null && $sdkKey !== $projectId) {
            throw new \InvalidArgumentException('Pass either "sdkKey" or its alias "projectId", not both');
        }
        $key = $sdkKey ?? $projectId;
        $this->sdkKey = $key === null || trim($key) === '' ? null : trim($key);
        $this->apiUrl = rtrim($apiUrl, '/');
        if ($environment !== null && !in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException('"environment" must be one of ' . implode(', ', self::ENVIRONMENTS));
        }
        if ($this->sdkKey !== null && str_starts_with($this->sdkKey, 'gly_sk_') && $environment === null) {
            throw new \InvalidArgumentException('A secret API key (gly_sk_…) needs the "environment" option: development, staging or production');
        }
        $this->environment = $environment;
        if ($cacheTtlSeconds < 0) {
            throw new \InvalidArgumentException('"cacheTtlSeconds" must be >= 0');
        }
        $this->cacheTtlSeconds = $cacheTtlSeconds;
        $this->cache = match (true) {
            $cache === null => new FileCache(),
            $cache === false => new InMemoryCache(),
            $cache instanceof CacheInterface => $cache,
            is_object($cache) => new Psr16Cache($cache),
            default => throw new \InvalidArgumentException('"cache" must be a ' . CacheInterface::class . ', a PSR-16 cache, false or null'),
        };
        $this->payload = $payload;
        $this->decryptionKey = $decryptionKey;
        $this->overrides = $overrides;
        $this->overrideBehaviour = is_string($overrideBehaviour) ? self::behaviour($overrideBehaviour) : $overrideBehaviour;
        $this->defaultAttributes = $defaultAttributes;
        $this->stickyBucketService = $stickyBucketService;
        $this->trackingCallback = $trackingCallback === null ? null : \Closure::fromCallable($trackingCallback);
        $this->onFlagEvaluated = $onFlagEvaluated === null ? null : \Closure::fromCallable($onFlagEvaluated);
        $this->onError = $onError === null ? null : \Closure::fromCallable($onError);
        if ($logger !== null && !method_exists($logger, 'error')) {
            throw new \InvalidArgumentException('"logger" must have an error(string $message, array $context) method (PSR-3)');
        }
        $this->logger = $logger;
        $this->reportUsage = $reportUsage;
        $this->reportExposures = $reportExposures;
        $this->timeoutSeconds = $timeoutSeconds > 0 ? $timeoutSeconds : 5.0;
        $this->httpClient = $httpClient;
        $this->offline = $offline;
        $this->remoteEval = $remoteEval;
        $this->clock = $clock === null ? null : \Closure::fromCallable($clock);
    }

    /**
     * Options from an array with the constructor's parameter names.
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $known = array_map(static fn (\ReflectionParameter $p) => $p->getName(), (new \ReflectionMethod(self::class, '__construct'))->getParameters());
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown Guidely flags option(s): ' . implode(', ', $unknown));
        }
        return new self(...$options);
    }

    private static function behaviour(string $value): OverrideBehaviour
    {
        return OverrideBehaviour::tryFrom($value)
            ?? throw new \InvalidArgumentException('"overrideBehaviour" must be localOnly, localOverRemote or remoteOverLocal');
    }

    /** True for a `gly_sk_` secret key (Bearer transport). */
    public function usesSecretKey(): bool
    {
        return $this->sdkKey !== null && str_starts_with($this->sdkKey, 'gly_sk_');
    }
}
