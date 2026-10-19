<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

/** Evaluation reasons (docs/feature-flags-spec.md §3.1). */
final class Reason
{
    public const FLAG_NOT_FOUND = 'FLAG_NOT_FOUND';
    public const DISABLED = 'DISABLED';
    public const PREREQUISITE_FAILED = 'PREREQUISITE_FAILED';
    public const HOLDOUT = 'HOLDOUT';
    public const RULE_MATCH = 'RULE_MATCH';
    public const EXPERIMENT = 'EXPERIMENT';
    public const DEFAULT = 'DEFAULT';
    public const OVERRIDE = 'OVERRIDE';
    public const ERROR = 'ERROR';

    private function __construct()
    {
    }
}
