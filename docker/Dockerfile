# Test image for the PHP SDK: the official CLI image plus ext-apcu (enabled on the CLI) and Composer.
#
#   docker build -t guidely-php-test:8.3 --build-arg PHP_VERSION=8.3 sdks/php/docker
#   docker run --rm -v "$PWD/sdks":/sdks -w /sdks/php guidely-php-test:8.3 composer test
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli
ARG APCU_VERSION=5.1.28

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip curl \
 && rm -rf /var/lib/apt/lists/* \
 # APCu from its pinned GitHub release rather than `pecl install`: pecl.php.net times out on CI often
 # enough to fail builds ("does not have REST info xml available").
 && mkdir -p /usr/src/php/ext/apcu \
 && curl -fsSL --retry 5 --retry-all-errors "https://github.com/krakjoe/apcu/archive/refs/tags/v${APCU_VERSION}.tar.gz" \
    | tar -xz -C /usr/src/php/ext/apcu --strip-components=1 \
 && docker-php-ext-install -j"$(nproc)" apcu \
 # APCu is off on the CLI by default. use_request_time=0 makes TTLs expire within one long-running process.
 && printf 'apc.enabled=1\napc.enable_cli=1\napc.use_request_time=0\n' > "$PHP_INI_DIR/conf.d/zz-apcu.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
