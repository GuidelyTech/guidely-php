<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Exception;

/** An encrypted payload could not be decrypted (missing or wrong `decryptionKey`, or no ext-openssl). */
final class DecryptionException extends FlagsException
{
}
