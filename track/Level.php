<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/** Severity of a captured error or message (Sentry's levels). */
enum Level: string
{
    case Fatal = 'fatal';
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
    case Debug = 'debug';

    /** A Level from its wire name (`'warning'`); null for anything else. */
    public static function of(Level|string|null $level): ?self
    {
        if ($level instanceof self || $level === null) {
            return $level;
        }
        return self::tryFrom(strtolower(trim($level)));
    }
}
