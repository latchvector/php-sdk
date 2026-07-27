<?php

declare(strict_types=1);

namespace LatchVector\Sso;

use DateTimeImmutable;

/** A verified access token. Every field here is cryptographically proven. */
final class Principal
{
    /**
     * @param int                  $uid          Stable user id. Key your records on this.
     *                                           Not the email: addresses change, and a GDPR
     *                                           erasure scrubs the address while this id
     *                                           survives. Rows keyed on email lose the link
     *                                           to their own user the first time either happens.
     * @param string               $email        Login address. Display and logging only.
     * @param string               $orgPath      Materialised path, e.g. "/1/57/".
     * @param list<string>         $permissions  Codes your application defined, scoped to your audience.
     * @param list<string>         $scopeSelf    Nodes the user may act on directly, but not beneath.
     * @param list<string>         $scopeSubtree Nodes the user may act on together with everything below.
     * @param array<string, mixed> $claims       Raw verified claims, for anything not modelled here.
     */
    public function __construct(
        public readonly int $uid,
        public readonly string $email,
        public readonly int $orgId,
        public readonly int $tenantId,
        public readonly string $orgPath,
        public readonly array $permissions,
        public readonly array $scopeSelf,
        public readonly array $scopeSubtree,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $claims,
    ) {
    }

    public function has(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->has($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAll(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->has($permission)) {
                return false;
            }
        }

        return true;
    }

    /** Whether the user's granted scope reaches $orgPath. */
    public function canReach(string $orgPath): bool
    {
        if ($orgPath === '') {
            return false;
        }
        if (in_array($orgPath, $this->scopeSelf, true)) {
            return true;
        }
        // A subtree grant covers the node itself and everything beneath it,
        // which on a materialised path is exactly a prefix test.
        foreach ($this->scopeSubtree as $prefix) {
            if (str_starts_with($orgPath, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
