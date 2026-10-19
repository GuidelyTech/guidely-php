<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

/**
 * Outcome of evaluating one flag (docs/feature-flags-spec.md §3.1).
 *
 * `experiment`, when the user is in an experiment, has the keys `key`, `name` (optional),
 * `variationId`, `variationName` (optional), `variationIndex`, `hashAttribute`, `hashValue`,
 * `bucket` and `stickyBucketUsed`.
 */
final class EvaluationResult
{
    /**
     * @param mixed $value the served JSON value (see {@see Json} for how objects are represented)
     * @param array<string, mixed>|null $experiment
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly string $reason,
        public readonly ?string $ruleId = null,
        public readonly ?string $variationId = null,
        public readonly bool $inExperiment = false,
        public readonly ?array $experiment = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /** Build a result from its wire form (remote evaluation responses). */
    public static function fromArray(array $data, string $key = ''): self
    {
        $experiment = Json::isObject($data['experiment'] ?? null) ? Json::asMap($data['experiment']) : null;
        return new self(
            key: is_string($data['key'] ?? null) ? $data['key'] : $key,
            value: $data['value'] ?? null,
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : Reason::ERROR,
            ruleId: Values::textOf($data['ruleId'] ?? null),
            variationId: Values::textOf($data['variationId'] ?? null),
            inExperiment: ($data['inExperiment'] ?? false) === true,
            experiment: $experiment,
            errorMessage: is_string($data['errorMessage'] ?? null) ? $data['errorMessage'] : null,
        );
    }

    /**
     * Wire form; optional fields are left out when absent, as in the TypeScript SDK.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['key' => $this->key, 'value' => $this->value, 'reason' => $this->reason];
        if ($this->ruleId !== null) {
            $out['ruleId'] = $this->ruleId;
        }
        if ($this->variationId !== null) {
            $out['variationId'] = $this->variationId;
        }
        $out['inExperiment'] = $this->inExperiment;
        if ($this->experiment !== null) {
            $out['experiment'] = $this->experiment;
        }
        if ($this->errorMessage !== null) {
            $out['errorMessage'] = $this->errorMessage;
        }
        return $out;
    }

    /** A result for a locally overridden flag (reason `OVERRIDE`). */
    public static function override(string $key, mixed $value): self
    {
        return new self($key, $value, Reason::OVERRIDE);
    }

    public static function error(string $key, string $message): self
    {
        return new self($key, null, Reason::ERROR, errorMessage: $message);
    }
}
