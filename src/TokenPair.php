<?php

declare(strict_types=1);

namespace LatchVector\Sso;

final class TokenPair implements LoginResult
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly string $tokenType,
        public readonly int $expiresInSeconds,
        /** Set only for a device session — the stable id to store and resend. */
        public readonly ?string $deviceId = null,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            accessToken: (string) ($body['accessToken'] ?? ''),
            refreshToken: (string) ($body['refreshToken'] ?? ''),
            tokenType: (string) ($body['tokenType'] ?? 'Bearer'),
            expiresInSeconds: (int) ($body['expiresInSeconds'] ?? 0),
            deviceId: isset($body['deviceId']) ? (string) $body['deviceId'] : null,
        );
    }
}
