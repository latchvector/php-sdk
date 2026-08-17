<?php

declare(strict_types=1);

namespace LatchVector\Sso\Symfony;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Makes a token's permission codes (or a machine token's scopes) usable directly
 * as authorization attributes, so `#[IsGranted('invoice.approve')]` works with the
 * codes your application already defined.
 *
 * <p>Why this is needed: Symfony's built-in {@see \Symfony\Component\Security\Core\Authorization\Voter\RoleVoter}
 * only votes on attributes that begin with {@code ROLE_}. A permission code like
 * {@code invoice.approve} is not role-prefixed, so RoleVoter abstains on it, and
 * with every voter abstaining the access decision defaults to *denied*. Without
 * this voter, `#[IsGranted('invoice.approve')]` would reject every caller — even
 * one who holds the permission. This voter closes that gap.
 *
 * <p>Scope is deliberately narrow: it only handles **subject-less** attribute
 * checks (`#[IsGranted('invoice.approve')]`), which is exactly the permission /
 * scope case. Anything with a subject (`#[IsGranted('EDIT', $post)]`) is left to
 * the application's own domain voters, and role checks stay with RoleVoter.
 *
 * <p>Implements {@see CacheableVoterInterface} directly (rather than extending the
 * abstract {@see \Symfony\Component\Security\Core\Authorization\Voter\Voter}) so a
 * single signature stays compatible across the Symfony versions the SDK supports.
 */
final class PermissionVoter implements CacheableVoterInterface
{
    /** Attribute namespaces owned by Symfony's own voters — never ours. */
    private const RESERVED_PREFIXES = ['ROLE_', 'IS_AUTHENTICATED', 'IS_IMPERSONATOR'];

    // The 4th parameter is left untyped on purpose: Symfony 7.3+ adds a typed
    // `?Vote $vote = null` here, earlier versions have no fourth parameter at all.
    // An untyped optional parameter is signature-compatible with both, so one
    // file supports the whole 6.4–8.x range without referencing the Vote class.
    public function vote(TokenInterface $token, mixed $subject, array $attributes, $vote = null): int
    {
        // A subject means a domain check (a specific entity) — leave it to the
        // application's own voters; we only answer permission/scope questions.
        if ($subject !== null) {
            return VoterInterface::ACCESS_ABSTAIN;
        }

        $granted = $this->grantsFor($token);
        if ($granted === null) {
            return VoterInterface::ACCESS_ABSTAIN;
        }

        $result = VoterInterface::ACCESS_ABSTAIN;
        foreach ($attributes as $attribute) {
            if (!\is_string($attribute) || !$this->supportsAttribute($attribute)) {
                continue;
            }
            $result = VoterInterface::ACCESS_DENIED;
            if (\in_array($attribute, $granted, true)) {
                return VoterInterface::ACCESS_GRANTED;
            }
        }

        return $result;
    }

    public function supportsAttribute(string $attribute): bool
    {
        if ($attribute === '' || $attribute === 'PUBLIC_ACCESS') {
            return false;
        }
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($attribute, $prefix)) {
                return false;
            }
        }

        return true;
    }

    public function supportsType(string $subjectType): bool
    {
        // Only subject-less checks concern us; Symfony reports that as 'null'.
        return $subjectType === 'null';
    }

    /**
     * The codes this token is authorized by: permissions for a user, scopes for a
     * machine caller. Null for any other principal, so the voter abstains.
     *
     * @return list<string>|null
     */
    private function grantsFor(TokenInterface $token): ?array
    {
        $user = $token->getUser();
        if ($user instanceof SsoUser) {
            return $user->principal->permissions;
        }
        if ($user instanceof SsoClientUser) {
            return $user->client->scopes;
        }

        return null;
    }
}
