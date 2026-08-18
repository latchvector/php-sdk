<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Symfony;

use DateTimeImmutable;
use LatchVector\Sso\ClientPrincipal;
use LatchVector\Sso\Principal;
use LatchVector\Sso\Symfony\PermissionVoter;
use LatchVector\Sso\Symfony\SsoClientUser;
use LatchVector\Sso\Symfony\SsoUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class PermissionVoterTest extends TestCase
{
    private PermissionVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PermissionVoter();
    }

    private function userToken(array $permissions): PreAuthenticatedToken
    {
        $principal = new Principal(
            uid: 1,
            email: 'user@test.local',
            orgId: 1,
            tenantId: 1,
            orgPath: '/1/',
            permissions: $permissions,
            scopeSelf: [],
            scopeSubtree: [],
            expiresAt: new DateTimeImmutable('+1 hour'),
            claims: [],
        );

        return new PreAuthenticatedToken(new SsoUser($principal), 'main');
    }

    private function clientToken(array $scopes): PreAuthenticatedToken
    {
        $principal = new ClientPrincipal(
            clientId: 'client-1',
            orgId: 1,
            tenantId: 1,
            scopes: $scopes,
            expiresAt: new DateTimeImmutable('+1 hour'),
            applicationId: null,
            claims: [],
        );

        return new PreAuthenticatedToken(new SsoClientUser($principal), 'api');
    }

    public function testGrantsAHeldPermission(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->userToken(['invoice.approve']), null, ['invoice.approve']),
        );
    }

    public function testDeniesAPermissionNotHeld(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->userToken(['invoice.read']), null, ['invoice.approve']),
        );
    }

    public function testAbstainsOnRolePrefixedAttributes(): void
    {
        // ROLE_ belongs to Symfony's RoleVoter — we must not touch it.
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->userToken(['invoice.approve']), null, ['ROLE_ADMIN']),
        );
    }

    public function testAbstainsWhenASubjectIsPresent(): void
    {
        // #[IsGranted('EDIT', $post)] is a domain check — leave it to the app's voter.
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->userToken(['EDIT']), new \stdClass(), ['EDIT']),
        );
    }

    public function testGrantsAMachineTokenScope(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->clientToken(['reports.write']), null, ['reports.write']),
        );
    }

    public function testDeniesAScopeAMachineTokenLacks(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->clientToken(['reports.read']), null, ['reports.write']),
        );
    }
}
