<?php

declare(strict_types=1);

namespace LatchVector\Sso\Symfony;

use LatchVector\Sso\ClientPrincipal;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The authenticated machine caller, backed entirely by verified token claims.
 *
 * The counterpart of {@see SsoUser} for client_credentials tokens. There is no
 * database row and no user: everything it reports was proven by the signature.
 */
final class SsoClientUser implements UserInterface
{
    public function __construct(public readonly ClientPrincipal $client)
    {
    }

    /**
     * Scopes become roles verbatim, so `#[IsGranted('reports.write')]` works
     * with the scopes the client was granted — the mirror of {@see SsoUser},
     * where permission codes become roles.
     *
     * ROLE_CLIENT is added (rather than ROLE_USER) so authorization rules can
     * tell a machine caller from a person, and because Symfony assumes every
     * authenticated user has at least one ROLE_-prefixed role.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [...$this->client->scopes, 'ROLE_CLIENT'];
    }

    /** The client id — the stable identity of a machine caller. */
    public function getUserIdentifier(): string
    {
        return $this->client->clientId;
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase: this caller never held a credential here. The
        // token was verified before construction and is not retained.
    }
}
