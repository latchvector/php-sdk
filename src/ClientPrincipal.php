<?php

declare(strict_types=1);

namespace LatchVector\Sso;

use DateTimeImmutable;

/**
 * A verified machine (client_credentials) token.
 *
 * There is no user behind it: the client is the subject, $orgId is the
 * customer it acts for, and $scopes is what it may do. See CONTRACT.md §2.
 */
final class ClientPrincipal
{
    /**
     * @param string               $clientId      The client id — the subject of a machine token.
     * @param int                  $orgId         The organization the machine acts for.
     * @param list<string>         $scopes        Granted scopes; authorization is by these, not permissions.
     * @param int|null             $applicationId The application this client is bound to, if any.
     * @param array<string, mixed> $claims        Raw verified claims.
     */
    public function __construct(
        public readonly string $clientId,
        public readonly int $orgId,
        public readonly int $tenantId,
        public readonly array $scopes,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?int $applicationId,
        public readonly array $claims,
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function hasAnyScope(string ...$scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->hasScope($scope)) {
                return true;
            }
        }

        return false;
    }
}
