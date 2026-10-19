# Guidely feature flags for PHP

`guidelytech/guidely-php` evaluates [Guidely](https://guidelytech.com) feature flags and experiments
inside your PHP application. Rules are evaluated locally, so a flag check costs no network round
trip. The payload is cached between requests and revalidated in the background of the next read.

* Feature flags, percentage rollouts, scheduled ramps, percentage options and A/B experiments
* Targeting by attributes, segments, prerequisites, flag conditions and holdouts
* Sticky bucketing, namespaces and bucket versions for experiments
* File, APCu, in-memory and any PSR-16 (Redis, Memcached, Laravel, Symfony) payload cache
* Encrypted payloads, remote evaluation, local overrides for tests
* Usage counters and experiment exposures reported to Guidely

Requirements: PHP 8.1 or later (64-bit) with `ext-json`, `ext-mbstring` and `ext-hash`.
`ext-openssl` is needed only for encrypted payloads, and `ext-curl` is used when it is installed
(otherwise PHP streams).

## Install

```bash
composer require guidelytech/guidely-php
```

## Quick start

Every environment has a **public key** (`pk_…`), the same key the Guidely browser SDK uses for
tracking. It is all most servers need:

```php
use GuidelyTech\Flags\GuidelyFlags;

$flags = new GuidelyFlags([
    'sdkKey' => 'pk_production_…',
]);

$user = ['id' => $user->id, 'email' => $user->email, 'plan' => $account->plan];

if ($flags->isEnabled('new-checkout', $user)) {
    // …
}

$limit = $flags->getNumber('upload-limit-mb', 10, $user);
$theme = $flags->getString('checkout-theme', 'classic', $user);
```

Getters never throw. When a flag is missing, disabled for everyone without a value, of the wrong
type, or the payload cannot be loaded, you get your fallback and the problem is reported through
`onError` (or your logger, or `error_log` when neither is configured).

### With a server key

A **secret API key** (`gly_sk_…`, scope `flags:read`) sees the full payload, including server-only
flags and environments that hide their rules from public keys. Secret keys are tied to a project, so
name the environment:

```php
$flags = new GuidelyFlags([
    'sdkKey' => getenv('GUIDELY_SECRET_KEY'),   // gly_sk_…
    'environment' => 'production',              // development | staging | production
]);
```

Never ship a secret key to a browser or a mobile app.

## Reading flags

| Method | Returns |
|---|---|
| `isEnabled($key, $attributes = [], $fallback = false)` | `bool`: the flag's boolean value, else `$fallback` |
| `getValue($key, $fallback = null, $attributes = [])` | the raw value, `$fallback` when it is null |
| `getString` / `getNumber` / `getBool` / `getJson($key, $fallback, $attributes)` | the value when it has that JSON type, else `$fallback` |
| `getDetails($key, $fallback = null, $attributes = [])` | `EvaluationResult` (`value`, `reason`, `ruleId`, `variationId`, `inExperiment`, `experiment`, `errorMessage`) |
| `getAll($attributes = [])` | `array<string, EvaluationResult>` for every flag (no usage counting or exposures) |
| `forUser($attributes)` | a `UserContext` with the same getters, minus the attributes argument |

```php
$flags = $flags->forUser(['id' => 'u_123', 'country' => 'GB', 'account' => ['plan' => 'pro']]);

$flags->isEnabled('new-checkout');
$details = $flags->getDetails('new-checkout');
echo $details->reason;       // RULE_MATCH, DEFAULT, EXPERIMENT, DISABLED, OVERRIDE, ERROR, …
```

**Attributes** are a JSON-like array. Nested values are addressed with dot paths in the dashboard
(`account.plan`). Dates can be `DateTimeInterface` objects, ISO 8601 strings or Unix seconds.
JSON objects come back from `getJson()` as associative arrays; because PHP cannot tell `[]` from
`{}`, an empty JSON object is returned as `stdClass` (pass `new stdClass()` as an attribute when you
mean an empty object).

## Frameworks

### Laravel

```php
// app/Providers/AppServiceProvider.php
use GuidelyTech\Flags\GuidelyFlags;
use Illuminate\Support\Facades\Cache;

public function register(): void
{
    $this->app->singleton(GuidelyFlags::class, fn () => new GuidelyFlags([
        'sdkKey' => config('services.guidely.key'),          // pk_… or gly_sk_…
        'environment' => config('services.guidely.environment'),
        'cache' => Cache::store('redis'),                     // any PSR-16 store
        'logger' => logger(),
        'defaultAttributes' => ['app' => 'web'],
    ]));
}
```

```php
// anywhere
public function show(Request $request, GuidelyFlags $flags)
{
    $features = $flags->forUser(['id' => (string) $request->user()->id, 'plan' => $request->user()->plan]);
    return view('checkout', ['newCheckout' => $features->isEnabled('new-checkout')]);
}
```

### Symfony

```yaml
# config/services.yaml
services:
    GuidelyTech\Flags\GuidelyFlags:
        arguments:
            $options:
                sdkKey: '%env(GUIDELY_SDK_KEY)%'
                environment: '%env(GUIDELY_ENVIRONMENT)%'
                cache: '@guidely.flags_cache'
                logger: '@logger'

    guidely.flags_cache:
        class: Symfony\Component\Cache\Psr16Cache
        arguments: ['@cache.app']
```

Inject `GuidelyFlags` into controllers and services as usual.

## Caching and freshness

PHP processes are short-lived, so the client loads lazily:

1. On the first flag read it takes the payload from the cache.
2. When the cached copy is older than `cacheTtlSeconds` (default 60), it revalidates it with
   `If-None-Match`; a `304` just refreshes the timestamp, so polling costs almost nothing.
3. If the API cannot be reached, the stale payload keeps serving and every worker retries after
   another `cacheTtlSeconds`.
4. `refresh()` forces a fetch; a long-running worker (Swoole, RoadRunner, queue consumers)
   revalidates by itself once its copy is older than the TTL.

Pick the cache that matches your hosting:

| Cache | When |
|---|---|
| `FileCache` (default) | Zero setup. JSON files in `sys_get_temp_dir()/guidely-flags-<uid>`, written atomically. |
| `ApcuCache` | One host with PHP-FPM: shared memory, the fastest option. Needs `ext-apcu`. |
| any PSR-16 cache | Several hosts or containers: Redis, Memcached, Laravel's `Cache::store()`, Symfony's `Psr16Cache`. Passed objects are detected by their `get` / `set` methods; `psr/simple-cache` is not required. |
| `InMemoryCache` / `'cache' => false` | Tests, or when every process may fetch its own copy. |

```php
use GuidelyTech\Flags\Cache\ApcuCache;

$flags = new GuidelyFlags(['sdkKey' => 'pk_…', 'cache' => new ApcuCache()]);
```

Your own storage only needs `GuidelyTech\Flags\Cache\CacheInterface` (`get($key)` and
`set($key, $value, $ttlSeconds)`).

## Options

| Option | Default | Meaning |
|---|---|---|
| `sdkKey` (alias `projectId`) | — | `pk_…` public key or `gly_sk_…` secret key. Without one the SDK only serves `payload` / `overrides`. |
| `environment` | — | `development`, `staging` or `production`. Required with a `gly_sk_` key. |
| `apiUrl` | `https://guidelytech.com` | API origin (self-hosted / testing). |
| `cacheTtlSeconds` | `60` | Age after which the cached payload is revalidated. |
| `cache` | `FileCache` | A `CacheInterface`, any PSR-16 cache, or `false` for memory only. |
| `payload` | — | Bootstrap payload (array or JSON string). It is served as-is and nothing is fetched until `refresh()`. |
| `decryptionKey` | — | The environment's decryption key, for encrypted payloads. |
| `overrides` | `[]` | Flag key ⇒ value. |
| `overrideBehaviour` | `localOverRemote` | `localOnly` (never fetch), `localOverRemote` (override wins), `remoteOverLocal` (override only for flags missing remotely). String or `OverrideBehaviour` enum. |
| `defaultAttributes` | `[]` | Attributes merged under every call's attributes. |
| `stickyBucketService` | — | A `StickyBucketServiceInterface` to keep users in their experiment variation. |
| `trackingCallback` | — | `fn (array $experiment, EvaluationResult $result)`, called once per experiment, user and variation. |
| `onFlagEvaluated` | — | `fn (EvaluationResult $result)` after every getter call. |
| `onError` | — | `fn (Throwable $error)` for network, cache, decryption and callback errors. |
| `logger` | — | A PSR-3 logger (anything with `error($message, $context)`). |
| `reportUsage` | `true` | Send per-flag evaluation counts to Guidely. |
| `reportExposures` | `true` | Send experiment exposures to Guidely. |
| `timeoutSeconds` | `5` | HTTP timeout. |
| `httpClient` | cURL / streams | A `GuidelyTech\Flags\Http\HttpClientInterface` (e.g. to wrap Guzzle, or a fake in tests). |
| `offline` | `false` | No network at all; the cached payload keeps serving. Also `setOffline()`. |
| `remoteEval` | `false` | Let Guidely evaluate (see below). |
| `clock` | `microtime(true)` | `fn (): float` current Unix time, for tests. |

Options can also be built as an object: `new GuidelyFlags(new Options(sdkKey: 'pk_…', cacheTtlSeconds: 30))`.
Unknown option names throw an `InvalidArgumentException` so typos surface immediately.

## Experiments

An experiment rule assigns each user a variation deterministically from their hash attribute (`id`
by default). Read the flag as usual; the result says whether the user is in the experiment:

```php
$flags = new GuidelyFlags([
    'sdkKey' => 'pk_…',
    'trackingCallback' => function (array $experiment, EvaluationResult $result) use ($analytics): void {
        // $experiment: key, name?, variationId, variationName?, variationIndex, hashAttribute, hashValue, bucket, stickyBucketUsed
        $analytics->track('$experiment_viewed', ['experiment' => $experiment['key'], 'variation' => $experiment['variationId']]);
    },
]);

$variant = $flags->getString('checkout-button', 'control', ['id' => $userId]);
```

Exposures are also sent to Guidely (`reportExposures`), so the dashboard shows results without
extra work. Read experiment flags with the getters rather than `getAll()`, which records nothing.

**Sticky bucketing** keeps users in their variation when you change weights or coverage. Implement
`StickyBucketServiceInterface` on your database or Redis (documents keyed `"id||u_123"`, mapping
`"<experiment>__<bucketVersion>"` to a variation id), or use `InMemoryStickyBucketService` in
long-running workers:

```php
use GuidelyTech\Flags\Sticky\StickyBucketServiceInterface;

final class RedisStickyBuckets implements StickyBucketServiceInterface
{
    public function __construct(private \Redis $redis) {}

    public function getAssignments(string $docKey): ?array
    {
        $raw = $this->redis->get("sticky:$docKey");
        return $raw === false ? null : json_decode($raw, true);
    }

    public function saveAssignments(string $docKey, array $assignments): void
    {
        $this->redis->set("sticky:$docKey", json_encode($assignments));
    }
}
```

## Overrides in tests

```php
$flags = new GuidelyFlags([
    'overrides' => ['new-checkout' => true, 'upload-limit-mb' => 50],
    'overrideBehaviour' => 'localOnly',   // no network, no usage reports
]);

$flags->setOverrides(['new-checkout' => false]);   // change them mid-test
```

Overridden flags report reason `OVERRIDE`. Overrides apply to the flag you read; prerequisites and
flag conditions of other flags still use the payload's values. To test against real rules without
the network, pass a payload exported from the dashboard as `payload`.

## Remote evaluation

When an environment's exposure mode is **remote**, public keys cannot download its rules
(`GET /flags` answers `403 remote_evaluation_required`, reported as a
`RemoteEvaluationRequiredException`). On a server, prefer a `gly_sk_` key and keep evaluating
locally. Otherwise let Guidely evaluate:

```php
$flags = new GuidelyFlags(['sdkKey' => 'pk_…', 'remoteEval' => true]);
$flags->isEnabled('new-checkout', ['id' => 'u_123']);   // POST /api/v1/sdk/flags/evaluate
```

Results are fetched once per distinct attribute set and reused for the rest of the client's life
(call `refresh()` to drop them).

## Encrypted payloads

With encryption on, the payload is AES-256-GCM encrypted. Pass the environment's decryption key
from the dashboard:

```php
$flags = new GuidelyFlags([
    'sdkKey' => 'pk_…',
    'decryptionKey' => getenv('GUIDELY_DECRYPTION_KEY'),
]);
```

The cache stores the payload still encrypted. A missing or wrong key is reported through `onError`
and the previous payload keeps serving. Requires `ext-openssl`.

## Usage reporting

Unless `reportUsage` is off, the SDK counts evaluations per flag and served variation / value, and
sends them with any experiment exposures to `POST /api/v1/sdk/flags/events`: at the end of the
request (shutdown function and destructor), after 60 seconds of activity in long-running
processes, or when you call `flush()`.

## Evaluating without the client

`GuidelyTech\Flags\Evaluator` is the pure evaluation engine shared by every Guidely SDK, if you
manage the payload yourself:

```php
use GuidelyTech\Flags\Evaluator;
use GuidelyTech\Flags\Json;

$payload = Json::decode($json);
$result = Evaluator::evaluate($payload, 'new-checkout', ['id' => 'u_123'], now: new DateTimeImmutable());
```

## Development

The evaluator follows `docs/feature-flags-spec.md` and passes the shared conformance vectors in
`sdks/conformance/flag-vectors.json`.

```bash
cd sdks/php
composer install
composer test
```

Without a local PHP, run the suite in Docker from the repository root:

```bash
docker run --rm -v "$PWD/sdks":/sdks -w /sdks/php composer:2 composer install
docker run --rm -v "$PWD/sdks":/sdks -w /sdks/php php:8.1-cli vendor/bin/phpunit
```

## License

MIT
