<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Symfony;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LatchVector\Sso\Symfony\SsoAuthenticator;
use LatchVector\Sso\Tenancy\TenantContext;
use LatchVector\Sso\TokenVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves the authenticator publishes the tenant + reach into the shared context
 * from a genuinely verified token (real RS256 signature, JWKS served from a
 * mocked HTTP client) — the link the Doctrine/Laravel scopes depend on.
 */
final class SsoAuthenticatorTest extends TestCase
{
    private string $privateKey = '';
    private array $jwk = [];

    protected function setUp(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $this->privateKey);
        $details = openssl_pkey_get_details($res);
        $this->jwk = [
            'kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256',
            'n' => $this->b64u($details['rsa']['n']),
            'e' => $this->b64u($details['rsa']['e']),
        ];
    }

    private function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function verifier(): TokenVerifier
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['jwks_uri' => 'https://issuer.test/jwks'])),
            new Response(200, [], (string) json_encode(['keys' => [$this->jwk]])),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);

        return new TokenVerifier('https://issuer.test', 'https://issuer.test', 30, 600, $http, null);
    }

    /** @param array<string,mixed> $overrides */
    private function bearer(array $overrides = []): string
    {
        $claims = array_merge([
            'iss' => 'https://issuer.test', 'aud' => 'https://issuer.test', 'token_use' => 'access',
            'uid' => 1, 'sub' => 'u@test.local', 'org_id' => 57, 'tenant_id' => 10, 'org_path' => '/10/57/',
            'permissions' => ['invoice.read'], 'scope_self' => [], 'scope_subtree' => ['/10/57/'],
            'exp' => time() + 3600, 'iat' => time(),
        ], $overrides);

        return 'Bearer ' . JWT::encode($claims, $this->privateKey, 'RS256', 'test-key');
    }

    private function request(string $authorization): Request
    {
        $request = Request::create('/api/x');
        $request->headers->set('Authorization', $authorization);

        return $request;
    }

    public function testPublishesTenantAndReachFromTheToken(): void
    {
        $context = new TenantContext();
        $authenticator = new SsoAuthenticator($this->verifier(), $context, 'platform.admin');

        $authenticator->authenticate($this->request($this->bearer()));

        self::assertSame(10, $context->tenantId());
        self::assertSame(57, $context->ownOrgId());
        self::assertSame('/10/57/', $context->ownOrgPath());
        self::assertSame(['/10/57/'], $context->subtreePaths());
        self::assertTrue($context->isActive(), 'no bypass permission held → scoping active');
    }

    public function testBypassPermissionLeavesTheContextInactive(): void
    {
        $context = new TenantContext();
        $authenticator = new SsoAuthenticator($this->verifier(), $context, 'platform.admin');

        $authenticator->authenticate($this->request($this->bearer(['permissions' => ['platform.admin']])));

        self::assertFalse($context->isActive(), 'bypass permission → unconstrained');
    }
}
