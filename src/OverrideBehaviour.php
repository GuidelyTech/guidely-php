<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

/** How local `overrides` combine with the remote payload (docs/feature-flags-spec.md §4). */
enum OverrideBehaviour: string
{
    /** Only overrides (then a bootstrap payload) are used; the SDK never fetches or reads a cached payload. */
    case LocalOnly = 'localOnly';
    /** An override wins over the remote flag (reason `OVERRIDE`). The default. */
    case LocalOverRemote = 'localOverRemote';
    /** An override is used only for flags missing from the remote payload. */
    case RemoteOverLocal = 'remoteOverLocal';
}
