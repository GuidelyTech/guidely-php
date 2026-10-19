<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

use GuidelyTech\Flags\Exception\DecryptionException;

/**
 * Encrypted payloads (docs/feature-flags-spec.md §1.3): AES-256-GCM, 12-byte IV, 16-byte tag
 * appended to the ciphertext, `encryptedPayload` = `"<base64 iv>.<base64 ciphertext+tag>"`.
 */
final class PayloadDecryptor
{
    /**
     * Return the payload with its encrypted part decrypted and merged over the envelope.
     * Payloads without `encryptedPayload` are returned unchanged.
     *
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     * @throws DecryptionException
     */
    public static function decryptPayload(array $payload, ?string $decryptionKey): array
    {
        if (!array_key_exists('encryptedPayload', $payload)) {
            return $payload;
        }
        if (!is_string($payload['encryptedPayload'])) {
            throw new DecryptionException('Invalid encrypted flags payload');
        }
        if ($decryptionKey === null || $decryptionKey === '') {
            throw new DecryptionException('The flags payload is encrypted: pass the environment\'s "decryptionKey" (Dashboard → Feature flags → Environments)');
        }
        $plain = self::decrypt($payload['encryptedPayload'], $decryptionKey);
        try {
            $inner = Json::decode($plain);
        } catch (\JsonException $e) {
            throw new DecryptionException('Decrypted flags payload is not valid JSON', 0, $e);
        }
        if (!Json::isObject($inner)) {
            throw new DecryptionException('Decrypted flags payload is not a JSON object');
        }
        $envelope = $payload;
        unset($envelope['encryptedPayload']);
        return array_replace($envelope, Json::asMap($inner));
    }

    /** Decrypt `"<base64 iv>.<base64 ciphertext+tag>"` with a base64 32-byte key. */
    public static function decrypt(string $encrypted, string $base64Key): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new DecryptionException('Decrypting flag payloads needs ext-openssl');
        }
        $parts = explode('.', $encrypted);
        if (count($parts) !== 2) {
            throw new DecryptionException('Invalid encrypted flags payload: expected "<iv>.<ciphertext>"');
        }
        $iv = self::base64($parts[0]);
        $data = self::base64($parts[1]);
        $key = self::base64($base64Key);
        if ($key === null || strlen($key) !== 32) {
            throw new DecryptionException('Invalid decryptionKey: expected the base64 encoding of 32 bytes');
        }
        if ($iv === null || strlen($iv) !== 12 || $data === null || strlen($data) < 16) {
            throw new DecryptionException('Invalid encrypted flags payload');
        }
        $plain = openssl_decrypt(substr($data, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, substr($data, -16));
        if ($plain === false) {
            throw new DecryptionException('Could not decrypt the flags payload: wrong decryptionKey or corrupted data');
        }
        return $plain;
    }

    /** Standard or URL-safe base64, padding optional. */
    private static function base64(string $s): ?string
    {
        $s = strtr(trim($s), '-_', '+/');
        $decoded = base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }
}
