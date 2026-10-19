<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Exception;

/** Base class of every error the SDK reports through `onError` / the logger. */
class FlagsException extends \RuntimeException
{
}
