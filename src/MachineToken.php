<?php

declare(strict_types=1);

namespace LatchVector\Sso;

/**
 * A machine (client_credentials) token.
 *
 * There is no refresh token — when it expires, ask for another. Caching is
 * the caller's job; $expiresInSeconds is how long it is good for.
 */
final class MachineToken
{
    /**
     * @param list<string> $scope The scopes actually granted, which may be narrower than requested.
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType,
        public readonly int $expiresInSeconds,
        public readonly array $scope,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $scope = $body['scope'] ?? '';

        return new self(
            accessToken: (string) ($body['access_token'] ?? ''),
            tokenType: (string) ($body['token_type'] ?? 'Bearer'),
            expiresInSeconds: (int) ($body['expires_in'] ?? 0),
            scope: is_string($scope) && $scope !== '' ? array_values(array_filter(explode(' ', $scope))) : [],
        );
    }
}
