<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

/**
 * A {@see GuidelyFlags} client bound to one user's attributes: the same getters without the
 * attributes argument. Create it with `$flags->forUser([...])`, e.g. once per request.
 */
final class UserContext
{
    /** @param array<array-key, mixed> $attributes */
    public function __construct(
        private readonly GuidelyFlags $client,
        private readonly array $attributes,
    ) {
    }

    /** @return array<array-key, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** A new context with more attributes merged over these. */
    public function with(array $attributes): self
    {
        return new self($this->client, array_replace($this->attributes, $attributes));
    }

    public function isEnabled(string $key, bool $fallback = false): bool
    {
        return $this->client->isEnabled($key, $this->attributes, $fallback);
    }

    public function getValue(string $key, mixed $fallback = null): mixed
    {
        return $this->client->getValue($key, $fallback, $this->attributes);
    }

    public function getString(string $key, ?string $fallback = null): ?string
    {
        return $this->client->getString($key, $fallback, $this->attributes);
    }

    public function getNumber(string $key, int|float|null $fallback = null): int|float|null
    {
        return $this->client->getNumber($key, $fallback, $this->attributes);
    }

    public function getBool(string $key, ?bool $fallback = null): ?bool
    {
        return $this->client->getBool($key, $fallback, $this->attributes);
    }

    public function getJson(string $key, array|object|null $fallback = null): array|object|null
    {
        return $this->client->getJson($key, $fallback, $this->attributes);
    }

    public function getDetails(string $key, mixed $fallback = null): EvaluationResult
    {
        return $this->client->getDetails($key, $fallback, $this->attributes);
    }

    /** @return array<string, EvaluationResult> */
    public function getAll(): array
    {
        return $this->client->getAll($this->attributes);
    }
}
