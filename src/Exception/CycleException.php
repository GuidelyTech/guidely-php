<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Exception;

/** A flag / segment reference cycle (or nesting deeper than 32 levels) aborted an evaluation. */
final class CycleException extends FlagsException
{
}
