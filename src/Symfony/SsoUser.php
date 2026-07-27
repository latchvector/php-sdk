<?php

declare(strict_types=1);

namespace LatchVector\Sso\Symfony;

use LatchVector\Sso\Principal;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The authenticated user, backed entirely by verified token claims.
 *
 * There is no database row behind this. Everything it reports was proven
 * by the signature, so a request costs no query to establish identity.
 */
final class SsoUser implements UserInterface
{
    public function __construct(public readonly Principal $principal)
    {
    }

    /**
     * Permission codes become roles verbatim, so `#[IsGranted('invoice.approve')]`
     * works with the codes your application already defined.
     *
     * ROLE_USER is added because parts of Symfony's security layer assume
     * every authenticated user has at least one ROLE_-prefixed role.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [...$this->principal->permissions, 'ROLE_USER'];
    }

    /**
     * The stable user id, as a string.
     *
     * Deliberately not the email: addresses change, and a GDPR erasure
     * scrubs the address while the id survives. Anything you persist
     * keyed on this identifier keeps working through both.
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->principal->uid;
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase: this user never held a credential. The token
        // was verified before construction and is not retained.
    }
}
