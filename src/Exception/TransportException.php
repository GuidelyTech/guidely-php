<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Exception;

/** The Guidely API could not be reached, or answered with an unexpected status. */
class TransportException extends FlagsException
{
    public function __construct(string $message, public readonly ?int $status = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
