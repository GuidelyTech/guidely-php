<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * Validation and serialization of identify / track / group messages (docs/server-sdks.md,
 * sdks/conformance/track-vectors.json → messages).
 *
 * @internal
 */
final class Messages
{
    public const MAX_BATCH_MESSAGES = 100;
    public const MAX_BATCH_BYTES = 500000;
    public const MAX_MESSAGE_BYTES = 32768;
    public const MAX_EVENT_LENGTH = 120;
    public const MAX_ID_LENGTH = 256;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /** 24 random characters from [0-9A-Za-z]. */
    public static function newMessageId(): string
    {
        $id = '';
        for ($i = 0; $i < 24; $i++) {
            $id .= self::ALPHABET[random_int(0, 61)];
        }
        return $id;
    }

    /** ISO 8601 UTC with milliseconds, e.g. `2026-10-26T10:00:00.000Z`. */
    public static function formatTimestamp(\DateTimeInterface $time): string
    {
        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * A caller-supplied time: a DateTimeInterface, an ISO 8601 string with an offset, or a Unix
     * timestamp in seconds (int or float).
     */
    public static function toDateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value) || is_float($value)) {
            $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', (float) $value));
            if ($dt !== false) {
                return $dt;
            }
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/i', trim($value)) === 1) {
            try {
                return new \DateTimeImmutable(trim($value));
            } catch (\Exception) {
                // fall through
            }
        }
        throw new \InvalidArgumentException('timestamp must be a DateTimeInterface, a Unix timestamp or an ISO 8601 string with an offset');
    }

    /**
     * Validates a call and returns the message as JSON text.
     *
     * @throws \InvalidArgumentException when the call is invalid and must be dropped
     */
    public static function build(string $type, mixed $args, \DateTimeImmutable $now): string
    {
        if (!is_array($args)) {
            throw new \InvalidArgumentException("$type() needs an array argument");
        }
        $ts = $args['timestamp'] ?? null;
        $m = [
            'type' => $type,
            'messageId' => self::newMessageId(),
            'timestamp' => self::formatTimestamp($ts === null ? $now : self::toDateTime($ts)),
        ];
        switch ($type) {
            case 'identify':
                $userId = self::id('userId', $args['userId'] ?? null, true);
                $traits = self::object('traits', $args['traits'] ?? null);
                $accountId = self::id('accountId', $args['accountId'] ?? null, false);
                $m['userId'] = $userId;
                if ($traits !== null) {
                    $m['traits'] = $traits;
                }
                if ($accountId !== null) {
                    $m['accountId'] = $accountId;
                }
                break;
            case 'track':
                $userId = self::id('userId', $args['userId'] ?? null, false);
                $anonymousId = self::id('anonymousId', $args['anonymousId'] ?? null, false);
                if ($userId === null && $anonymousId === null) {
                    throw new \InvalidArgumentException('track() needs a userId or an anonymousId');
                }
                $event = $args['event'] ?? null;
                if (!is_string($event) || trim($event) === '') {
                    throw new \InvalidArgumentException('track() needs an event name');
                }
                if (self::longerThan($event, self::MAX_EVENT_LENGTH)) {
                    throw new \InvalidArgumentException('event is longer than ' . self::MAX_EVENT_LENGTH . ' characters');
                }
                $properties = self::object('properties', $args['properties'] ?? null);
                $accountId = self::id('accountId', $args['accountId'] ?? null, false);
                if ($userId !== null) {
                    $m['userId'] = $userId;
                }
                if ($anonymousId !== null) {
                    $m['anonymousId'] = $anonymousId;
                }
                $m['event'] = $event;
                if ($properties !== null) {
                    $m['properties'] = $properties;
                }
                if ($accountId !== null) {
                    $m['accountId'] = $accountId;
                }
                break;
            case 'group':
                $accountId = self::id('accountId', $args['accountId'] ?? null, true);
                $traits = self::object('traits', $args['traits'] ?? null);
                $userId = self::id('userId', $args['userId'] ?? null, false);
                $m['accountId'] = $accountId;
                if ($traits !== null) {
                    $m['traits'] = $traits;
                }
                if ($userId !== null) {
                    $m['userId'] = $userId;
                }
                break;
            default:
                throw new \InvalidArgumentException("Unknown message type $type");
        }
        try {
            return json_encode($m, self::JSON_FLAGS);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('could not be serialized to JSON (' . $e->getMessage() . ')', 0, $e);
        }
    }

    /** Length in UTF-16 code units, like JavaScript's `length` (and the server's validation). */
    public static function length(string $s): int
    {
        return intdiv(strlen((string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** UTF-16 never needs more code units than UTF-8 needs bytes, so short strings skip the conversion. */
    private static function longerThan(string $s, int $max): bool
    {
        return strlen($s) > $max && self::length($s) > $max;
    }

    /**
     * An id. Required ids must be present; optional ones treat null and '' as absent. Integers
     * become strings; anything else that is not a non-blank string of at most 256 UTF-16 code units
     * is invalid.
     */
    private static function id(string $name, mixed $value, bool $required): ?string
    {
        if ($value === null || (!$required && $value === '')) {
            if ($required) {
                throw new \InvalidArgumentException("$name is required");
            }
            return null;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("$name must be a string");
        }
        if (trim($value) === '') {
            throw new \InvalidArgumentException("$name must not be empty");
        }
        if (self::longerThan($value, self::MAX_ID_LENGTH)) {
            throw new \InvalidArgumentException("$name is longer than " . self::MAX_ID_LENGTH . ' characters');
        }
        return $value;
    }

    /**
     * Traits / properties: absent, or an associative array (or stdClass). An empty array is an
     * empty object; a non-empty list is invalid.
     *
     * @return array<array-key, mixed>|\stdClass|null
     */
    private static function object(string $name, mixed $value): array|\stdClass|null
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException("$name must be an associative array");
        }
        if ($value === []) {
            return new \stdClass();
        }
        if (array_is_list($value)) {
            throw new \InvalidArgumentException("$name must be an associative array, not a list");
        }
        return $value;
    }
}
