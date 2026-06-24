<?php

declare(strict_types=1);

namespace App\Core;

final class SecureUrl
{
    /**
     * Generate a tamper-proof signed URL.
     */
    public static function generate(string $path, string $type, ?string $ownerId = null, int $ttl = 3600): string
    {
        $secret = Env::get('JWT_SECRET', 'change-me-now') ?? 'change-me-now';
        $expires = time() + $ttl;
        $owner = $ownerId ?? '';

        // Payload to hash
        $payload = $path . '|' . $expires . '|' . $type . '|' . $owner;
        $sig = hash_hmac('sha256', $payload, $secret);

        $baseUrl = rtrim(Env::get('APP_URL', 'https://bike.ggnproperty.com'), '/');

        return $baseUrl . '/v1/images?' . http_build_query([
            'path' => $path,
            'expires' => $expires,
            'type' => $type,
            'owner' => $owner,
            'sig' => $sig
        ]);
    }

    /**
     * Verify a signed URL. Returns the file path if valid, null otherwise.
     */
    public static function verify(Request $request): ?string
    {
        $path = $request->query('path');
        $expires = $request->query('expires');
        $type = $request->query('type');
        $owner = $request->query('owner') ?? '';
        $sig = $request->query('sig');

        if ($path === null || $expires === null || $type === null || $sig === null) {
            return null;
        }

        $expiresTime = (int) $expires;
        if (time() > $expiresTime) {
            return null; // Expired
        }

        $secret = Env::get('JWT_SECRET', 'change-me-now') ?? 'change-me-now';
        $payload = $path . '|' . $expires . '|' . $type . '|' . $owner;
        $expectedSig = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSig, $sig)) {
            return null; // Tampered URL / signature mismatch
        }

        return $path;
    }
}
