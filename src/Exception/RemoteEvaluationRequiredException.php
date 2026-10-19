<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Exception;

/**
 * `GET /flags` answered `403 remote_evaluation_required`: the environment does not serve its rules
 * to public keys (docs/feature-flags-spec.md §1.4).
 */
final class RemoteEvaluationRequiredException extends TransportException
{
    public function __construct()
    {
        parent::__construct(
            'This environment only allows remote evaluation for public (pk_) keys. On a server, use a secret '
            . 'API key ("gly_sk_…", scope flags:read) together with the "environment" option; otherwise set '
            . '"remoteEval" => true so flags are evaluated by Guidely.',
            403,
        );
    }
}
