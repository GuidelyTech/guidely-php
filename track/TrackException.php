<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * A problem reported to `onError` / the logger. Never thrown by tracking calls: they drop what they
 * cannot send and report it.
 */
final class TrackException extends \RuntimeException
{
    /** A call was invalid (missing userId, empty or over-long event, group without accountId…) and dropped. */
    public const INVALID_MESSAGE = 'invalid_message';
    /** A message serialized to more than 32 KB (an `exception` message: 80 KB) and was dropped. */
    public const MESSAGE_TOO_LARGE = 'message_too_large';
    /** The queue held `maxQueueSize` messages; the oldest were dropped. */
    public const QUEUE_FULL = 'queue_full';
    /** A batch was rejected (a 4xx other than 429) or failed after every retry. */
    public const DELIVERY_FAILED = 'delivery_failed';
    /** A call was made after close(). */
    public const CLOSED = 'closed';

    /**
     * @param string $errorCode one of the constants above
     * @param int $count how many messages were dropped
     * @param int|null $status the HTTP status for DELIVERY_FAILED (0 = no answer)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $count = 1,
        public readonly ?int $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
