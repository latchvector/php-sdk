<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use LatchVector\Sso\Exception\TokenVerificationException;
use LatchVector\Sso\TokenVerifier;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * Verification, exercised against a real RSA keypair.
 *
 * Only the JWKS fetch is bypassed — the key is injected directly. Everything
 * below that is firebase/php-jwt and the SDK's own claim handling, so these
 * tests exercise the code that ships rather than a stand-in for it.
 */
final class TokenVerifierTest extends TestCase
{
    private const ISSUER = 'https://sso.example.com';
    private const AUDIENCE = 'https://api.example.com';

    private string $privatePem;
    private string $publicPem;

    protected function setUp(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($resource);

        // Exported into a local first: PHP refuses to take a reference to an
        // uninitialized typed property.
        $privatePem = '';
        openssl_pkey_export($resource, $privatePem);

        $this->privatePem = $privatePem;
        $this->publicPem = openssl_pkey_get_details($resource)['key'];
    }

    private function verifier(int $leeway = 30): TokenVerifier
    {
        $verifier = new TokenVerifier(self::ISSUER, self::AUDIENCE, $leeway);

        // The one thing stubbed: which keys we trust. Everything else is real.
        // No setAccessible(): it has had no effect since PHP 8.1 and is
        // deprecated in 8.5, which turns a green suite into a noisy one.
        $property = (new ReflectionObject($verifier))->getProperty('keys');
        $property->setValue($verifier, ['test-key' => new Key($this->publicPem, 'RS256')]);

        return $verifier;
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides = [], bool $withExp = true): string
    {
        $now = time();
        $claims = [
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'person@customer.com',
            'iat' => $now - 5,
            'token_use' => 'access',
            'uid' => 4711,
            'org_id' => 57,
            'tenant_id' => 1,
            'org_path' => '/1/57/',
            'permissions' => ['invoice.approve'],
            'scope_self' => ['/1/57/'],
            'scope_subtree' => [],
        ];
        if ($withExp) {
            $claims['exp'] = $now + 900;
        }

        foreach ($overrides as $claim => $value) {
            if ($value === null) {
                unset($claims[$claim]);
            } else {
                $claims[$claim] = $value;
            }
        }

        return JWT::encode($claims, $this->privatePem, 'RS256', 'test-key');
    }

    public function testAcceptsAValidAccessToken(): void
    {
        $principal = $this->verifier()->verify($this->token());

        self::assertSame(4711, $principal->uid);
        self::assertSame(57, $principal->orgId);
        self::assertSame(1, $principal->tenantId);
        self::assertTrue($principal->has('invoice.approve'));
        self::assertFalse($principal->has('invoice.void'));
    }

    // --- the two findings from the cross-SDK conformance corpus -------------

    /**
     * The prefix trap. `/1/5/` and `/1/57/` are different companies, and a
     * naive character prefix test hands one the other's data.
     */
    public function testASubtreeGrantDoesNotReachASiblingWithALongerNumber(): void
    {
        $principal = $this->verifier()->verify($this->token([
            'org_path' => '/1/5/',
            'scope_self' => ['/1/5/'],
            'scope_subtree' => ['/1/5/'],
        ]));

        self::assertTrue($principal->canReach('/1/5/'));
        self::assertTrue($principal->canReach('/1/5/9/'));
        self::assertFalse($principal->canReach('/1/57/'));
        self::assertFalse($principal->canReach('/1/50/'));
    }

    /**
     * Defence in depth. The service always emits the trailing slash, so a grant
     * of `/1/5` should never arrive — but trusting one verbatim would be a
     * cross-organization read, so the grant is normalised before comparison.
     */
    public function testAGrantMissingItsTrailingSlashNarrowsRatherThanWidens(): void
    {
        $principal = $this->verifier()->verify($this->token([
            'org_path' => '/1/5/',
            'scope_self' => ['/1/5/'],
            'scope_subtree' => ['/1/5'],
        ]));

        self::assertTrue($principal->canReach('/1/5/9/'));
        self::assertFalse(
            $principal->canReach('/1/57/'),
            'a malformed grant must not widen the reach',
        );
    }

    /**
     * firebase/php-jwt validates `exp` when present but does not require it.
     * A signed bearer token with no expiry is a permanent credential.
     */
    public function testATokenWithNoExpiryIsRejected(): void
    {
        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verify($this->token(withExp: false));
    }

    public function testAMachineTokenWithNoExpiryIsRejected(): void
    {
        $token = $this->token([
            'token_use' => 'client',
            'sub' => 'client_abc',
            'scope' => ['reports.read'],
            'uid' => null,
            'permissions' => null,
        ], withExp: false);

        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verifyClient($token);
    }

    // --- the mandatory checks ----------------------------------------------

    public function testATokenForAnotherApplicationIsRejected(): void
    {
        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verify($this->token(['aud' => 'https://api.someone-else.com']));
    }

    public function testATokenFromAnotherIssuerIsRejected(): void
    {
        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verify($this->token(['iss' => 'https://sso.elsewhere.com']));
    }

    public function testAnMfaPendingTokenIsNotALogin(): void
    {
        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verify($this->token(['token_use' => 'mfa_pending']));
    }

    public function testAMachineTokenIsRejectedByTheUserVerifier(): void
    {
        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verify($this->token(['token_use' => 'client']));
    }

    public function testAUserTokenIsRejectedByTheMachineVerifier(): void
    {
        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verifyClient($this->token());
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verify($this->token(['exp' => time() - 3600, 'iat' => time() - 4500]));
    }

    public function testATokenExpiredWithinTheLeewayIsAccepted(): void
    {
        $principal = $this->verifier()->verify(
            $this->token(['exp' => time() - 10, 'iat' => time() - 910]),
        );

        self::assertSame(4711, $principal->uid);
    }

    public function testAForgedSignatureIsRejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $otherPem = '';
        openssl_pkey_export($other, $otherPem);

        $forged = JWT::encode(
            ['iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'exp' => time() + 900,
             'token_use' => 'access', 'uid' => 4711, 'org_id' => 57, 'tenant_id' => 1],
            $otherPem,
            'RS256',
            'test-key',
        );

        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verify($forged);
    }

    public function testAnEmptyTokenIsRejected(): void
    {
        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verify('');
    }
}
